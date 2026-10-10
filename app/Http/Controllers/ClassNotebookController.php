<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\ClassNotebook\StoreClassNotebookEntryRequest;
use App\Http\Requests\ClassNotebook\UpdateClassNotebookEntryRequest;
use App\Models\ClassNotebookEntry;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O caderno da turma: registos privados do professor sobre a turma como grupo.
 *
 * Autorização, por esta ordem, em TODAS as operações: a turma (`view` — quem
 * a ensina) e depois a autoria do registo (`ClassNotebookEntryPolicy`, 404
 * para qualquer outro, mesmo colega da mesma turma). Toda a leitura filtra por
 * `author_id`. As escritas recusam a impersonação: quem presta assistência
 * pode ver o que o professor vê, nunca escrever no caderno em nome dele.
 *
 * SEM `audit_events`, deliberadamente: a auditoria da organização é lida pelo
 * responsável, e um evento «registo criado no caderno da 7.º A» expunha-lhe a
 * existência e o ritmo dos registos privados de outro professor.
 */
class ClassNotebookController extends Controller
{
    use RefusesDuringImpersonation;

    private const PER_PAGE = 20;

    /**
     * O carácter de escape do LIKE. Não é a barra invertida: em MySQL ela é
     * escape por omissão e exige `'\\'` na cláusula ESCAPE, em SQLite exige
     * `'\'` — `'!'` é igual nos dois.
     */
    private const LIKE_ESCAPE = '!';

    public function index(Request $request, SchoolClass $class): Response|RedirectResponse
    {
        Gate::authorize('view', $class);

        $user = $this->user($request);
        $class->loadMissing(['subject', 'academicYear']);

        $term = trim((string) $request->query('q', ''));

        $query = $this->mine($class, $user);

        if ($term !== '') {
            $pattern = '%'.$this->escapeLike($term).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('title like ? escape \''.self::LIKE_ESCAPE.'\'', [$pattern])
                ->orWhereRaw('body like ? escape \''.self::LIKE_ESCAPE.'\'', [$pattern]));
        }

        $entries = $query->orderedForNotebook()->paginate(self::PER_PAGE)->withQueryString();

        // Uma página para lá da última (por exemplo, depois de eliminar o
        // último registo da página 3): vai para a última que existe.
        if ($entries->isEmpty() && $entries->currentPage() > 1) {
            return redirect()->route('classes.notebook.index', array_filter([
                'class' => $class->ulid,
                'q' => $term !== '' ? $term : null,
                'page' => $entries->lastPage(),
            ], fn ($value) => $value !== null));
        }

        $entries->through(fn (ClassNotebookEntry $entry): array => [
            'ulid' => $entry->ulid,
            'title' => $entry->title,
            'body' => $entry->body,
            'is_pinned' => $entry->is_pinned,
            'created_at' => $entry->created_at?->toIso8601String(),
            'edited_at' => $entry->edited_at?->toIso8601String(),
            'lock_version' => $entry->lock_version,
        ]);

        return Inertia::render('classes/Notebook', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'archived' => $class->isArchived(),
            ],
            'entries' => $entries,
            'filters' => ['q' => $term],
            // Os MEUS registos nesta turma, ignorando a pesquisa: é o que
            // decide entre «vazio» e «nenhum resultado».
            'totalEntries' => $this->mine($class, $user)->count(),
            'can' => ['write' => ! $request->session()->has('impersonator_id')],
        ]);
    }

    public function store(StoreClassNotebookEntryRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('view', $class);
        $this->refuseDuringImpersonation($request);

        ClassNotebookEntry::create([
            'class_id' => $class->getKey(),
            'author_id' => $this->user($request)->getKey(),
            'title' => $this->titleOf($request),
            'body' => (string) $request->validated('body'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Registo guardado.']);

        return to_route('classes.notebook.index', $class);
    }

    public function update(UpdateClassNotebookEntryRequest $request, SchoolClass $class, ClassNotebookEntry $notebookEntry): RedirectResponse
    {
        Gate::authorize('view', $class);
        Gate::authorize('update', $notebookEntry);
        $this->refuseDuringImpersonation($request);

        $title = $this->titleOf($request);
        $body = (string) $request->validated('body');
        $version = (int) $request->validated('lock_version');

        DB::transaction(function () use ($notebookEntry, $title, $body, $version): void {
            $entry = ClassNotebookEntry::query()->whereKey($notebookEntry->getKey())->lockForUpdate()->firstOrFail();

            if ($entry->lock_version !== $version) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Este registo foi alterado noutro separador ou dispositivo. O teu texto continua aqui: copia-o antes de recarregar a página.',
                ]);
            }

            // Nada mudou: não é uma edição, e `edited_at` não se mexe.
            if ($entry->title === $title && $entry->body === $body) {
                return;
            }

            $entry->forceFill([
                'title' => $title,
                'body' => $body,
                'edited_at' => now(),
                'lock_version' => $entry->lock_version + 1,
            ])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alterações guardadas.']);

        return back();
    }

    public function pin(Request $request, SchoolClass $class, ClassNotebookEntry $notebookEntry): RedirectResponse
    {
        Gate::authorize('view', $class);
        Gate::authorize('update', $notebookEntry);
        $this->refuseDuringImpersonation($request);

        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        $pinned = (bool) $data['pinned'];

        // Fixar não é editar: nem `edited_at` nem `lock_version` (só o
        // `updated_at` técnico muda).
        $notebookEntry->forceFill(['is_pinned' => $pinned])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $pinned ? 'Registo fixado no topo.' : 'Registo desafixado.',
        ]);

        return back();
    }

    public function destroy(Request $request, SchoolClass $class, ClassNotebookEntry $notebookEntry): RedirectResponse
    {
        Gate::authorize('view', $class);
        Gate::authorize('delete', $notebookEntry);
        $this->refuseDuringImpersonation($request);

        $notebookEntry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Registo eliminado.']);

        return back();
    }

    /**
     * O caderno de quem olha, nesta turma — a ÚNICA porta de leitura: nunca
     * há uma consulta ao caderno sem autor.
     *
     * @return Builder<ClassNotebookEntry>
     */
    private function mine(SchoolClass $class, User $user): Builder
    {
        return ClassNotebookEntry::query()
            ->where('class_id', $class->getKey())
            ->where('author_id', $user->getKey());
    }

    private function titleOf(Request $request): ?string
    {
        $title = $request->input('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
