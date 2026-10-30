<?php

namespace App\Actions\Lessons;

/**
 * O resultado de uma reconciliação com o calendário — o mesmo objeto na
 * pré-visualização e na execução. SÓ ESCALARES E LISTAS (nunca modelos): a
 * pré-visualização corre numa transação que reverte, e um modelo que lá
 * nasceu não existiria depois dela.
 *
 * NUNCA CONTÉM TEXTO PEDAGÓGICO. Os planeamentos que se deslocam aparecem pelos
 * ULIDs e datas das aulas de origem e destino e pelos NOMES dos campos que
 * viajam; o conteúdo viaja só na escrita, e na impressão digital (`token`)
 * apenas como hash.
 *
 * `review` conta as aulas em revisão por razão (códigos e nomes dos outros
 * grupos de uma lição partilhada); `planning` (só no âmbito do professor) diz
 * onde fica cada planeamento no fim — o das aulas retiradas e o que avança.
 *
 * @phpstan-type ClassRow array{class_id: int, class_label: string, retired: int, shifted: int, awaiting_teacher: int, flagged: int, renumbered: int, gaps: list<int>, review: list<array{reasons: list<string>, partners: list<string>, count: int}>, planning: list<array{origin: string, from_starts_at: string, to_starts_at: string|null, new_lesson: bool, pending: bool}>|null, lessons: list<array<string, mixed>>|null}
 */
final class CalendarReconciliationPlan
{
    /**
     * @param  list<int>  $exceptionIds  as exceções que causam conflitos
     * @param  list<array<string, mixed>>  $classes  uma entrada por turma com conflitos (ver ClassRow)
     * @param  array{retired: int, shifted: int, awaiting_teacher: int, flagged: int}  $totals
     *                                                                                          `retired`: A retiradas; `shifted`: B deslocadas (e retiradas);
     *                                                                                          `awaiting_teacher`: B que só o professor desloca (âmbito do
     *                                                                                          responsável); `flagged`: C, em «conflito com o calendário»
     * @param  array<int, list<int>>  $gaps  class_id => números que ficam sem aula
     * @param  list<string>  $freedDays  dias que voltam a ser letivos (eliminar/encurtar uma exceção)
     * @param  array{count: int, lessons: list<array<string, mixed>>|null}  $existingOnFreedDays  aulas que já existam nesses dias (informativo)
     */
    public function __construct(
        public readonly string $token,
        public readonly string $scopeKind,
        public readonly ?string $operation,
        public readonly array $exceptionIds,
        public readonly array $classes,
        public readonly array $totals,
        public readonly array $gaps,
        public readonly array $freedDays,
        public readonly array $existingOnFreedDays,
    ) {}

    /**
     * «Não há aulas a corrigir» sem calcular nada — a resposta para uma
     * organização sem o módulo «Aulas e Sumários» com escrita, onde não há
     * aulas que a reconciliação possa tocar. O token não serve para executar:
     * a gravação com `reconcile` é recusada antes de o ler.
     */
    public static function nothing(string $scopeKind, ?string $operation): self
    {
        return new self(
            token: hash('sha256', 'calendar-reconciliation:nothing'),
            scopeKind: $scopeKind,
            operation: $operation,
            exceptionIds: [],
            classes: [],
            totals: ['retired' => 0, 'shifted' => 0, 'awaiting_teacher' => 0, 'flagged' => 0],
            gaps: [],
            freedDays: [],
            existingOnFreedDays: ['count' => 0, 'lessons' => null],
        );
    }

    /** Há alguma escrita a fazer (ou feita)? */
    public function changesAnything(): bool
    {
        return $this->totals['retired'] + $this->totals['shifted'] > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_token' => $this->token,
            'scope' => $this->scopeKind,
            'operation' => $this->operation,
            'exception_ids' => $this->exceptionIds,
            'classes' => $this->classes,
            'totals' => $this->totals,
            'gaps' => $this->gaps,
            'freed_days' => $this->freedDays,
            'existing_on_freed_days' => $this->existingOnFreedDays,
        ];
    }
}
