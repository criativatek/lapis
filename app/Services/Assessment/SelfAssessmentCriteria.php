<?php

namespace App\Services\Assessment;

use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\SchoolClass;
use App\Models\SelfAssessmentTemplate;
use App\Services\Classes\ClassProfileUpdateOffer;
use App\Support\Assessment\SelfAssessmentAnswerScale;
use Illuminate\Support\Facades\Gate;

/**
 * Os critérios com que uma autoavaliação foi construída, ditos ao PROFESSOR.
 *
 * A autoavaliação não tem configuração própria: herda os domínios, as
 * ponderações e a escala da versão do perfil que a turma tinha quando o modelo
 * foi derivado. Este serviço lê-os do modelo — não da turma — e diz, ao lado,
 * o que a turma usa agora e se são a mesma coisa, para que um professor nunca
 * veja uns critérios e preencha outros.
 *
 * Só para o professor. O aluno recebe as perguntas e a escala em que cada uma
 * é feita, e nada sobre perfis nem versões.
 */
class SelfAssessmentCriteria
{
    public function __construct(protected ClassProfileUpdateOffer $offer) {}

    /**
     * @return array<string, mixed>
     */
    public function forTeacher(SchoolClass $class, SelfAssessmentTemplate $template): array
    {
        $template->loadMissing('profileVersion.scale');
        $version = $template->profileVersion;
        $classVersion = $class->profileVersion;

        $canEdit = Gate::allows('update', $class);

        $state = match (true) {
            $version === null && $classVersion === null => 'no_profile',
            $template->assessment_profile_version_id !== $class->assessment_profile_version_id => 'previous',
            default => 'current',
        };

        $offer = $this->offer->for($class);

        return [
            'profile' => $version === null ? null : $this->profileName($version),
            'version' => $version?->version_number,
            'scale' => $version?->scale?->name,
            'domains' => $version === null ? [] : $this->domainsOf($version),
            // How the questions of this self-assessment are answered, on the
            // scale THEY were derived with.
            'answer' => $version === null
                ? null
                : SelfAssessmentAnswerScale::for($version->scale, (int) $version->rounding_scale)->toPayload(),
            'state' => $state,
            'class_criteria' => $classVersion === null ? null : [
                'profile' => $this->profileName($classVersion),
                'version' => $classVersion->version_number,
                'scale' => $classVersion->scale?->name,
            ],
            'saved_count' => $template->selfAssessments()->count(),
            'can_renew' => $state === 'previous' && $canEdit,
            'can_edit' => $canEdit,
            'class_url' => "/classes/{$class->ulid}",
            'review' => $offer === null ? null : [
                'version' => $offer['version_number'],
                'scale' => $offer['scale_name'],
                'url' => $offer['review_url'],
            ],
        ];
    }

    /**
     * The profile may have been archived since: its name is still the name the
     * criteria were filled in under.
     */
    protected function profileName(AssessmentProfileVersion $version): ?string
    {
        return AssessmentProfile::withTrashed()->whereKey($version->assessment_profile_id)->first()?->name;
    }

    /**
     * @return list<array{name: string, weight: string}>
     */
    protected function domainsOf(AssessmentProfileVersion $version): array
    {
        $domains = [];

        foreach ($version->domains()->with('domain')->get() as $profileDomain) {
            $domains[] = [
                'name' => (string) $profileDomain->domain->name,
                'weight' => SelfAssessmentAnswerScale::trimZeros((string) $profileDomain->weight_percent),
            ];
        }

        return $domains;
    }
}
