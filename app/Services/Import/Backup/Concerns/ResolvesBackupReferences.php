<?php

namespace App\Services\Import\Backup\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The small cross-cutting helpers every BuildImportPlan collaborator needs:
 * translating a reason, resolving a ulid-identified row against the
 * destination (existing/elsewhere), resolving an author email, and
 * resolving a scale/scale-level/instrument-type reference against the
 * lookup tables `BuildAssessmentStructurePlan` produces.
 *
 * Lives in a trait rather than a shared base class so each collaborator
 * (`BuildAssessmentStructurePlan`, `BuildAssessmentDataPlan`,
 * `BuildPedagogicalRecordsPlan`) stays a small, independently analysable
 * file — the reason this split exists at all is that a single class this
 * size made PHPStan's own memory footprint too large to check reliably.
 *
 * @phpstan-type ScaleResolution array{rows: array<int, array<string, mixed>>, byRef: Collection<string, array{destination_id: int, levelCodes: array<string, true>}>}
 * @phpstan-type InstrumentTypeResolution array{rows: array<int, array<string, mixed>>, byRef: Collection<string, int>}
 * @phpstan-type ScaleRef array{ulid: string, name: string, is_system: bool}
 * @phpstan-type ScaleLevelRef array{scale: ScaleRef, code: string}
 * @phpstan-type InstrumentTypeRef array{ulid: string, code: string, is_system: bool}
 */
trait ResolvesBackupReferences
{
    /**
     * A translated string, never the string|array union __() is typed to
     * return — every call site here passes a literal key with placeholders,
     * which always resolves to a string.
     *
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    private function conflictReason(): string
    {
        return $this->t('Já existe um registo com esta identidade, mas os dados diferem.');
    }

    private function elsewhereReason(): string
    {
        return $this->t('Este registo pertence a outra organização e não pode ser restaurado aqui.');
    }

    /**
     * `ulid` is unique across the WHOLE table, not per-organization — the
     * database will refuse a second row with a ulid another organization's
     * row already holds. Checked up front, cross-tenant on purpose
     * (`withoutGlobalScopes()`, the same legitimate pattern already used for
     * admin reports), so classifiers can match a destination business key
     * or clone with a fresh ULID instead of attempting the source identity.
     *
     * Every scope is stripped, not just one named `organization` — several
     * models restorable here (`Scale`, `InstrumentType`) register their
     * tenant-visibility scope under a different name (`scaleVisibility`,
     * `typeVisibility`) precisely because a system row has no organization
     * of its own. Removing only a scope literally named `organization`
     * would leave those two filtering to "mine or system" even here,
     * silently hiding another organization's row and letting its ulid
     * collide at write time instead of being caught in the plan.
     *
     * @param  class-string<Model>  $modelClass
     * @param  Collection<int, string>  $ulids
     * @return Collection<string, int>
     */
    private function ulidOrganizationsElsewhere(string $modelClass, Collection $ulids, int $destinationOrganizationId): Collection
    {
        if ($ulids->isEmpty()) {
            return collect();
        }

        return $modelClass::query()
            ->withoutGlobalScopes()
            ->whereIn('ulid', $ulids)
            ->where('organization_id', '!=', $destinationOrganizationId)
            ->pluck('organization_id', 'ulid');
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  Collection<int, string>  $ulids
     * @return array{existing: Collection<string, TModel>, elsewhere: Collection<string, int>}
     */
    private function ulidLookups(string $modelClass, Collection $ulids, Organization $destination): array
    {
        $existing = $ulids->isEmpty()
            ? collect()
            : $modelClass::query()->where('organization_id', $destination->getKey())->whereIn('ulid', $ulids)->get()->keyBy('ulid');

        return ['existing' => $existing, 'elsewhere' => $this->ulidOrganizationsElsewhere($modelClass, $ulids, $destination->getKey())];
    }

    /**
     * The only safe author resolution this importer ever makes: the row's
     * recorded email is literally the email of whoever is confirming THIS
     * import (§30 of the import brief — "current user quando a autoria
     * original é o próprio exportador e isso é inequívoco", generalised to
     * any individual author field on the same footing). Never a lookup
     * against other users in the destination organization — that would be
     * matching a stranger's account by email, which is exactly the kind of
     * invention the brief forbids.
     */
    private function resolveAuthor(?string $email, User $actor): ?int
    {
        if ($email === null || $email === '') {
            return null;
        }

        return strcasecmp($email, $actor->email) === 0 ? $actor->getKey() : null;
    }

    /**
     * The reason shown when a `NOT NULL` author column can't be resolved
     * (§30-31): spells out that this is about WHOSE account is confirming,
     * not a generic failure — a teacher cloning a colleague's materials
     * into their own organization should read this as "some of what you
     * exported has personal authorship and needs to be restored from your
     * own account", not as an unexplained block. Never names the original
     * author here — the backup's own `exported_by` is provenance about the
     * file, not something this message repeats to a different importer.
     */
    private function unmappableAuthorReason(string $domain): string
    {
        return $this->t(
            'A autoria :domain é obrigatória e só pode ser confirmada com a conta que a criou — inicia sessão com essa conta para o recuperar, ou prossegue sem este registo para clonar apenas os restantes dados.',
            ['domain' => $domain],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  iterable<array{domain: string, ulid: string|null, reason: string}>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function appendRowIssues(array $rows, iterable $issues): array
    {
        foreach ($issues as $issue) {
            $rows[] = ['ulid' => $issue['ulid'], 'classification' => 'invalid', 'reason' => $issue['reason']];
        }

        return $rows;
    }

    /**
     * Whether a scale reference resolves — never its destination id. A
     * scale being newly created in this same backup has no id yet at plan
     * time (§14: the plan never writes anything), so resolving it to an
     * integer here would either be wrong or force the plan to invent one.
     * Rows that need the real id carry the RAW ref forward instead
     * (§6 of the import brief — never a value this layer computes) and
     * `ExecuteDataImport` resolves it against its own live map, built as it
     * actually creates rows.
     *
     * @param  ScaleRef|null  $ref
     * @param  ScaleResolution  $scaleResolution
     * @return array{resolvable: bool}
     */
    private function resolveScaleRef(?array $ref, array $scaleResolution): array
    {
        if ($ref === null) {
            return ['resolvable' => true];
        }

        $refKey = $ref['is_system'] ? "system:{$ref['name']}" : "custom:{$ref['ulid']}";

        return ['resolvable' => $scaleResolution['byRef']->has($refKey)];
    }

    /**
     * @param  ScaleLevelRef|null  $ref
     * @param  ScaleResolution  $scaleResolution
     * @return array{resolvable: bool}
     */
    private function resolveScaleLevelRef(?array $ref, array $scaleResolution): array
    {
        if ($ref === null) {
            return ['resolvable' => true];
        }

        $refKey = $ref['scale']['is_system'] ? "system:{$ref['scale']['name']}" : "custom:{$ref['scale']['ulid']}";
        $match = $scaleResolution['byRef']->get($refKey);

        return ['resolvable' => $match !== null && isset($match['levelCodes'][$ref['code']])];
    }

    /**
     * Same reasoning as {@see resolveScaleRef()}: whether the reference
     * resolves, never its destination id — a custom type created in this
     * same backup has no id yet at plan time.
     *
     * @param  InstrumentTypeRef|null  $ref
     * @param  Collection<string, int>  $byRef
     * @return array{resolvable: bool}
     */
    private function resolveInstrumentTypeRef(?array $ref, Collection $byRef): array
    {
        if ($ref === null) {
            return ['resolvable' => true];
        }

        $refKey = $ref['is_system'] ? "system:{$ref['code']}" : "custom:{$ref['ulid']}";

        return ['resolvable' => $byRef->has($refKey)];
    }
}
