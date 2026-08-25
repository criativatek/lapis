<?php

namespace App\Services\AcademicCalendar;

use App\Domain\AcademicCalendar\AcademicCalendarExceptionMatch;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use Illuminate\Support\Collection;

/**
 * A REGRA DE DEDUPLICAÇÃO DAS EXCEÇÕES LETIVAS, escrita uma vez e usada por todos
 * os caminhos que propõem uma.
 *
 * PORQUE É QUE ISTO SAIU DA IMPORTAÇÃO. Nasceu dentro de
 * BuildAcademicCalendarImportPreview, quando o único sítio de onde uma exceção
 * podia ser proposta em lote era um .xlsx. Deixou de ser: a sugestão de feriados
 * nacionais propõe exatamente a mesma espécie de coisa, contra exatamente o mesmo
 * calendário, e tem de chegar exatamente à mesma conclusão — senão sugerir e
 * importar discordavam sobre se o 1 de maio já lá está, e um deles criava a
 * segunda linha. Emparelhar exceções deixou de ser assunto da importação, e por
 * isso deixou de viver lá dentro.
 *
 * A CRIAÇÃO MANUAL NÃO PASSA POR AQUI, e é deliberado: escrever UMA exceção à mão
 * é uma pessoa a dizer o que quer, num formulário que ela própria preencheu e cuja
 * lista tem à frente. Não há lote nenhum para deduplicar, e recusar-lhe uma
 * segunda linha no mesmo dia seria decidir por ela.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A CHAVE NATURAL É `type` + `starts_on` + `ends_on` — e não o título.
 *
 * O título é texto livre que muda de ano para ano e de documento para documento
 * («Natal», «Interrupção letiva do Natal», «Férias de Natal» são o mesmo
 * intervalo). É por isso que a igualdade de datas manda: um feriado é o dia em
 * que cai, e o nome é como lhe chamam.
 *
 * MESMA ESPÉCIE, MESMAS DATAS, TÍTULO QUE NORMALIZA IGUAL → já existe. Nada a
 * fazer. É isto que torna reimportar o mesmo ficheiro uma operação inofensiva.
 *
 * MESMA ESPÉCIE, MESMAS DATAS, TÍTULO DIFERENTE → correspondência. Um afinamento
 * deliberado ao que a Fase 5.6 entregou, e não a correção de um defeito: até aqui
 * «Dia do Trabalhador» e «1.º de Maio» no mesmo 1 de maio liam-se ambos como «já
 * está lá», e o professor nunca chegava a saber que o documento lhe chamava outra
 * coisa. Continua a não nascer uma segunda linha — isso nunca esteve em causa —
 * mas as duas designações passam a ir lado a lado para ele escolher qual fica.
 *
 * MESMA ESPÉCIE, DATAS QUE SE TOCAM SEM SEREM IGUAIS → conflito. Pode ser a
 * interrupção do ano passado que ficou a mais um dia, pode ser a versão nova do
 * calendário — não há regra que saiba qual, e por isso não há regra nenhuma a
 * decidir (§32).
 *
 * ESPÉCIES DIFERENTES NUNCA CONFLITUAM, de propósito: o 25 de dezembro é um
 * feriado E está dentro de uma interrupção letiva, e as duas linhas são ambas
 * verdadeiras.
 *
 * NENHUMA APROXIMAÇÃO DE TEXTO, EM PONTO NENHUM: sem distância de Levenshtein,
 * sem «títulos parecidos», sem subcadeias, sem datas «quase iguais». A comparação
 * de títulos é exata depois de normalizada, ou não é nada.
 */
class MatchAcademicCalendarExceptions
{
    /**
     * O que uma exceção proposta encontra no calendário que já existe.
     *
     * `$existing` É RESPONSABILIDADE DE QUEM CHAMA, e chega sempre já estreitado:
     * são as exceções DAQUELE ano letivo, lidas por quem as pode ler. Nada aqui
     * consulta a base de dados, o que é o que torna esta regra igualmente
     * aplicável a uma pré-visualização (contra o que estava lá há dez segundos) e
     * a uma confirmação (contra o que está lá agora, dentro da transação).
     *
     * @param  string  $startsOn  «Y-m-d»
     * @param  string  $endsOn  «Y-m-d» — igual a `$startsOn` num feriado de um dia
     * @param  Collection<int, AcademicCalendarException>  $existing
     */
    public function match(
        AcademicCalendarExceptionType $type,
        string $startsOn,
        string $endsOn,
        string $title,
        Collection $existing,
    ): AcademicCalendarExceptionMatch {
        $sameType = $existing->filter(
            fn (AcademicCalendarException $exception): bool => $exception->type === $type,
        );

        $exact = $sameType->first(
            fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() === $startsOn
                && $exception->ends_on->toDateString() === $endsOn,
        );

        if ($exact !== null) {
            return $this->sameTitle($exact->title, $title)
                ? AcademicCalendarExceptionMatch::exists($this->payload($exact))
                : AcademicCalendarExceptionMatch::correspondence($this->payload($exact));
        }

        // As datas comparam-se como texto porque são «Y-m-d» canónicas: a ordem
        // lexicográfica É a ordem cronológica.
        $overlapping = $sameType->first(
            fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $endsOn
                && $exception->ends_on->toDateString() >= $startsOn,
        );

        if ($overlapping !== null) {
            return AcademicCalendarExceptionMatch::conflict($this->payload($overlapping));
        }

        return AcademicCalendarExceptionMatch::none();
    }

    /**
     * O RETRATO DO QUE JÁ LÁ ESTÁ, na forma exata que os dois ecrãs mostram: a
     * espécie, a designação, as datas e a PROVENIÊNCIA — porque «já lá está, e foi
     * o senhor que a escreveu à mão» e «já lá está, e veio do ficheiro do ano
     * passado» são duas frases muito diferentes para quem está a decidir.
     *
     * @return array<string, mixed>
     */
    public function payload(AcademicCalendarException $exception): array
    {
        return [
            'ulid' => $exception->ulid,
            'type_label' => $exception->type->label(),
            'title' => $exception->title,
            'starts_on' => $exception->starts_on->toDateString(),
            'ends_on' => $exception->ends_on->toDateString(),
            'source_label' => $exception->source->label(),
        ];
    }

    /**
     * Duas designações são a mesma quando só diferem em maiúsculas e em espaços.
     * E MAIS NADA: «1.º de Maio» e «1 de Maio» ficam DIFERENTES de propósito — e o
     * que isso produz não é um duplicado, é uma correspondência que o professor vê
     * e decide.
     */
    public function sameTitle(string $left, string $right): bool
    {
        return self::normalise($left) === self::normalise($right);
    }

    /**
     * A ÚNICA definição de «normalizado» que este calendário tem, e a que já cá
     * estava: é esta mesma que BuildAcademicCalendarImportPreview usa para
     * emparelhar os rótulos dos períodos. Passou a viver aqui em vez de lá porque
     * agora tem dois clientes, e duas definições de «normalizado» era a maneira
     * garantida de um dia discordarem sobre se dois textos são o mesmo.
     *
     * Minúsculas, espaços seguidos colapsados num só, pontas aparadas. Nada de
     * acentos removidos, nada de pontuação retirada: «Páscoa» e «Pascoa» são
     * textos diferentes, e adivinhar que são o mesmo é exatamente a aproximação
     * que esta classe existe para não fazer.
     */
    public static function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
