<?php

namespace App\Services\Import;

use App\Domain\Import\EnrollmentSituation;
use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterMatch;
use App\Domain\Import\RosterRow;
use Illuminate\Support\Str;

/**
 * Merges parsed roster rows with parsed photo matches into plain arrays ready
 * to hand to the Inertia preview page. Never touches Eloquent or the
 * database directly — the matcher is injected so this stays a fast, pure unit.
 */
class RosterImportPreviewBuilder
{
    /**
     * The «SIT.» codes this application understands, and what they mean, are
     * EnrollmentSituation's business — never a second list kept here (§25).
     */

    /** A student the class does not have yet. */
    public const ACTION_ENROL = 'enrol';

    /** Already on the roll: the roster fills in what the record is missing, and erases nothing. */
    public const ACTION_UPDATE = 'update';

    /** The same name twice in one file — not something to guess about. */
    public const ACTION_SKIP = 'skip';

    /**
     * More than one student on the roll answers to this row. Never resolved
     * here, and never included by default: the teacher points at the right one
     * in the preview, or says it is somebody new (§3).
     */
    public const ACTION_AMBIGUOUS = 'ambiguous';

    /**
     * @param  list<RosterRow>  $rosterRows
     * @param  list<PhotoMatch>  $photoMatches
     * @param  \Closure(RosterRow): RosterMatch  $matcher  What this class already knows about the row — which enrolment, recognised how, and whether more than one answered. MatchRosterToEnrollments::forClass() is the database-backed implementation.
     * @param  \Closure(int): ?string|null  $currentStateOf  The words the record currently uses for that enrolment, so the preview can show a change instead of only a destination (§12). Optional: without it the preview simply shows no «estado atual».
     * @return list<array<string, mixed>>
     */
    public function build(array $rosterRows, array $photoMatches, \Closure $matcher, ?\Closure $currentStateOf = null): array
    {
        $nameCounts = [];

        foreach ($rosterRows as $row) {
            $key = $this->normalize($row->name);
            $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
        }

        // O MATCHER CORRE UMA VEZ POR LINHA, ANTES DE SE DECIDIR SEJA O QUE
        // FOR. Duas linhas podem apontar ao mesmo aluno sem terem o mesmo
        // nome — basta o n.º de processo repetido, que é um engano corrente
        // num ficheiro feito à mão —, e nesse caso a segunda escrevia por
        // cima da primeira sem que nada o dissesse: um nome perdia-se, uma
        // foto ia para o sítio errado, e a contagem final dizia «2 alunos
        // atualizados» como se fossem dois registos. Contar os destinos exige
        // conhecê-los todos primeiro (§8).
        $matches = [];
        $enrollmentCounts = [];

        foreach ($rosterRows as $index => $row) {
            $match = $matcher($row);
            $matches[$index] = $match;

            if ($match->enrollmentId !== null) {
                $enrollmentCounts[$match->enrollmentId] = ($enrollmentCounts[$match->enrollmentId] ?? 0) + 1;
            }
        }

        $preview = [];

        foreach ($rosterRows as $index => $row) {
            $key = $this->normalize($row->name);
            $match = $matches[$index];

            // Duas linhas para o mesmo aluno são um duplicado tanto como duas
            // linhas com o mesmo nome, e tratam-se da mesma maneira: nenhuma
            // entra, e a pré-visualização mostra as duas.
            $duplicateTarget = $match->enrollmentId !== null
                && ($enrollmentCounts[$match->enrollmentId] ?? 0) > 1;

            // Contadas à parte, e não somadas numa só: «este nome aparece duas
            // vezes» e «estas duas linhas são o mesmo aluno» são coisas
            // diferentes de se ler num ecrã, e a segunda acontece com nomes
            // que não se parecem nada um com o outro. Dizer «nome duplicado no
            // ficheiro» a quem tem dois nomes distintos com o mesmo n.º de
            // processo seria mandá-lo procurar uma coisa que não existe.
            $duplicateInFile = $nameCounts[$key] > 1;
            $photoIndex = $this->findPhotoIndex($row->name, $photoMatches);

            // A name appearing twice in the same file is not something to guess
            // about, and neither is a name that two students on the roll both
            // answer to. Everyone else is either new here, or already on the
            // roll and therefore an UPDATE rather than a second enrolment (§8).
            $action = match (true) {
                $duplicateInFile, $duplicateTarget => self::ACTION_SKIP,
                $match->ambiguous => self::ACTION_AMBIGUOUS,
                $match->enrollmentId !== null => self::ACTION_UPDATE,
                default => self::ACTION_ENROL,
            };

            // «MT» read as «Mudou de turma», not shown as a bare code — and an
            // unrecognised one says so rather than being quietly treated as
            // «Matriculado» (§10, §24).
            $situation = EnrollmentSituation::tryFromCode($row->situationCode);
            $currentState = $match->enrollmentId !== null && $currentStateOf !== null
                ? $currentStateOf($match->enrollmentId)
                : null;
            $newState = $situation?->label();

            $preview[] = [
                'name' => $row->name,
                'class_number' => $row->classNumber,
                'birth_date' => $row->birthDate,
                'situation_code' => $row->situationCode,
                'situation_recognized' => $situation !== null,
                'situation_label' => $newState,
                'current_state' => $currentState,
                // Only when the roll actually asks for something different from
                // what the record says — a change is worth a line, a repetition
                // is noise (§12).
                'state_changes' => $newState !== null && $currentState !== null && $newState !== $currentState,
                'process_number' => $row->processNumber,
                'note' => $row->note,
                'photo_index' => $photoIndex,
                'photo_extension' => $photoIndex !== null ? $photoMatches[$photoIndex]->extension : null,
                'duplicate_in_file' => $duplicateInFile,
                'duplicate_target' => $duplicateTarget,
                'already_enrolled' => $match->enrollmentId !== null,
                'enrollment_id' => $match->enrollmentId,
                // «Reconhecido pelo n.º de processo» and «reconhecido pelo
                // nome» do not deserve the same amount of the teacher's trust,
                // so the preview is told which of the two happened (§3).
                'matched_by' => $match->matchedBy,
                'ambiguous' => $match->ambiguous,
                'candidates' => $match->candidates,
                // «Nome atual → Nome novo» (§5): a corrected spelling updates
                // the student it already belongs to; it never adds a second one.
                'current_name' => $match->currentName,
                'name_changes' => $match->currentName !== null
                    && $this->normalize($match->currentName) !== $key,
                // The difference between «associar uma foto» and «substituir a
                // que lá está» — worth saying before it happens, not after (§4).
                'has_photo_today' => $match->hasPhoto,
                'action' => $action,
                'include' => $action === self::ACTION_ENROL || $action === self::ACTION_UPDATE,
            ];
        }

        return $preview;
    }

    /**
     * Matches freshly-parsed photos against ALREADY-BUILT preview rows —
     * used by the "attach photos" step, which runs after the roster has
     * already been previewed (and possibly edited by the teacher). Unlike
     * build(), $rows here are plain arrays (the client's current row data,
     * name edits included), not RosterRow objects, and there is no
     * duplicate/already-enrolled recalculation: those flags were already
     * decided by the original build() call and are passed through unchanged.
     *
     * A row whose name matches no parsed photo is left exactly as it came
     * in — so a row that already had no photo simply keeps photo_index:
     * null, and this never clobbers a manual assignment from an earlier
     * attach-photos call that this call's photo file happens not to repeat.
     *
     * @param  list<array<string, mixed>>  $rows  each must at least have a 'name' key (string)
     * @param  list<PhotoMatch>  $photoMatches
     * @return list<array<string, mixed>>
     */
    public function matchPhotosToRows(array $rows, array $photoMatches): array
    {
        foreach ($rows as &$row) {
            $name = $row['name'] ?? null;
            $photoIndex = $this->findPhotoIndex(is_string($name) ? $name : '', $photoMatches);

            if ($photoIndex !== null) {
                $row['photo_index'] = $photoIndex;
                $row['photo_extension'] = $photoMatches[$photoIndex]->extension;
            }
        }

        return $rows;
    }

    /**
     * Real Intuitivo exports were verified to name-match this way, not by
     * exact string equality: the Word photo sheet's captions carry only
     * first+last name ("Afonso Mordomo"), while the Excel roster carries the
     * full name including middle names ("Afonso Pito Mordomo"). A plain
     * normalized-string comparison never matches a single real photo against
     * a real roster — this checks each name's words against the other's, in
     * order, so either one may be the abbreviated side.
     *
     * A PHOTO WITH NO NAME NEVER MATCHES ANYBODY. PhotoFileParser now returns
     * the images out of a file exported without captions, so that the teacher
     * can assign them by hand in the preview. Auto-matching one of those would
     * be inventing an association out of nothing but position — precisely the
     * mistake this flow exists to make impossible.
     *
     * @param  list<PhotoMatch>  $photoMatches
     */
    protected function findPhotoIndex(string $name, array $photoMatches): ?int
    {
        $targetWords = $this->words($name);

        foreach ($photoMatches as $index => $photo) {
            if (trim($photo->name) === '') {
                continue;
            }

            $photoWords = $this->words($photo->name);

            if ($this->isWordSubsequence($photoWords, $targetWords) || $this->isWordSubsequence($targetWords, $photoWords)) {
                return $index;
            }
        }

        return null;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }

    /**
     * @return list<string>
     */
    protected function words(string $value): array
    {
        return array_values(Str::of($value)->squish()->lower()->explode(' ')->all());
    }

    /**
     * True when every word in $needle appears in $haystack, in the same
     * relative order — e.g. ["afonso", "mordomo"] is a subsequence of
     * ["afonso", "pito", "mordomo"], but never of ["pito", "afonso",
     * "mordomo"] or of a haystack missing either word. An empty $needle
     * never matches — it would otherwise be trivially "found" in anything.
     *
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    protected function isWordSubsequence(array $needle, array $haystack): bool
    {
        if ($needle === []) {
            return false;
        }

        $position = 0;

        foreach ($needle as $word) {
            while (true) {
                if (! array_key_exists($position, $haystack)) {
                    return false;
                }

                if ($haystack[$position] === $word) {
                    $position++;

                    continue 2;
                }

                $position++;
            }
        }

        return true;
    }
}
