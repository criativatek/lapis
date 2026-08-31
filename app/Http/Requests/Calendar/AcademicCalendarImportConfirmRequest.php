<?php

namespace App\Http\Requests\Calendar;

use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O equivalente em lote de AcademicYearRequest e de CalendarEventRequest — as
 * mesmas regras, a mesma política, a mesma verificação de inquilino, aplicadas
 * uma vez por linha.
 *
 * NADA DO QUE A PRÉ-VISUALIZAÇÃO DISSE É ACREDITADO AQUI. Ao navegador foram
 * dados ulids para poder desenhar um ecrã, e ele devolve-os; cada um é
 * re-resolvido através do modelo com o seu scope de organização e re-autorizado
 * através da AcademicYearPolicy antes de se escrever seja o que for. Um ano ou um
 * período de outra organização simplesmente não resolve — o scope esconde-o — e
 * por isso a resposta é «não autorizado» e nunca «esse registo não existe», que
 * seria uma forma de perguntar a esta aplicação se um id é real.
 *
 * O PAR DE VERIFICAÇÕES DOS ULIDS É O MESMO DE AcademicYearRequest, e pela mesma
 * razão: a regra de coluna vê que o período é DESTA ORGANIZAÇÃO, e não consegue
 * ver que é DESTE ANO. Sem a segunda metade — feita em after(), onde o ano já
 * está resolvido — uma confirmação podia adotar (e reescrever) o período de um ano
 * vizinho.
 *
 * UMA LINHA RECUSADA RECUSA O LOTE INTEIRO, deliberadamente e como no importador
 * de horários: se a autorização falha numa linha, o payload não é um que este
 * professor pudesse ter produzido a rever a sua própria pré-visualização, e por
 * isso não se escreve nada dele. «Já existe, salta» é OUTRA COISA — é ordinário,
 * é esperado, e é contado linha a linha no resultado (ver o controlador).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE ESTA CLASSE PODE E NÃO PODE GARANTIR SOBRE A ESCOLHA DAS TRÊS DATAS
 *
 * PODE garantir — e garante, em after() — que uma linha de período marcada para
 * gravar traz um par início/fim COMPLETO, bem formado, coerente e dentro do ano
 * letivo. É isso que impede que a linha do 2.º Semestre seja confirmada sem que
 * alguém tenha escolhido uma das três datas: sem escolha, `ends_on` vem vazio e o
 * pedido é recusado com uma frase que o diz.
 *
 * NÃO PODE garantir que a data escolhida seja uma das três do documento, e não
 * finge que pode: a confirmação não tem o ficheiro à frente, e a data que lá
 * chega é indistinguível de uma que o professor escrevesse à mão no formulário do
 * ano letivo — onde teria todo o direito de a escrever. A ambiguidade é do
 * DOCUMENTO e nunca chega à base de dados; o que a base de dados exige é uma data
 * válida, e é isso que se exige aqui.
 */
class AcademicCalendarImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $academicYear = $this->academicYear();

        // Um ulid de outra organização não resolve através do scope do modelo, e
        // um ano inexistente também não. As duas situações respondem o mesmo.
        if ($academicYear === null || ! $user->can('update', $academicYear)) {
            return false;
        }

        foreach ($this->rowsOf('semesters') as $row) {
            $ulid = $row['ulid'] ?? null;

            if (! is_string($ulid) || $ulid === '') {
                continue;
            }

            if (AcademicPeriod::query()->where('ulid', $ulid)->doesntExist()) {
                return false;
            }
        }

        // Os acontecimentos têm dono e política próprios, e por isso a sua própria
        // pergunta: quem importa é quem fica a ser o autor.
        if ($this->hasIncluded('events') && ! $user->can('create', CalendarEvent::class)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year_ulid' => [
                'required', 'string',
                new BelongsToCurrentOrganization(AcademicYear::class, 'ulid'),
            ],

            // As três listas são `sometimes`: uma importação em que o professor só
            // aceitou os feriados não fala de períodos de todo, e não é por isso
            // um pedido malformado.
            'semesters' => ['sometimes', 'array', 'max:50'],
            'semesters.*.include' => ['required', 'boolean'],
            'semesters.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(AcademicPeriod::class, 'ulid')],
            'semesters.*.label' => ['required', 'string', 'max:64'],
            'semesters.*.kind' => ['required', Rule::enum(AcademicPeriodKind::class)],
            'semesters.*.sequence' => ['required', 'integer', 'min:1', 'max:255'],
            // `nullable` AQUI e obrigatório em after() para as linhas marcadas: uma
            // linha por confirmar pode legitimamente vir sem data — é exatamente o
            // que a linha do 2.º Semestre é enquanto ninguém escolher — e recusar o
            // lote inteiro por causa dela seria impedir o professor de gravar as
            // outras quinze linhas por causa da única que deixou em aberto.
            'semesters.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'semesters.*.ends_on' => ['nullable', 'date_format:Y-m-d'],

            'exceptions' => ['sometimes', 'array', 'max:200'],
            'exceptions.*.include' => ['required', 'boolean'],
            'exceptions.*.type' => ['required', Rule::enum(AcademicCalendarExceptionType::class)],
            'exceptions.*.title' => ['required', 'string', 'max:200'],
            'exceptions.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'exceptions.*.ends_on' => ['nullable', 'date_format:Y-m-d'],
            'exceptions.*.note' => ['nullable', 'string', 'max:2000'],
            // `source` NÃO SE VALIDA PORQUE NÃO SE ACEITA — a mesma decisão que
            // AcademicYearRequest já tomou. A proveniência é escrita pelo servidor
            // (sempre «imported» por este caminho) e nunca vem do cliente: senão
            // uma importação podia declarar-se escrita à mão, e a coluna deixava de
            // responder honestamente à pergunta que existe para responder.

            // `max:50` SUBIU PARA 200, a par das exceções. Enquanto tudo o que o
            // documento marcava era escrito como feriado, esta lista tinha três
            // linhas — os fins de coorte — e cinquenta era um teto que ninguém
            // alcançava. Agora é aqui que aterram as reuniões, as atividades e os
            // convívios de um ano inteiro, e cinquenta passou a ser um limite que um
            // calendário real atinge.
            'events' => ['sometimes', 'array', 'max:200'],
            'events.*.include' => ['required', 'boolean'],
            // AS QUATRO ESPÉCIES, e já não só «outro». Deixou de haver uma única
            // espécie possível no momento em que a importação passou a classificar o
            // que lê: recusar aqui uma «Reunião» que a pré-visualização propôs como
            // reunião seria recusar o próprio ecrã anterior. O conjunto continua
            // FECHADO — é o enum e nada mais —, e continua a ser este pedido a
            // decidi-lo e nunca o cliente a declará-lo.
            'events.*.type' => ['required', Rule::enum(CalendarEventType::class)],
            'events.*.title' => ['required', 'string', 'max:200'],
            'events.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'events.*.ends_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $academicYear = $this->academicYear();

            if ($academicYear === null) {
                return;
            }

            $this->assertPeriodUlidsBelongToYear($validator, $academicYear);
            $this->assertIncludedRowsAreComplete($validator, $academicYear);
        });
    }

    /**
     * Pertencer à organização é o que a regra de coluna viu; pertencer a ESTE ANO
     * é o que ela não conseguia ver. Exatamente a segunda metade que
     * AcademicYearRequest faz aos seus `periods.*.ulid`.
     */
    private function assertPeriodUlidsBelongToYear(Validator $validator, AcademicYear $academicYear): void
    {
        $own = $academicYear->periods()->pluck('ulid');

        foreach ($this->rowsOf('semesters') as $index => $row) {
            $ulid = $row['ulid'] ?? null;

            if (is_string($ulid) && $ulid !== '' && ! $own->contains($ulid)) {
                $validator->errors()->add(
                    "semesters.{$index}.ulid",
                    __('O período selecionado não pertence a este ano letivo.'),
                );
            }
        }
    }

    /**
     * Uma linha MARCADA PARA GRAVAR tem de estar completa; uma linha por marcar
     * pode estar como estiver, porque não vai ser escrita.
     *
     * É aqui que a escolha das três datas de fim é exigida de facto: sem escolha,
     * `ends_on` chega vazio e a linha é recusada com uma frase que o professor lê.
     */
    private function assertIncludedRowsAreComplete(Validator $validator, AcademicYear $academicYear): void
    {
        $yearStart = $academicYear->starts_on->toDateString();
        $yearEnd = $academicYear->ends_on->toDateString();

        foreach (['semesters', 'exceptions', 'events'] as $group) {
            foreach ($this->rowsOf($group) as $index => $row) {
                if (! $this->isIncluded($row)) {
                    continue;
                }

                $startsOn = is_string($row['starts_on'] ?? null) ? $row['starts_on'] : null;
                $endsOn = is_string($row['ends_on'] ?? null) ? $row['ends_on'] : null;

                if ($startsOn === null || $endsOn === null) {
                    $validator->errors()->add(
                        "{$group}.{$index}.ends_on",
                        $group === 'semesters'
                            ? __('Escolha a data de fim deste período antes de o confirmar.')
                            : __('Indique as datas de início e de fim antes de confirmar esta linha.'),
                    );

                    continue;
                }

                // Um período TEM de durar mais do que um dia (a CHECK de
                // `academic_periods` exige `ends_on > starts_on`); uma exceção de um
                // dia só é o caso mais comum que existe. A diferença é a mesma que
                // AcademicYearRequest já faz entre `after` e `after_or_equal`.
                $tooShort = $group === 'semesters' ? $endsOn <= $startsOn : $endsOn < $startsOn;

                if ($tooShort) {
                    $validator->errors()->add(
                        "{$group}.{$index}.ends_on",
                        __('A data de fim não pode ser anterior à data de início.'),
                    );

                    continue;
                }

                if ($startsOn < $yearStart || $endsOn > $yearEnd) {
                    $validator->errors()->add(
                        "{$group}.{$index}.starts_on",
                        __('Esta data está fora do ano letivo selecionado.'),
                    );
                }
            }
        }
    }

    /**
     * O ano letivo de destino, re-resolvido através do scope de organização do
     * próprio modelo — nunca o da sessão, e nunca um id acreditado por vir no
     * pedido sem passar por aqui.
     */
    public function academicYear(): ?AcademicYear
    {
        $ulid = $this->input('academic_year_ulid');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return AcademicYear::query()->where('ulid', $ulid)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rowsOf(string $group): array
    {
        $rows = $this->input($group);

        if (! is_array($rows)) {
            return [];
        }

        return array_filter($rows, is_array(...));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isIncluded(array $row): bool
    {
        return filter_var($row['include'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function hasIncluded(string $group): bool
    {
        foreach ($this->rowsOf($group) as $row) {
            if ($this->isIncluded($row)) {
                return true;
            }
        }

        return false;
    }
}
