<?php

// app/Http/Controllers/RosterImportController.php

namespace App\Http\Controllers;

use App\Domain\Import\EnrollmentSituation;
use App\Domain\Import\PhotoMatch;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\SchoolClass;
use App\Services\Import\MatchRosterToEnrollments;
use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use App\Services\Import\RosterImportPreviewBuilder;
use App\Services\StudentEnrollmentService;
use App\Services\StudentPhotoService;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use App\Support\Import\RosterImportTempStorage;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Throwable;

class RosterImportController extends Controller
{
    public function __construct(
        protected RosterFileParser $rosterParser,
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected RosterImportTempStorage $tempStorage,
        protected StudentEnrollmentService $enrollmentService,
        protected StudentPhotoService $photoService,
        protected MatchRosterToEnrollments $matcher,
        protected CurrentOrganization $currentOrganization,
        protected HelpCenter $helpCenter,
    ) {}

    /**
     * "Precisa de ajuda?" (A2, Onboarding & Help) — one of the 3 pages the
     * brief names explicitly. Shared by store() and attachPhotos(), which
     * both re-render the SAME roster-imports/Preview page; passing it from
     * only one of them would make the block disappear after the photo step.
     *
     * @return list<array<string, mixed>>
     */
    protected function helpArticles(): array
    {
        return array_values($this->helpCenter->forContext('classes.roster-imports.store')
            ->map(fn (HelpArticle $article): array => $article->toArray())
            ->all());
    }

    /**
     * The per-row validation rules, shared by attachPhotos() and confirm() so
     * the two can never drift into disagreeing about what a row is.
     *
     * NAME AND «SIT.» ARE BOTH NULLABLE, and both used to be `required`.
     *
     * The situation code was the older mistake: a Relação de Turma whose SIT.
     * column is blank sends an empty string, ConvertEmptyStringsToNull turns
     * it into null, and `required` then rejected the whole import — for a
     * file the preview had already explained ("sem situação no ficheiro — o
     * estado da matrícula fica como está"). A rule and a screen were saying
     * opposite things about the same file.
     *
     * The name became nullable when the photo-correction flow arrived: it
     * previews students already on the roll, and a student imported without
     * an identity genuinely has no name yet. Refusing that row would lock the
     * photo correction out of exactly the records that need it most, and
     * filling it with a placeholder would write "(sem identidade)" into
     * somebody's record as though it were their name. So the row travels
     * without one, and confirm() below writes nothing where there is nothing.
     *
     * @return array<string, list<string>>
     */
    protected function rowRules(): array
    {
        return [
            'rows' => ['required', 'array'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.birth_date' => ['nullable', 'date'],
            'rows.*.situation_code' => ['nullable', 'string', 'max:32'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.process_number' => ['nullable', 'string', 'max:64'],
            // Deliberately NOT a path: the client only ever names a photo by
            // its position in the original photo pool (photo_index) and its
            // extension (photo_extension). The server is the only party that
            // ever builds an actual filesystem path, and it does so using
            // $token from the route — never anything the client sends — so
            // there is no client-controlled string that could ever resolve
            // outside this request's own temp folder. The regex on
            // photo_extension is an allowlist (alphanumeric only): it makes a
            // '/' or '..' in that value structurally impossible, not merely
            // unlikely. required_with in both directions means a row must
            // supply both fields together or neither — never just one.
            'rows.*.photo_index' => ['nullable', 'integer', 'min:0', 'required_with:rows.*.photo_extension'],
            'rows.*.photo_extension' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9]+$/', 'max:10', 'required_with:rows.*.photo_index'],
            'rows.*.include' => ['required', 'boolean'],
            // Which enrolment this row updates, when the student is already on
            // the roll. Re-resolved through the class before it is used.
            'rows.*.enrollment_id' => ['nullable', 'integer'],
        ];
    }

    public function store(Request $request, SchoolClass $class): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'roster' => ['required', 'file', 'mimes:xls,xlsx'],
        ]);

        try {
            $rosterRows = $this->rosterParser->parse($data['roster']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['roster' => $exception->getMessage()]);
        }

        if ($rosterRows === []) {
            return back()->withErrors(['roster' => 'Não foi possível encontrar nenhum aluno neste ficheiro.']);
        }

        // Photos are no longer part of this step — they are a separate,
        // later phase (attachPhotos() below), reusing the token created
        // here. Every row therefore starts with photo_index/photo_extension
        // null.
        $token = $this->tempStorage->newToken($class->id);

        // WHICH student, recognised HOW, and whether more than one answered —
        // by process number first, by name only when it is not a guess. The
        // whole of that reasoning lives in MatchRosterToEnrollments; this
        // controller only decides that a re-import must ask the question.
        $matcher = $this->matcher->forClass($class);

        // No photos at this step (see the comment above $token) — the
        // preview page always starts with an empty photo pool; attachPhotos()
        // below is the only place that ever populates it.
        // What the record says today, so the preview can show a CHANGE rather
        // than only a destination. Resolved from one query for the whole class,
        // never one per row.
        $currentStates = $class->enrollments()
            ->get(['id', 'status', 'status_reason'])
            ->mapWithKeys(fn (Enrollment $enrollment): array => [
                $enrollment->id => $enrollment->status_reason?->label() ?? $enrollment->status->label(),
            ]);

        $rows = $this->previewBuilder->build(
            $rosterRows,
            [],
            $matcher,
            fn (int $enrollmentId): ?string => $currentStates->get($enrollmentId),
        );

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
            'photos' => [],
            'flow' => 'roster',
            'helpArticles' => $this->helpArticles(),
        ]);
    }

    /**
     * Second, separate phase of the import: attaches a Word photo file to an
     * ALREADY-created preview, in place — reusing the roster upload's own
     * $token so both phases' temp files land in the same
     * roster-imports/{token}/ folder (the scheduled prune command and
     * confirm()'s own cleanup keep working unchanged).
     *
     * The row data validated/used here is whatever the CLIENT currently
     * holds — i.e. the teacher's own edits already made on the preview page
     * — never a fresh re-parse of the roster file. Matching therefore runs
     * against the CURRENT names, not the original ones.
     *
     * It is also the way BACK: choosing a different photo file simply posts
     * here again. Photos are staged under the same token, and a row that the
     * new file has nothing to say about keeps whatever it already had — so a
     * second attempt corrects the first instead of starting from zero.
     */
    public function attachPhotos(Request $request, SchoolClass $class, string $token): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        // The photo pool is a place on disk; the permission on the class says
        // who may import into it, not whose folder this is. Proven before a
        // single byte is written into it.
        abort_unless($this->tempStorage->belongsToClass($token, $class->id), 404);

        $validated = $request->validate([
            'photos' => ['required', 'file', 'mimes:doc,docx'],
            ...$this->rowRules(),
        ]);

        try {
            $photoMatches = $this->photoParser->parse($validated['photos']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['photos' => $exception->getMessage()]);
        }

        foreach ($photoMatches as $index => $photo) {
            $this->tempStorage->storePhoto($token, $index, $photo->imageBytes, $photo->extension);
        }

        // $request->validate() only returns the fields it was told to
        // validate, dropping every other key — but the preview page's row
        // shape also carries display-only fields (situation_recognized,
        // duplicate_in_file, already_enrolled, matched_by, current_name…)
        // that were never part of those rules and must still round-trip
        // unchanged. So the raw, as-submitted row is merged with its
        // validated/cast counterpart: validated fields win (sanitized
        // types), everything else survives.
        $rawRows = $request->input('rows', []);
        $rows = [];

        foreach ($rawRows as $index => $rawRow) {
            $rows[] = array_merge($rawRow, $validated['rows'][$index]);
        }

        $rows = $this->previewBuilder->matchPhotosToRows($rows, $photoMatches);

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
            'photos' => $this->photoPool($photoMatches),
            'flow' => $request->string('flow')->value() === 'photos' ? 'photos' : 'roster',
            'helpArticles' => $this->helpArticles(),
        ]);
    }

    /**
     * Every parsed photo offered to the preview, whether or not it auto-matched
     * a row by name — and, since PhotoFileParser started returning images from
     * a file exported without captions, whether or not it has a name at all.
     *
     * `named` is what lets the preview say «5 fotos sem nome no ficheiro» out
     * loud instead of leaving the teacher to work out why nothing matched. The
     * names themselves never travel: they are children's names, and the pool
     * is browsed by thumbnail.
     *
     * @param  list<PhotoMatch>  $photoMatches
     * @return list<array{index: int, extension: string, named: bool}>
     */
    protected function photoPool(array $photoMatches): array
    {
        $photos = [];

        foreach ($photoMatches as $index => $photo) {
            $photos[] = [
                'index' => $index,
                'extension' => $photo->extension,
                'named' => trim($photo->name) !== '',
            ];
        }

        return $photos;
    }

    /**
     * DESISTIR DA IMPORTAÇÃO — a saída que faltava.
     *
     * A pré-visualização só sabia confirmar. Quem chegasse aqui com o ficheiro
     * errado tinha um único caminho de volta: o botão «anterior» do browser, um
     * mecanismo do browser e não da aplicação, que reenvia o POST original
     * conforme o que o professor responder à caixa que o próprio browser
     * levanta. Um passo com uma só saída não é um passo, é um beco.
     *
     * NÃO INSCREVE NINGUÉM E NÃO ESCREVE NADA. É o oposto de confirm(): o que
     * faz é apagar — a pasta temporária deste token, com as fotografias que lá
     * estivessem. O PruneRosterImportTempStorage continua a fazer falta (quem
     * fecha o separador nunca passa por aqui), mas quem desiste de propósito
     * deixa de ter fotografias de alunos à espera de um cron.
     *
     * O token nunca chega ao disco sem primeiro se provar desta turma: o
     * `belongsToClass` é a mesma guarda de previewPhoto() e de confirm(), pela
     * mesma razão — a permissão sobre a turma diz quem pode importar para ela,
     * não de quem é uma pasta.
     */
    public function discard(Request $request, SchoolClass $class, string $token): RedirectResponse
    {
        Gate::authorize('update', $class);

        if ($this->tempStorage->belongsToClass($token, $class->id)) {
            $this->tempStorage->delete($token);
        }

        // De volta ao passo de carregamento, não apenas à turma: reabre o
        // diálogo de onde o ficheiro anterior saiu, para que escolher outro
        // seja um clique e não uma caça ao botão. Qual dos dois diálogos
        // depende de por onde se entrou — corrigir fotos e importar a lista
        // são pontos de partida diferentes.
        return $request->string('flow')->value() === 'photos'
            ? to_route('classes.show', ['class' => $class->ulid, 'fotos' => 1])
            : to_route('classes.show', ['class' => $class->ulid, 'importar' => 1]);
    }

    public function previewPhoto(SchoolClass $class, string $token, int $index): Response
    {
        Gate::authorize('update', $class);
        abort_unless($this->tempStorage->belongsToClass($token, $class->id), 404);

        $extension = $this->guessExtension($token, $index);

        abort_if($extension === null, 404);

        $bytes = $this->tempStorage->readPhoto($this->tempStorage->path($token)."/{$index}.{$extension}");

        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => "image/{$extension}"]);
    }

    protected function guessExtension(string $token, int $index): ?string
    {
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            if (Storage::disk('local')->exists($this->tempStorage->path($token)."/{$index}.{$extension}")) {
                return $extension;
            }
        }

        return null;
    }

    public function confirm(Request $request, SchoolClass $class, string $token): RedirectResponse
    {
        // Authorization and validation run BEFORE the try/finally below, on
        // purpose: they never touch the temp folder, so a rejected request
        // (wrong permissions, or a row that fails validation — e.g. a name
        // over 255 chars) leaves the teacher's uploaded photos untouched and
        // resubmittable. Only the row-processing loop actually needs the temp
        // photos and must guarantee their cleanup regardless of outcome, so
        // only IT sits inside try/finally.
        Gate::authorize('update', $class);

        $data = $request->validate($this->rowRules());

        // The temp token folder is ALWAYS cleaned up on the way out of this
        // block — whether a row throws partway through the loop, or it
        // completes normally. Rows already enrolled before such a throw are
        // deliberately NOT rolled back (no outer DB::transaction() here):
        // partial success is the accepted behavior, matching the "N
        // inscritos, M ignorados" partial-completion design elsewhere in this
        // flow. Only the temp-folder cleanup is unconditional; the exception
        // itself still propagates so the teacher sees the failure.
        try {
            $created = 0;
            $updated = 0;
            $photosWritten = 0;

            // CADA INSCRIÇÃO É ESCRITA UMA VEZ SÓ POR PEDIDO.
            //
            // A pré-visualização já marca duas linhas com o mesmo destino como
            // duplicadas e deixa-as de fora, mas isso é apresentação: as
            // linhas chegam do cliente, e nada impede que duas tragam o mesmo
            // `enrollment_id` — um separador antigo, uma ambiguidade resolvida
            // duas vezes para o mesmo aluno, um pedido feito à mão. Sem esta
            // guarda a segunda linha escreveria por cima da primeira em
            // silêncio: um nome perdido e uma foto no aluno errado, com um
            // «2 aluno(s) atualizado(s)» a dizer que correu bem. Quem recusa é
            // o servidor, como em todo o resto desta aplicação.
            $alreadyWritten = [];

            foreach ($data['rows'] as $row) {
                if (! $row['include']) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));

                // An enrolment id from the client is re-resolved THROUGH this
                // class, never trusted: a forged id belonging to another class
                // finds nothing and the row is enrolled as new instead.
                $existing = isset($row['enrollment_id'])
                    ? $class->enrollments()->whereKey((int) $row['enrollment_id'])->first()
                    : null;

                if ($existing !== null) {
                    if (isset($alreadyWritten[$existing->getKey()])) {
                        continue;
                    }

                    $alreadyWritten[$existing->getKey()] = true;
                }

                // Nobody on the roll and nothing to call them. This is the
                // photo-correction flow's row for a student who never got an
                // identity, ticked without a name being typed in — there is
                // no student here to create, and inventing a placeholder name
                // to satisfy the loop would put it in somebody's record.
                if ($existing === null && $name === '') {
                    continue;
                }

                // READ, not yet written. The bytes go to permanent storage by
                // two different routes depending on the branch below, and the
                // old code's shortcut — write first, delete again if the row
                // turned out to be an update — is exactly how a re-import
                // ended up unable to correct a photo at all.
                $photoBytes = null;
                $photoExtension = null;
                $photoIndex = $row['photo_index'] ?? null;
                $requestedExtension = $row['photo_extension'] ?? null;

                if ($photoIndex !== null && $requestedExtension !== null) {
                    // A permissão sobre a turma não chega para LER a pasta do
                    // token: ele nomeia um sítio no disco, não diz de quem é.
                    // Sem esta ligação, um token de outro professor trazia as
                    // fotografias dos alunos dele para esta turma. A guarda
                    // vive aqui, e não no topo do método, porque confirmar SEM
                    // fotografias nunca toca na pasta — e nesse caso o token
                    // não é mais do que um número por usar.
                    abort_unless($this->tempStorage->belongsToClass($token, $class->id), 404);

                    $photoBytes = $this->tempStorage->readPhoto(
                        $this->tempStorage->path($token)."/{$photoIndex}.{$requestedExtension}"
                    );
                    $photoExtension = $requestedExtension;
                }

                if ($existing !== null) {
                    // ALREADY ON THE ROLL. Nothing is created and nothing is
                    // erased: fillFromRoster() fills in what the file knows and
                    // the record does not, and the Student row — with it the
                    // pseudonym_code every result, record, intervention and
                    // piece of evidence hangs off — is never touched (§7).
                    //
                    // THE ROLL IS A SNAPSHOT OF THE CLASS AS IT STANDS, so
                    // a re-import is where «X → MT» actually gets recorded:
                    // filling in a name and skipping the state would leave
                    // a student on a roll the school says they left.
                    $this->enrollmentService->fillFromRoster($existing, array_filter([
                        'name' => $name,
                        'class_number' => $row['class_number'] ?? null,
                        'birth_date' => $row['birth_date'] ?? null,
                        'import_note' => $row['note'] ?? null,
                        'school_number' => $row['process_number'] ?? null,
                        'situation' => $this->situationFor($row['situation_code'] ?? null),
                    ], fn ($value): bool => $value !== null && $value !== ''));

                    // THE PHOTO IS WRITTEN HERE, and this is the fix.
                    //
                    // This branch used to move the staged photo into permanent
                    // storage and then delete it again, saying in as many words
                    // that an update "is simply not what this path writes". The
                    // consequence was a dead end with no way out: once a class
                    // existed, no re-import could ever correct its photos, and
                    // the only remaining move was to delete the students —
                    // their results with them — and start the year again.
                    //
                    // storeBytes() is the same single writer the manual,
                    // one-student path uses: it points the identity at the new
                    // file and only then removes the one it pointed at before,
                    // so replacing a wrong photo leaves nothing orphaned. The
                    // enrolment, the results and the history are not its
                    // business and it does not touch them (§4, §7).
                    if ($photoBytes !== null && $photoExtension !== null) {
                        $identity = $existing->student?->identity;

                        if ($identity !== null) {
                            $this->photoService->storeBytes($identity, $photoBytes, $photoExtension);
                            $photosWritten++;
                        }
                    }

                    $updated++;

                    continue;
                }

                $photoPath = $photoBytes !== null && $photoExtension !== null
                    ? $this->photoService->putBytes($photoBytes, $photoExtension)
                    : null;

                try {
                    // An unrecognised «SIT.» enrols the student plainly rather
                    // than guessing at a state — the preview already told the
                    // teacher the code was not understood (§10, §11).
                    $situation = $this->situationFor($row['situation_code'] ?? null);

                    $this->enrollmentService->enrollNew($class, [
                        'name' => $name,
                        'class_number' => $row['class_number'] ?? null,
                        'birth_date' => $row['birth_date'] ?? null,
                        'import_note' => $row['note'] ?? null,
                        'school_number' => $row['process_number'] ?? null,
                        'photo_path' => $photoPath,
                        'status' => $situation['status'] ?? EnrollmentStatus::Active->value,
                        'status_reason' => $situation['status_reason'] ?? null,
                    ]);
                } catch (Throwable $exception) {
                    // enrollNew() rolls its own transaction back, so nothing in
                    // the database points at the photo we just wrote for this
                    // row. Compensate for it here — the temp folder cleanup
                    // below only covers the staging area, not permanent storage.
                    if ($photoPath !== null) {
                        Storage::disk(StudentPhotoService::DISK)->delete($photoPath);
                    }

                    throw $exception;
                }

                if ($photoPath !== null) {
                    $photosWritten++;
                }

                $created++;
            }

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $this->outcomeMessage($created, $updated, $photosWritten),
            ]);

            return to_route('classes.show', $class->ulid);
        } finally {
            $this->tempStorage->delete($token);
        }
    }

    /**
     * What actually happened, in the words of the thing the teacher came to do.
     *
     * A photo correction that enrols nobody used to report «0 aluno(s)
     * inscrito(s)», which reads as a failure and is not one. Each clause is
     * only there when its number is: nothing enrolled, nothing said about
     * enrolments.
     */
    protected function outcomeMessage(int $created, int $updated, int $photosWritten): string
    {
        $clauses = [];

        if ($created > 0) {
            $clauses[] = "{$created} aluno(s) inscrito(s)";
        }

        if ($updated > 0) {
            $clauses[] = "{$updated} aluno(s) atualizado(s)";
        }

        if ($photosWritten > 0) {
            $clauses[] = "{$photosWritten} foto(s) associada(s)";
        }

        if ($clauses === []) {
            return 'Nada foi alterado — nenhuma linha estava selecionada.';
        }

        return implode(', ', $clauses).'.';
    }

    /**
     * The administrative state a roster row asks for.
     *
     * AN UNKNOWN OR EMPTY «SIT.» CHANGES NOTHING. The preview already flags it
     * for the teacher; turning it into «matriculado» here would be the
     * application guessing, and on a re-import that guess would silently put a
     * student back on a roll they had left (§10, §11).
     *
     * @return array{status: string, status_reason: ?string}|null
     */
    protected function situationFor(?string $code): ?array
    {
        $situation = EnrollmentSituation::tryFromCode($code);

        if ($situation === null) {
            return null;
        }

        return [
            'status' => $situation->status()->value,
            'status_reason' => $situation->reason()?->value,
        ];
    }
}
