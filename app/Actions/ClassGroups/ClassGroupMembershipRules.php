<?php

namespace App\Actions\ClassGroups;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * As invariantes que TODAS as ações sobre pertenças partilham, ditas uma vez.
 *
 * Não é uma classe de conveniência: cada uma destas verificações é uma recusa
 * que o professor tem de conseguir ler, e escrevê-las em cada ação faria com
 * que a terceira delas dissesse a mesma coisa por outras palavras. As ações
 * chamam-nas DENTRO da transação, depois do lock e antes de escrever — a ordem
 * que impede que duas mudanças simultâneas passem as duas.
 *
 * `exists:` NÃO APARECE AQUI NEM NOS FORM REQUESTS que chamam estas ações: as
 * três entidades (grupo, inscrição, turma) chegam já resolvidas pelo global
 * scope da organização, ou por `new BelongsToCurrentOrganization(...)`. O que
 * falta verificar — e é o que está aqui — é que elas falam todas da MESMA
 * turma, coisa que nenhuma regra de validação sabe.
 */
class ClassGroupMembershipRules
{
    /**
     * O grupo e a inscrição são da mesma turma, e da mesma organização.
     *
     * A comparação de `organization_id` é redundante enquanto ambos vierem do
     * global scope — e está aqui na mesma, porque uma ação chamada de um
     * comando, de um seeder ou de um job entra no tenant por
     * CurrentOrganization::runFor() e nada garante que quem a escreveu tenha
     * passado as duas entidades certas.
     */
    public function assertSameClass(ClassGroup $classGroup, Enrollment $enrollment): void
    {
        if ($classGroup->class_id !== $enrollment->class_id
            || $classGroup->organization_id !== $enrollment->organization_id) {
            throw ValidationException::withMessages([
                'class_group_id' => __('O grupo e o aluno têm de pertencer à mesma turma.'),
            ]);
        }
    }

    /**
     * Um grupo arquivado é história que se lê, não um sítio onde se põe gente.
     */
    public function assertAcceptsMembers(ClassGroup $classGroup): void
    {
        if ($classGroup->isArchived()) {
            throw ValidationException::withMessages([
                'class_group_id' => __('O grupo :label está arquivado e não aceita novos alunos.', [
                    'label' => $classGroup->label,
                ]),
            ]);
        }
    }

    /**
     * A pertença em vigor de uma inscrição nesta turma, naquele dia — ou null
     * se nesse dia o aluno não estava em grupo nenhum.
     *
     * Filtrada pela TURMA e não só pela inscrição por precaução barata: uma
     * inscrição pertence a uma turma só, mas a pergunta que esta função
     * responde é sempre feita no contexto de uma turma, e escrevê-la assim
     * torna-a verdadeira mesmo que um dia isso deixe de ser garantido.
     */
    public function membershipInVigor(Enrollment $enrollment, string $date): ?ClassGroupMembership
    {
        /** @var ClassGroupMembership|null $membership */
        $membership = ClassGroupMembership::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereHas('classGroup', fn ($query) => $query->where('class_id', $enrollment->class_id))
            ->inVigorOn($date)
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();

        return $membership;
    }

    /**
     * Nenhuma pertença desta inscrição pode começar NA data em que a mudança
     * entra em vigor, ou depois dela.
     *
     * É esta a verificação que impede uma sobreposição, e não uma CHECK: fechar
     * a pertença atual em `data - 1` e abrir outra em `data` só produz um
     * calendário coerente se não houver já uma terceira linha mais à frente. É
     * também a razão pela qual as ações não deixam editar o passado a meio:
     * mexer numa janela intermédia obrigaria a reescrever as seguintes, e
     * reescrever história é exatamente o que esta funcionalidade existe para
     * não fazer.
     */
    public function assertNothingStartsOnOrAfter(Enrollment $enrollment, string $effectiveFrom): void
    {
        $later = ClassGroupMembership::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereHas('classGroup', fn ($query) => $query->where('class_id', $enrollment->class_id))
            ->whereDate('effective_from', '>=', $effectiveFrom)
            ->exists();

        if ($later) {
            throw ValidationException::withMessages([
                'effective_from' => __('Já existe uma alteração de grupo registada nessa data ou depois dela. Reveja o histórico do aluno primeiro.'),
            ]);
        }
    }

    /**
     * A data em que a pertença inicial de uma inscrição começa.
     *
     * `max(início do ano letivo, entrada do aluno)` (§4 do briefing), e nunca
     * pedida ao professor: uma matrícula tardia não pode dizer que o aluno
     * esteve em T1 desde setembro, e um aluno que entrou em setembro não
     * precisa de uma data escrita à mão para dizer o óbvio.
     */
    public function initialEffectiveFrom(Enrollment $enrollment, string $academicYearStartsOn): string
    {
        $enrolledOn = $enrollment->enrolled_on->toDateString();

        return $enrolledOn > $academicYearStartsOn ? $enrolledOn : $academicYearStartsOn;
    }

    /**
     * A data pedida ao professor tem de cair dentro do ano letivo da turma.
     *
     * PODE SER NO PASSADO, ao contrário do `effective_from` de um tempo do
     * horário. A diferença não é de gosto: rever um slot para trás reescreveria
     * o que o horário FOI, enquanto uma mudança de grupo não toca em nenhuma
     * linha de `lessons` — o grupo de cada aula é um instantâneo guardado na
     * própria aula. Um professor que só em dezembro regista que o aluno passou
     * para T2 a 15 de novembro está a corrigir o registo para a realidade, e é
     * a realidade que interessa.
     */
    public function assertWithinAcademicYear(string $effectiveFrom, string $startsOn, string $endsOn): void
    {
        if ($effectiveFrom < $startsOn || $effectiveFrom > $endsOn) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data tem de ficar dentro do ano letivo da turma (:from a :to).', [
                    'from' => $startsOn,
                    'to' => $endsOn,
                ]),
            ]);
        }
    }

    /**
     * Uma pertença não pode ser fechada no dia em que começou, nem antes: o
     * intervalo resultante seria `effective_until < effective_from`, que a
     * própria CHECK da tabela recusa. A mensagem aqui é a versão legível disso
     * — a mesma regra, e pela mesma razão, que RecurringLessonSlotRequest já
     * escreve para o `starts_on` de um tempo do horário.
     */
    public function assertClosesAfterItStarted(ClassGroupMembership $membership, string $effectiveFrom): void
    {
        $startedOn = $membership->effective_from->toDateString();

        if ($effectiveFrom <= $startedOn) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data tem de ser posterior ao início da pertença atual (:date).', [
                    'date' => $startedOn,
                ]),
            ]);
        }
    }

    public function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }
}
