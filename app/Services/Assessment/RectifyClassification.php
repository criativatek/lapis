<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Organization;
use App\Models\RectificationReason;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Assessment\RectificationAccess;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Correcting a classification that was already published (spec §5, §6).
 *
 * A published decision is never edited: it was communicated, and everything
 * that read it read that value. Rectifying writes a NEW row, already published,
 * and turns the old one into «superseded», pointing at its successor. The pair
 * stays in the table as the history; every reader already looks only at the live
 * row, so nothing but this service has to know that two exist.
 *
 * It changes the DECISION and nothing it was derived from: no instrument, score,
 * scale, criterion or profile is touched, and the proposal and the snapshot that
 * explained it are copied to the new row as they were. An element «under review»
 * does not block this, as it blocks publication — rectifying is precisely how a
 * review ends up in a different grade.
 *
 * THE ORDER OF THE WRITES IS NOT A CHOICE. The table refuses both orders that
 * look natural: marking the old row superseded without a successor breaks the
 * pointer CHECK (`classifications_superseded_pointer_check`), and inserting the
 * new one first breaks the «one live row per cell» unique index. So the old row
 * is released with a TEMPORARY pointer to itself — which satisfies the CHECK and
 * takes it out of the index —, the new row goes in, and the pointer is then
 * moved to its real target. The self-pointer never outlives the transaction.
 */
class RectifyClassification
{
    public function __construct(
        protected DecisionResolver $decisions,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  int|null  $finalScaleLevelId  the level now assigned, on a scale made of levels
     * @param  string|null  $finalValue  the classification now assigned, on a scale that is an interval
     * @param  int  $expectedLockVersion  the version the screen showed; a different one means someone got there first
     * @param  bool  $closedConfirmed  the extra confirmation a closed period asks for
     * @return Classification the NEW row, which is now the live one
     */
    public function rectify(
        Classification $classification,
        User $actor,
        RectificationReason $reason,
        ?string $note,
        ?int $finalScaleLevelId,
        ?string $finalValue,
        int $expectedLockVersion,
        bool $closedConfirmed,
    ): Classification {
        // THE CLASSIFICATION'S ORGANIZATION, NOT THE REQUEST'S. A user may act
        // from another of their organizations (or from none, in a command), and
        // the relations the resolver walks — enrollment, class, scale — are all
        // tenant-scoped. Running as the row's own tenant makes the outcome the
        // same whatever tenant happened to be current, and stamps the audit
        // event with the right one. `Organization` itself is not scoped.
        $organization = Organization::query()->findOrFail($classification->organization_id);

        return app(CurrentOrganization::class)->runFor($organization, fn (): Classification => $this->run(
            $classification, $actor, $reason, $note, $finalScaleLevelId, $finalValue, $expectedLockVersion, $closedConfirmed,
        ));
    }

    protected function run(
        Classification $classification,
        User $actor,
        RectificationReason $reason,
        ?string $note,
        ?int $finalScaleLevelId,
        ?string $finalValue,
        int $expectedLockVersion,
        bool $closedConfirmed,
    ): Classification {
        // Everything is decided on the LOCKED row, inside the transaction: two
        // people rectifying the same grade at once would otherwise both pass the
        // checks and the second would overwrite the first's correction. The
        // second one waits here and then finds the row already superseded.
        return DB::transaction(function () use ($classification, $actor, $reason, $note, $finalScaleLevelId, $finalValue, $expectedLockVersion, $closedConfirmed): Classification {
            $locked = Classification::query()->whereKey($classification->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ClassificationStatus::Published) {
                // A superseded row is also «not published» in the sense that
                // matters, but the useful answer is that someone rectified it.
                throw $locked->status === ClassificationStatus::Superseded
                    ? ClassificationDecisionException::alreadyRectified()
                    : ClassificationDecisionException::notPublished();
            }

            if ($locked->superseded_by_id !== null || $locked->lock_version !== $expectedLockVersion) {
                throw ClassificationDecisionException::alreadyRectified();
            }

            // Re-checked on the locked row, not trusted from the request: the
            // period may have closed, or the user stopped teaching the class,
            // since the dialog was opened.
            $access = RectificationAccess::for($actor, $locked);

            if (! $access->allowed) {
                throw ClassificationDecisionException::rectificationNotAllowed();
            }

            if ($access->needsConfirmation && ! $closedConfirmed) {
                throw ClassificationDecisionException::closedNeedsConfirmation();
            }

            $written = $note === null || trim($note) === '' ? null : trim($note);

            if ($reason->requiresNote() && $written === null) {
                throw ClassificationDecisionException::noteRequired();
            }

            // With nothing indicated the resolver answers «use the proposal».
            // That is how a first decision is made and not how one is revised:
            // a correction must state the value it corrects TO.
            if ($finalScaleLevelId === null && ($finalValue === null || trim($finalValue) === '')) {
                throw ClassificationDecisionException::decisionRequired();
            }

            $decision = $this->decisions->resolve($locked, $finalScaleLevelId, $finalValue);

            if ($this->sameDecision($locked, $decision)) {
                throw ClassificationDecisionException::unchangedValue();
            }

            $previous = [
                'readable' => $this->decisionReadable($locked),
                'final_value' => $locked->final_value,
                'final_scale_level_id' => $locked->final_scale_level_id,
                'ulid' => $locked->ulid,
                'published_at' => $locked->published_at?->toIso8601String(),
            ];

            // (a) Release the old row: superseded, with the temporary pointer.
            $locked->forceFill([
                'status' => ClassificationStatus::Superseded,
                'superseded_by_id' => $locked->getKey(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            // (b) The new row, already published. `organization_id` is set
            // explicitly — and `forceFill`, because it is not mass-assignable —
            // so BelongsToOrganization leaves it alone: it only fills the
            // attribute when it is absent.
            $now = now();
            $new = (new Classification)->forceFill([
                'organization_id' => $locked->organization_id,
                'enrollment_id' => $locked->enrollment_id,
                'academic_period_id' => $locked->academic_period_id,
                'scope' => $locked->scope,
                'assessment_profile_version_id' => $locked->assessment_profile_version_id,
                // The proposal, and the snapshot that explains it, are what the
                // old decision was taken against; they do not change because the
                // decision did.
                'calculation_snapshot_id' => $locked->calculation_snapshot_id,
                'proposed_normalized_value' => $locked->proposed_normalized_value,
                'proposed_value' => $locked->proposed_value,
                'proposed_scale_level_id' => $locked->proposed_scale_level_id,
                'status' => ClassificationStatus::Published,
                'final_scale_level_id' => $decision['scale_level_id'],
                'final_value' => $decision['value'],
                // Always written: the table requires a reason on any decision
                // that differs from the proposal, and the rectification's own
                // note, or failing that its reason, is the honest one.
                'override_reason' => $written ?? $reason->label(),
                'overridden_by' => $decision['is_override'] ? $actor->getKey() : null,
                'overridden_at' => $decision['is_override'] ? $now : null,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => $now,
                'published_at' => $now,
                'rectifies_id' => $locked->getKey(),
                'rectification_reason' => $reason,
                'rectification_note' => $written,
                'rectified_by' => $actor->getKey(),
                'rectified_at' => $now,
                'lock_version' => 0,
            ]);
            $new->save();

            // (c) The pointer takes its real value; the self-pointer is gone.
            $locked->forceFill(['superseded_by_id' => $new->getKey()])->save();

            $this->audit->record(
                'classification.rectified',
                $new,
                $actor,
                "Classificação retificada de {$previous['readable']} para {$decision['readable']}.",
                [
                    'previous_final_value' => $previous['final_value'],
                    'previous_final_scale_level_id' => $previous['final_scale_level_id'],
                    'previous_classification_ulid' => $previous['ulid'],
                    'previous_published_at' => $previous['published_at'],
                    'final_value' => $new->final_value,
                    'final_scale_level_id' => $new->final_scale_level_id,
                    'reason' => $reason->value,
                    'note' => $written,
                    'period_closed' => $access->closed,
                ],
            );

            return $new;
        });
    }

    /**
     * Whether the decision asked for is the one already on the row. A level is
     * compared by id; a value NUMERICALLY, because «15» and «15.000» are the same
     * classification and the column hands back three decimals.
     *
     * @param  array{scale_level_id: int|null, value: string|null, is_override: bool, readable: string}  $decision
     */
    protected function sameDecision(Classification $locked, array $decision): bool
    {
        if ($decision['scale_level_id'] !== null || $locked->final_scale_level_id !== null) {
            return $decision['scale_level_id'] === $locked->final_scale_level_id;
        }

        if ($decision['value'] === null || $locked->final_value === null) {
            return false;
        }

        return Bc::compare(Bc::of($decision['value']), Bc::of((string) $locked->final_value)) === 0;
    }

    /** The decision currently on the row, as a teacher reads it. */
    protected function decisionReadable(Classification $classification): string
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null) {
            return "{$level->code} — {$level->label}";
        }

        if ($classification->final_value === null) {
            return '—';
        }

        // «15.000» is how the column stores it, not how anyone says it.
        $value = (string) $classification->final_value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
