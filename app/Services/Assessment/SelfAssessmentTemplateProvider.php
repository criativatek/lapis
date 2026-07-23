<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessmentTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Provides the self-assessment template for a class (§15). For now it is derived
 * from the class's profile version domains — one 1–5 scale question per domain,
 * so each answer is comparable, on read, with that domain's calculated result.
 * A manual template builder can replace this later without touching the fill flow.
 */
class SelfAssessmentTemplateProvider
{
    public function forClass(SchoolClass $class): SelfAssessmentTemplate
    {
        $existing = SelfAssessmentTemplate::query()
            ->where('class_id', $class->id)
            ->where('is_active', true)
            ->with('questions')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($class): SelfAssessmentTemplate {
            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->id,
                'name' => "Autoavaliação — {$class->subject->name}",
                'is_active' => true,
            ]);

            $version = $class->profileVersion;
            $scale = Scale::where('name', 'Escala 1 a 5')->first();

            if ($version !== null && $scale !== null) {
                $domainIds = $version->domains()->orderBy('id')->pluck('domain_id');
                $domains = Domain::whereIn('id', $domainIds)->orderBy('name')->get();

                foreach ($domains->values() as $index => $domain) {
                    $template->questions()->create([
                        'domain_id' => $domain->id,
                        'prompt' => "Como te avalias em {$domain->name}?",
                        'answer_kind' => 'scale',
                        'scale_id' => $scale->id,
                        'sequence' => $index + 1,
                    ]);
                }
            }

            return $template->load('questions');
        });
    }
}
