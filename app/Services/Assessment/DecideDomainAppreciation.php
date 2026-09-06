<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\DomainAppreciationDecision;
use App\Models\Enrollment;
use App\Models\ProfileVersionDomain;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\DomainDecisionException;
use Illuminate\Support\Facades\DB;

/**
 * A ESCRITA de uma apreciação por domínio: o professor a dizer, sobre UM
 * domínio, algo diferente do que o Lapispro propôs.
 *
 * O QUE ESTE SERVIÇO NÃO FAZ é o que o torna seguro. Não recalcula nada, não
 * toca no quantitativo, não reescreve a proposta e não altera a classificação
 * global. 49% continuam 49%, NS continua a ser a proposta, e o que muda é
 * apenas a leitura que o professor assume (§3.3, §13.3).
 *
 * REEDITÁVEL SEMPRE. Não há aqui estado «fechado»: guardar a pauta, exportar
 * para o Inovar ou ter fotografias no histórico não fecham porta nenhuma —
 * essas são cópias do que era verdade num momento, e mudar a pauta de hoje não
 * as toca. A única porta que se fecha é a da autorização, e essa é verificada
 * antes de aqui se chegar.
 *
 * TUDO É VERIFICADO CONTRA A CONFIGURAÇÃO REAL DA TURMA. O domínio tem de ser
 * do perfil ativo, o nível tem de ser da escala desse perfil, e a matrícula tem
 * de ser desta turma — três perguntas que o browser não responde por nós.
 */
class DecideDomainAppreciation
{
    public function __construct(
        protected AuditLog $audit,
    ) {}

    /**
     * Escreve a decisão, ou apaga-a quando o professor volta à proposta.
     *
     * `$scaleLevelId` a null é «voltar à proposta do Lapispro» — e é por isso
     * que apaga a linha em vez de guardar um nível nulo: a ausência de decisão
     * é o estado natural de um domínio que ninguém reviu, e um estado que dissesse
     * «decidiu não decidir» seria uma terceira coisa que a pauta não sabe mostrar.
     */
    public function decide(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        Enrollment $enrollment,
        Domain $domain,
        ?int $scaleLevelId,
        User $teacher,
    ): ?DomainAppreciationDecision {
        $scale = $this->scaleFor($class);

        $this->guardDomain($class, $domain);

        $level = $scaleLevelId === null ? null : $this->levelOnScale($scale, $scaleLevelId);

        return DB::transaction(function () use ($period, $scope, $enrollment, $domain, $level, $teacher): ?DomainAppreciationDecision {
            // Sob bloqueio de linha: dois professores da mesma turma a decidir o
            // mesmo domínio ao mesmo tempo escreveriam por cima um do outro, e o
            // segundo apagaria o rasto do primeiro sem ninguém dar por isso.
            $existing = DomainAppreciationDecision::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('academic_period_id', $period->getKey())
                ->where('scope', $scope)
                ->where('domain_id', $domain->getKey())
                ->lockForUpdate()
                ->first();

            $previous = $existing?->scaleLevel;

            if ($level === null) {
                if ($existing === null) {
                    return null;
                }

                $existing->delete();

                $this->audit->record(
                    'domain-appreciation.cleared',
                    $enrollment,
                    $teacher,
                    "Apreciação de «{$domain->name}» devolvida à proposta do Lapispro.",
                    [
                        'academic_period_id' => (int) $period->getKey(),
                        'scope' => $scope->value,
                        'domain_id' => (int) $domain->getKey(),
                        'previous_scale_level_id' => $previous?->id,
                        'previous_scale_level_code' => $previous?->code,
                    ],
                );

                return null;
            }

            if ($existing !== null) {
                $existing->fill([
                    'scale_level_id' => $level->id,
                    'decided_by' => $teacher->getKey(),
                ])->save();

                $decision = $existing;
            } else {
                $decision = DomainAppreciationDecision::create([
                    'enrollment_id' => $enrollment->getKey(),
                    'academic_period_id' => $period->getKey(),
                    'scope' => $scope,
                    'domain_id' => $domain->getKey(),
                    'scale_level_id' => $level->id,
                    'decided_by' => $teacher->getKey(),
                ]);
            }

            // Auditoria (§22.5), com a mesma gramática das decisões globais: o
            // que estava antes, o que passou a estar, e sobre o quê.
            $this->audit->record(
                $previous === null ? 'domain-appreciation.decided' : 'domain-appreciation.redecided',
                $enrollment,
                $teacher,
                $previous === null
                    ? "Apreciação de «{$domain->name}» decidida em {$level->code} — {$level->label}."
                    : "Apreciação de «{$domain->name}» alterada de {$previous->code} — {$previous->label} para {$level->code} — {$level->label}.",
                [
                    'academic_period_id' => (int) $period->getKey(),
                    'scope' => $scope->value,
                    'domain_id' => (int) $domain->getKey(),
                    'previous_scale_level_id' => $previous?->id,
                    'previous_scale_level_code' => $previous?->code,
                    'scale_level_id' => $level->id,
                    'scale_level_code' => $level->code,
                ],
            );

            return $decision;
        });
    }

    /**
     * O domínio tem de pertencer ao perfil ATIVO desta turma.
     *
     * `profile_version_domains` não tem `organization_id` — é alcançada apenas
     * pelo `assessment_profile_version_id` — mas o `Domain` tem, e o âmbito
     * global já garantiu que este é da organização corrente antes de aqui
     * chegar. O que falta perguntar é se é deste perfil.
     */
    protected function guardDomain(SchoolClass $class, Domain $domain): void
    {
        if ($class->assessment_profile_version_id === null) {
            throw DomainDecisionException::withoutProfile();
        }

        $belongs = ProfileVersionDomain::query()
            ->where('assessment_profile_version_id', $class->assessment_profile_version_id)
            ->where('domain_id', $domain->getKey())
            ->exists();

        if (! $belongs) {
            throw DomainDecisionException::domainNotInProfile();
        }
    }

    /** Um nível DESTA escala. Outro qualquer não é uma apreciação desta turma. */
    protected function levelOnScale(?Scale $scale, int $scaleLevelId): ScaleLevel
    {
        if ($scale === null) {
            throw DomainDecisionException::withoutProfile();
        }

        if ($scale->levels->isEmpty()) {
            throw DomainDecisionException::scaleWithoutLevels();
        }

        $level = ScaleLevel::query()
            ->where('scale_id', $scale->getKey())
            ->whereKey($scaleLevelId)
            ->first();

        if ($level === null) {
            throw DomainDecisionException::levelNotOnScale();
        }

        return $level;
    }

    protected function scaleFor(SchoolClass $class): ?Scale
    {
        return $class->profileVersion?->scale()->with('levels')->first();
    }
}
