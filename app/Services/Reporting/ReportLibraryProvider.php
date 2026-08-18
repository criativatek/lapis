<?php

namespace App\Services\Reporting;

use App\Models\ReportLibraryEntry;
use Illuminate\Support\Collection;

/**
 * Reading the pedagogical library, and turning a chosen code into a COPY.
 *
 * THE COPY IS THE WHOLE POINT. When a teacher picks «guiões de planificação
 * prévia e revisão orientada», what goes into the report is that sentence and
 * its objective — not a foreign key. Rewording the library entry next September
 * then leaves last February's report exactly as it was signed, and a finalized
 * document needs no join to be reprinted (§33, §38).
 *
 * It also means composers never query: they read what is already in
 * `teacher_input`, which is what makes «reports do not compute» enforceable
 * rather than merely stated (§1).
 *
 * WHAT THE LIBRARY IS FOR (§14). A difficulty has strategies that answer IT,
 * and each strategy states the objective it serves. Offering the full list under
 * every difficulty is what makes generated reports read like form letters, so
 * `strategiesFor` narrows by `related_code` and nothing else.
 */
class ReportLibraryProvider
{
    /**
     * Difficulties this organization may choose from — the shared system set
     * plus its own.
     *
     * @return list<array<string, mixed>>
     */
    public function difficulties(?int $subjectId = null): array
    {
        return $this->present(
            ReportLibraryEntry::query()
                ->ofKind(ReportLibraryEntry::KIND_DIFFICULTY)
                ->when($subjectId !== null, fn ($query) => $query
                    ->where(fn ($inner) => $inner->whereNull('subject_id')->orWhere('subject_id', $subjectId)))
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(),
        );
    }

    /**
     * The strategies that answer one difficulty.
     *
     * @return list<array<string, mixed>>
     */
    public function strategiesFor(string $difficultyCode): array
    {
        return $this->present(
            ReportLibraryEntry::query()
                ->answeringDifficulty($difficultyCode)
                ->orderBy('sort_order')
                ->get(),
        );
    }

    /**
     * Every strategy, grouped by the difficulty it answers — one query for a
     * screen that has to offer all of them (§62).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function strategiesByDifficulty(): array
    {
        $grouped = [];

        $strategies = ReportLibraryEntry::query()
            ->ofKind(ReportLibraryEntry::KIND_STRATEGY)
            ->orderBy('sort_order')
            ->get();

        foreach ($strategies as $strategy) {
            $code = (string) $strategy->related_code;

            $grouped[$code] ??= [];
            $grouped[$code][] = $this->row($strategy);
        }

        return $grouped;
    }

    /**
     * Turn what a form posted into what the report will keep.
     *
     * A row that names a library code is resolved and COPIED; a row the teacher
     * typed themselves is kept as typed. Either way what lands in
     * `teacher_input` is self-contained text, and the report never depends on
     * the library again.
     *
     * @param  array<mixed>  $chosen  Straight off a request, so shaped by nobody.
     * @return list<array<string, mixed>>
     */
    public function resolveDifficulties(array $chosen): array
    {
        $chosen = array_values(array_filter($chosen, 'is_array'));

        $codes = array_values(array_filter(array_map(
            fn (array $row) => is_string($row['code'] ?? null) ? $row['code'] : null,
            $chosen,
        )));

        $entries = $codes === []
            ? collect()
            : ReportLibraryEntry::query()
                ->ofKind(ReportLibraryEntry::KIND_DIFFICULTY)
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');

        $resolved = [];

        foreach ($chosen as $row) {
            $code = is_string($row['code'] ?? null) ? $row['code'] : null;
            $entry = $code === null ? null : $entries->get($code);

            // `->` and not `?->`: the left of ?? already tolerates a null object.
            $label = $entry->label ?? (is_string($row['label'] ?? null) ? trim($row['label']) : '');

            // A difficulty with no words is not a difficulty. Dropped rather
            // than printed as an empty bullet.
            if ($label === '') {
                continue;
            }

            $resolved[] = [
                'code' => $code,
                'label' => $label,
                'domain' => is_string($row['domain'] ?? null) && trim($row['domain']) !== '' ? trim($row['domain']) : null,
                'note' => is_string($row['note'] ?? null) && trim($row['note']) !== '' ? trim($row['note']) : null,
                'strategies' => $this->resolveStrategies($row['strategies'] ?? []),
            ];
        }

        return $resolved;
    }

    /**
     * @param  mixed  $chosen
     * @return list<array<string, mixed>>
     */
    protected function resolveStrategies($chosen): array
    {
        if (! is_array($chosen)) {
            return [];
        }

        $codes = array_values(array_filter(array_map(
            fn ($row) => is_array($row) && is_string($row['code'] ?? null) ? $row['code'] : null,
            $chosen,
        )));

        $entries = $codes === []
            ? collect()
            : ReportLibraryEntry::query()
                ->ofKind(ReportLibraryEntry::KIND_STRATEGY)
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');

        $resolved = [];

        foreach ($chosen as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = is_string($row['code'] ?? null) ? $row['code'] : null;
            $entry = $code === null ? null : $entries->get($code);

            // `->` and not `?->`: the left of ?? already tolerates a null object.
            $label = $entry->label ?? (is_string($row['label'] ?? null) ? trim($row['label']) : '');

            if ($label === '') {
                continue;
            }

            $resolved[] = [
                'code' => $code,
                'label' => $label,
                // §14: a strategy without a stated objective is a list item.
                // The teacher may write their own; nothing is invented for them.
                'objective' => $entry->objective
                    ?? (is_string($row['objective'] ?? null) && trim($row['objective']) !== '' ? trim($row['objective']) : null),
            ];
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, ReportLibraryEntry>  $entries
     * @return list<array<string, mixed>>
     */
    protected function present(Collection $entries): array
    {
        return array_values($entries->map(fn (ReportLibraryEntry $entry) => $this->row($entry))->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(ReportLibraryEntry $entry): array
    {
        return [
            'code' => $entry->code,
            'label' => $entry->label,
            'objective' => $entry->objective,
            'related_code' => $entry->related_code,
            'is_system' => $entry->isSystem(),
        ];
    }
}
