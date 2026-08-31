<?php

namespace App\Services\Import\AcademicCalendar;

use App\Models\AcademicCalendarExceptionType;
use App\Models\CalendarEventType;
use App\Services\AcademicCalendar\Holidays\NationalHolidayProviders;

/**
 * O que é, de facto, um dia com nome escrito na grelha do calendário da escola.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PORQUE É QUE ISTO EXISTE
 *
 * Até aqui não existia: tudo o que sobrasse dos filtros da grelha era escrito
 * como FERIADO, e «feriado» era o valor por omissão de uma pergunta que ninguém
 * chegava a fazer. Um calendário escolar não traz só feriados — traz reuniões,
 * apresentações, atividades, convívios, entregas — e cada um deles ia parar a
 * `academic_calendar_exceptions`, que é a tabela cuja única afirmação é «neste
 * dia NÃO HÁ AULA».
 *
 * O erro não era de rótulo, era de consequência: uma «Reunião de avaliação»
 * importada apagava as aulas desse dia. Uma classificação que não se sabe fazer
 * nunca pode ser a mais destrutiva das três.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A ORDEM DAS PROVAS, DA MAIS FORTE PARA A MAIS FRACA
 *
 *   1. O QUE O DOCUMENTO ESCREVE. «Feriado municipal» diz-se feriado a si
 *      próprio; «Reunião de avaliação» diz-se reunião. É classificação
 *      explícita do ficheiro e ganha a tudo o resto (§4).
 *
 *   2. O QUE A LEI DIZ DAQUELA DATA. 25 de dezembro é feriado nacional em
 *      Portugal por lei, e não por palpite — o mesmo NationalHolidayProvider que
 *      a sugestão de feriados já usa responde a isto. É um facto sobre a DATA e
 *      não uma leitura do texto, e por isso não tem ambiguidade nenhuma para
 *      resolver. Só responde para o país do ano letivo, e devolve `null` — nunca
 *      Portugal por omissão — para qualquer outro.
 *
 *   3. O QUE O DOCUMENTO PINTA. A folha de referência pinta os seus treze
 *      feriados de laranja forte, e essa cor é a classificação que a escola lhes
 *      deu: é assim que o «Dia de Leiria», feriado municipal que provider nenhum
 *      pode conhecer, continua a ser lido como feriado. Ver `isHolidayFill()`.
 *
 *   4. NADA. E «nada» responde-se com o tipo NEUTRO — um acontecimento de
 *      calendário «Data relevante» —, nunca com feriado. É esta linha que a
 *      correção inteira existe para escrever.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE NÃO SE ADIVINHA (§1, §4)
 *
 * As expressões reconhecidas em (1) são poucas de propósito e todas de
 * ambiguidade baixa: «reunião», «visita de estudo», «convívio», «atividade» sem
 * ser «atividade letiva». «Apresentação» NÃO está cá e não vai estar — uma
 * «apresentação dos alunos» tanto é o primeiro dia de aulas como um sarau —, e
 * uma palavra frágil classificada à sorte é exatamente o que produziu o
 * problema que isto corrige. O que não se sabe fica «Data relevante», à vista e
 * por confirmar, e o professor decide (§3.3).
 */
class ClassifyCalendarDay
{
    /**
     * As datas dos feriados nacionais já pedidas, por «PAÍS|ano civil». Uma
     * grelha tem umas centenas de células e todas caem em dois anos civis:
     * sem isto, o mesmo provider era construído e percorrido uma vez por dia
     * lido.
     *
     * @var array<string, array<string, true>>
     */
    private array $nationalHolidayDates = [];

    public function __construct(private readonly NationalHolidayProviders $providers) {}

    /**
     * @param  string  $label  o nome escrito na célula, já limpo do número do dia
     * @param  string  $date  «Y-m-d»
     * @param  string|null  $countryCode  o `country_code` do ano letivo de destino (§16)
     * @param  bool  $highlighted  a célula está pintada como o documento pinta os feriados
     */
    public function classify(
        string $label,
        string $date,
        ?string $countryCode,
        bool $highlighted,
    ): AcademicCalendarExceptionType|CalendarEventType {
        $explicit = $this->fromLabel($label);

        if ($explicit !== null) {
            return $explicit;
        }

        if ($this->isNationalHoliday($date, $countryCode)) {
            return AcademicCalendarExceptionType::Holiday;
        }

        if ($highlighted) {
            return AcademicCalendarExceptionType::Holiday;
        }

        // O NEUTRO, e nunca o feriado. Ver o cabeçalho.
        return CalendarEventType::Other;
    }

    /**
     * A palavra do próprio documento, quando ela lá está sem margem para dúvida.
     */
    private function fromLabel(string $label): AcademicCalendarExceptionType|CalendarEventType|null
    {
        // «Dia não letivo», «não letivo» — a escola a dizer, por extenso, que
        // naquele dia não há aula sem ser por ser feriado.
        if (preg_match('/n[ãa]o\s*-?\s*letiv[oa]s?\b/iu', $label) === 1) {
            return AcademicCalendarExceptionType::NonTeachingDay;
        }

        if (preg_match('/\bferiad[oa]s?\b/iu', $label) === 1) {
            return AcademicCalendarExceptionType::Holiday;
        }

        if (preg_match('/\breuni(?:[õo]es|[ãa]o)\b/iu', $label) === 1
            || preg_match('/\bconselho\s+(?:de\s+turma|pedag[óo]gico|geral)\b/iu', $label) === 1) {
            return CalendarEventType::Meeting;
        }

        if (preg_match('/\bvisita\s+de\s+estudo\b/iu', $label) === 1) {
            return CalendarEventType::FieldTrip;
        }

        // «Atividades LETIVAS» é o contrário de uma atividade: é o nome que o
        // documento dá ao próprio funcionamento das aulas («início das atividades
        // letivas»). Sem esta exclusão, a palavra que mais aparece num calendário
        // escolar classificava mal metade da grelha.
        if (preg_match('/\bconv[íi]vio\b/iu', $label) === 1
            || (preg_match('/\batividades?\b/iu', $label) === 1 && preg_match('/letiv/iu', $label) !== 1)) {
            return CalendarEventType::Activity;
        }

        return null;
    }

    /**
     * A data é feriado nacional do país DESTE ano letivo?
     *
     * `false` para um país sem provider, e nunca o calendário português a fingir
     * de universal — a mesma recusa honesta que NationalHolidayProviders já faz.
     */
    private function isNationalHoliday(string $date, ?string $countryCode): bool
    {
        $code = mb_strtoupper(trim((string) $countryCode));

        if ($code === '') {
            return false;
        }

        $year = (int) substr($date, 0, 4);
        $cacheKey = "{$code}|{$year}";

        if (! isset($this->nationalHolidayDates[$cacheKey])) {
            $provider = $this->providers->for($code);

            $dates = [];
            foreach ($provider?->between("{$year}-01-01", "{$year}-12-31") ?? [] as $holiday) {
                $dates[$holiday->date] = true;
            }

            $this->nationalHolidayDates[$cacheKey] = $dates;
        }

        return isset($this->nationalHolidayDates[$cacheKey][$date]);
    }
}
