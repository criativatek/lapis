<?php

namespace App\Actions\DataImports\Concerns;

/**
 * The mirror image of `ResolvesBackupReferences` (the plan side): once a
 * row has actually been written, resolving another row's reference to it
 * means looking up a REAL destination id — never the plan's placeholder
 * shape. Kept as a trait, shared by every `ExecuteDataImport` writer
 * collaborator, for the same reason the plan side is a trait: a single
 * class this size makes PHPStan's own memory footprint too large to check
 * reliably.
 */
trait ResolvesWrittenReferences
{
    /**
     * @param  array<string, int>  $byUlid
     */
    private function resolveId(?string $ulid, array $byUlid): ?int
    {
        return $ulid === null ? null : ($byUlid[$ulid] ?? null);
    }

    /**
     * @param  array{ulid: string|null, name: string, is_system: bool}|null  $ref
     * @param  array<string, int>  $byRef
     */
    private function resolveScaleId(?array $ref, array $byRef): ?int
    {
        if ($ref === null) {
            return null;
        }

        $key = $ref['is_system'] ? "system:{$ref['name']}" : "custom:{$ref['ulid']}";

        return $byRef[$key] ?? null;
    }

    /**
     * @param  array{ulid: string|null, code: string, is_system: bool}|null  $ref
     * @param  array<string, int>  $byRef
     */
    private function resolveInstrumentTypeId(?array $ref, array $byRef): ?int
    {
        if ($ref === null) {
            return null;
        }

        $key = $ref['is_system'] ? "system:{$ref['code']}" : "custom:{$ref['ulid']}";

        return $byRef[$key] ?? null;
    }

    /**
     * @param  array{scale: array{ulid: string|null, name: string, is_system: bool}, code: string}|null  $ref
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function resolveScaleLevelId(?array $ref, array $scalesByRef, array $scaleLevelsByRef): ?int
    {
        if ($ref === null) {
            return null;
        }

        $scaleKey = $ref['scale']['is_system'] ? "system:{$ref['scale']['name']}" : "custom:{$ref['scale']['ulid']}";

        return $scaleLevelsByRef["{$scaleKey}:{$ref['code']}"] ?? null;
    }
}
