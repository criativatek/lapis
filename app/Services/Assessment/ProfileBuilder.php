<?php

namespace App\Services\Assessment;

use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\ProfileVersionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and edits assessment profiles and their draft versions (§10).
 *
 * A profile is created with its first draft version in one transaction. Editing
 * an active profile does not mutate it — it opens a fresh draft copy (§10.2).
 * Weights are not validated to 100 here; that gate belongs to activation
 * (ActivateProfileVersion), because a draft is allowed to be incomplete (§10.3).
 */
class ProfileBuilder
{
    /**
     * @param  array<string, mixed>  $attributes  name, academic_year_id, subject_id, grade_level, description
     * @param  list<array{name: string, weight: float}>  $domains
     */
    public function create(array $attributes, int $scaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($attributes, $scaleId, $domains): AssessmentProfile {
            $profile = AssessmentProfile::create($attributes);

            // organization_id is stamped by the BelongsToOrganization creating
            // hook from the resolved tenant — not passed here (it is not fillable).
            $version = $profile->versions()->create([
                'version_number' => 1,
                'status' => ProfileVersionStatus::Draft,
                'scale_id' => $scaleId,
                'domain_weight_mode' => 'must_total_100',
                'period_result_mode' => 'weighted_domain_average',
                'rounding_scale' => 0,
                'rounding_stage' => 'final_only',
            ]);

            $this->syncDomains($version, (int) $attributes['subject_id'], $domains);

            return $profile;
        });
    }

    /**
     * Edit the profile. If its current version is a frozen active one, a new draft
     * copy is opened; if a draft already exists, that draft is updated in place.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{name: string, weight: float}>  $domains
     */
    public function update(AssessmentProfile $profile, array $attributes, int $scaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($profile, $attributes, $scaleId, $domains): AssessmentProfile {
            $profile->update($attributes);

            $draft = $profile->draftVersion() ?? $this->openNewDraft($profile);

            $draft->update(['scale_id' => $scaleId]);
            $draft->domains()->delete();
            $this->syncDomains($draft, (int) $attributes['subject_id'], $domains);

            return $profile->refresh();
        });
    }

    /**
     * Opens the next draft version, carrying the settings of the current active
     * one forward. This is what "editing" an activated profile actually does.
     */
    protected function openNewDraft(AssessmentProfile $profile): AssessmentProfileVersion
    {
        // Reached only when there is no draft, which for an existing profile means
        // it has been activated — so it always has a current (active) version to
        // carry settings forward from.
        $latest = $profile->versions()->max('version_number') ?? 0;
        $source = $profile->currentVersion;

        return $profile->versions()->create([
            'version_number' => $latest + 1,
            'status' => ProfileVersionStatus::Draft,
            'scale_id' => $source->scale_id,
            'domain_weight_mode' => $source->domain_weight_mode,
            'period_result_mode' => $source->period_result_mode,
            'accumulated_mode' => $source->accumulated_mode,
            'absence_mode' => $source->absence_mode,
            'rounding_mode' => $source->rounding_mode,
            'rounding_scale' => $source->rounding_scale,
            'rounding_stage' => $source->rounding_stage,
            'created_from_version_id' => $source->id,
        ]);
    }

    /**
     * @param  list<array{name: string, weight: float}>  $domains
     */
    protected function syncDomains(AssessmentProfileVersion $version, int $subjectId, array $domains): void
    {
        foreach ($domains as $index => $row) {
            $domain = $this->resolveDomain($subjectId, $row['name'], $index);

            $version->domains()->create([
                'domain_id' => $domain->id,
                'weight_percent' => $row['weight'],
                'sequence' => $index + 1,
            ]);
        }
    }

    /**
     * A domain is a stable identity: find the existing one for this subject by its
     * derived code, or create it. Renaming later fixes the label everywhere without
     * a new version (§2.4).
     */
    protected function resolveDomain(int $subjectId, string $name, int $index): Domain
    {
        $code = Str::upper(Str::slug($name, ''));
        $code = $code === '' ? 'DOM'.($index + 1) : Str::limit($code, 32, '');

        return Domain::firstOrCreate(
            ['subject_id' => $subjectId, 'code' => $code],
            ['name' => $name, 'sequence' => $index + 1],
        );
    }
}
