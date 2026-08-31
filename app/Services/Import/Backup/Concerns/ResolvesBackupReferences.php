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
     * any individual author field on the same footing).
     *
     * STILL NEVER A LOOKUP AGAINST OTHER ACCOUNTS, including members of the
     * destination organization, and the reason is sharper than "we might
     * pick the wrong person". The author email is a value inside an
     * uploaded file, and this application authenticates by email: matching
     * it against a colleague's account would let anyone who can edit a JSON
     * file write pedagogical records signed by a colleague who never wrote
     * them. Forging a colleague's professional record is a far worse
     * outcome than leaving authorship empty, and self-attribution is not
     * forgery — an account can already write its own records.
     *
     * What CHANGED in 0.101.4 is not this function; it is what an
     * unresolved author costs. It used to invalidate the row. It now leaves
     * the author column empty and the record intact — see
     * {@see unresolvedAuthorNotice()}.
     */
    private function resolveAuthor(?string $email, User $actor): ?int
    {
        if ($email === null || $email === '') {
            return null;
        }

        return strcasecmp($email, $actor->email) === 0 ? $actor->getKey() : null;
    }

    /**
     * Shown when an author email belongs to nobody this import may claim.
     *
     * IT IS A NOTICE, NOT A REFUSAL. Until 0.101.4 this text blocked the
     * row: an author that could not be mapped made the whole record
     * `invalid`, so a teacher who had changed email — or a school moving a
     * class between teachers — could not restore their own materials at
     * all. Authorship is historical metadata about a record; it was never
     * a precondition for the record to exist. The row is imported, the
     * author column is left empty, and the message says so in the words a
     * teacher can act on.
     *
     * Never names the original author: the backup's `exported_by` is
     * provenance about the FILE, and a colleague's email is not something
     * this importer repeats into an organization that has no relationship
     * with them (§22.4).
     */
    private function unresolvedAuthorNotice(string $domain): string
    {
        return $this->t(
            'A autoria original :domain não pôde ser associada à conta atual. Pode continuar a importação — o registo é clonado sem atribuir indevidamente a autoria à conta atual.',
            ['domain' => $domain],
        );
    }

    /**
     * The notice for a decision that arrives confirmed by an account this
     * import cannot resolve (§13.3, §3.3 — the teacher decides).
     *
     * The alternative was to keep `status = confirmed` with an empty
     * `confirmed_by`, which the database refuses outright
     * (`classifications_confirmed_has_author_check`) and which would be a
     * lie either way: nobody in this installation confirmed it. So the
     * grade the teacher recorded is restored in full and only the
     * CONFIRMATION step is handed back to them.
     */
    private function unconfirmableDecisionNotice(): string
    {
        return $this->t(
            'Esta classificação estava confirmada por uma conta que não pôde ser associada. Os valores são importados tal como estavam, mas fica por confirmar — a confirmação é uma decisão sua.',
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
