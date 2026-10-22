<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mudar de estado, de uma só vez, todas as medidas de uma turma que estão num
 * estado de origem — «Marcar todas como Em curso» (Planeada → Em curso) e
 * «Concluir todas as medidas» (Em curso → Concluída).
 *
 * O ÂMBITO É A TURMA INTEIRA, não a lista filtrada. A turma pertence a um só
 * ano letivo e a organização é o escopo global do modelo, por isso turma +
 * ano + organização são fechados pela própria consulta; os filtros da página
 * são uma conveniência de leitura e nunca decidem o que um lote altera.
 *
 * O SERVIDOR RECALCULA SEMPRE. O que o professor viu na pré-visualização é uma
 * fotografia; aqui as linhas elegíveis são lidas outra vez, bloqueadas, dentro
 * da transação. Uma medida que entretanto mudou de estado deixou de ser
 * elegível e é simplesmente ignorada — por isso repetir o pedido (duplo clique,
 * reenvio) não repete eventos: a segunda execução já não encontra nada.
 *
 * Cada alteração passa por ChangeInterventionStatus, o mesmo mecanismo do botão
 * individual. Os acompanhamentos (reviews) existentes não são tocados.
 */
class BulkChangeInterventionStatus
{
    /** Os pares de estados que o lote admite: origem => destino. */
    public const TRANSITIONS = [
        'new' => 'in_progress',
        'in_progress' => 'concluded',
    ];

    public function __construct(private readonly ChangeInterventionStatus $change) {}

    public static function targetOf(InterventionStatus $from): ?InterventionStatus
    {
        $target = self::TRANSITIONS[$from->value] ?? null;

        return $target === null ? null : InterventionStatus::from($target);
    }

    /**
     * O que um lote tocaria agora: quantas medidas e que alunos (matrículas).
     *
     * Uma medida da turma inteira abrange todos os alunos ativos; as de aluno
     * ou de grupo, os seus participantes.
     *
     * @return array{measures: int, enrollment_ids: list<int>}
     */
    public function preview(SchoolClass $class, InterventionStatus $from): array
    {
        $interventions = $this->eligible($class, $from)->with('participants:id')->get();

        return $this->summarise($class, $interventions->all());
    }

    /**
     * @return array{changed: int, measures: int, enrollment_ids: list<int>}
     */
    public function apply(SchoolClass $class, InterventionStatus $from, User $user): array
    {
        $to = self::targetOf($from) ?? throw new \InvalidArgumentException('Estado de origem sem lote.');

        return DB::transaction(function () use ($class, $from, $to, $user): array {
            // lockForUpdate: dois pedidos em simultâneo esperam um pelo outro e
            // o segundo lê, já confirmado, o estado que o primeiro deixou.
            $interventions = $this->eligible($class, $from)->lockForUpdate()->with('participants:id')->get();

            foreach ($interventions as $intervention) {
                $was = $this->change->transition($intervention, $to, $user);
                $this->change->audit($intervention, $was, $to, $user, ['bulk' => true]);
            }

            return ['changed' => $interventions->count(), ...$this->summarise($class, $interventions->all())];
        });
    }

    /** @return Builder<Intervention> */
    private function eligible(SchoolClass $class, InterventionStatus $from): Builder
    {
        return Intervention::query()
            ->forClass($class->id)
            ->where('status', $from->value)
            ->orderBy('id');
    }

    /**
     * @param  array<int, Intervention>  $interventions
     * @return array{measures: int, enrollment_ids: list<int>}
     */
    private function summarise(SchoolClass $class, array $interventions): array
    {
        $enrollmentIds = [];
        $classWide = false;

        foreach ($interventions as $intervention) {
            if ($intervention->target_type === InterventionTargetType::SchoolClass) {
                $classWide = true;
            }

            foreach ($intervention->participants as $participant) {
                $enrollmentIds[$participant->getKey()] = true;
            }
        }

        if ($classWide) {
            foreach ($class->activeEnrollments()->pluck('id') as $id) {
                $enrollmentIds[$id] = true;
            }
        }

        $ids = array_map('intval', array_keys($enrollmentIds));
        sort($ids);

        return ['measures' => count($interventions), 'enrollment_ids' => $ids];
    }
}
