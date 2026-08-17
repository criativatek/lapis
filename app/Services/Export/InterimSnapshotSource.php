<?php

namespace App\Services\Export;

use App\Models\InterimAssessment;

/**
 * The mentions a kept moment holds — read, never recomputed.
 *
 * NOTHING HERE ASKS THE DATABASE WHAT ANYTHING MEANS. The band, its INOVAR
 * code and the reason behind each ⚠ all come out of the stored document, so a
 * grid produced in January from a November photograph carries November's
 * answers even if a score was corrected, a domain renamed or a band's code
 * changed in between (§10, §11, §14).
 *
 * That last one is the reason `inovar_code_snapshot` exists at all: reference
 * data does change, and it has changed in this project's own history.
 */
class InterimSnapshotSource implements InovarExportSource
{
    public function __construct(protected InterimAssessment $interim) {}

    public function label(): string
    {
        // The teacher's own name for the moment — never a label rebuilt from
        // the date, which is a different thing they did not choose.
        return $this->interim->name;
    }

    public function referenceLabel(): ?string
    {
        return $this->interim->reference_date->format('d/m/Y');
    }

    public function cells(): array
    {
        $codesByLevel = $this->codesByLevel();
        $cells = [];

        foreach ($this->interim->snapshot['students'] ?? [] as $student) {
            $enrollmentId = (int) $student['enrollment_id'];

            foreach ($student['domains'] ?? [] as $cell) {
                $mention = $cell['mention'] ?? null;
                $levelId = $mention['scale_level_id'] ?? null;

                $cells[$enrollmentId][(int) $cell['domain_id']] = [
                    'band_label' => $mention['label_snapshot'] ?? null,
                    // The code THAT BAND CARRIED when the photograph was taken.
                    'inovar_code' => $levelId === null ? null : ($codesByLevel[(int) $levelId] ?? null),
                    'coverage_warning' => (bool) ($cell['coverage_warning'] ?? false),
                    // The instrument, its date and the state recorded against
                    // it, as they stood then. If the student has since sat the
                    // missing test, this still shows November's absence (§14).
                    'coverage_elements' => $cell['coverage_elements'] ?? [],
                ];
            }
        }

        return $cells;
    }

    public function isExportable(): bool
    {
        $bands = $this->interim->snapshot['scale']['bands'] ?? [];

        return $bands !== [] && $this->missingBands() === [];
    }

    public function missingBands(): array
    {
        $missing = [];

        foreach ($this->interim->snapshot['scale']['bands'] ?? [] as $band) {
            if (! $this->accepted($band['inovar_code_snapshot'] ?? null)) {
                $missing[] = (string) ($band['label_snapshot'] ?? '(sem nome)');
            }
        }

        return $missing;
    }

    /**
     * The INOVAR code of each band, by the band's canonical id.
     *
     * @return array<int, string>
     */
    protected function codesByLevel(): array
    {
        $codes = [];

        foreach ($this->interim->snapshot['scale']['bands'] ?? [] as $band) {
            $code = $band['inovar_code_snapshot'] ?? null;

            if ($this->accepted($code)) {
                $codes[(int) $band['scale_level_id']] = trim((string) $code);
            }
        }

        return $codes;
    }

    /**
     * The same closed set the live resolver accepts. A band whose stored code
     * is something else is a configuration error frozen into history, and
     * passing it through would produce a file INOVAR refuses — the teacher
     * would learn about it there rather than here.
     */
    protected function accepted(?string $code): bool
    {
        return $code !== null
            && trim($code) !== ''
            && in_array(trim($code), InovarCodeResolver::ACCEPTED, true);
    }
}
