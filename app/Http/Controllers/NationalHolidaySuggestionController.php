<?php

namespace App\Http\Controllers;

use App\Domain\AcademicCalendar\AcademicCalendarExceptionMatch;
use App\Domain\AcademicCalendar\NationalHoliday;
use App\Http\Requests\Calendar\NationalHolidaySuggestionRequest;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Services\AcademicCalendar\Holidays\NationalHolidayProviders;
use App\Services\AcademicCalendar\MatchAcademicCalendarExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Sugerir feriados nacionais» — os treze feriados portugueses que caem dentro
 * deste ano letivo, propostos linha a linha e escritos só depois de marcados.
 *
 * DOIS PASSOS, E O PRIMEIRO NÃO ESCREVE NADA. `index` é uma leitura do princípio
 * ao fim: calcula os feriados do país deste ano, compara cada um com o que já está
 * no calendário e devolve o que encontrou. Nenhuma linha nasce aí, nem no caminho
 * até aí. Só `store` escreve, e volta a correr as MESMAS comparações contra a base
 * de dados quando o faz — porque o que estava lá quando o diálogo abriu não é o que
 * interessa: interessa o que está lá quando se grava.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O PAÍS SAI DO ANO LETIVO E DE MAIS LADO NENHUM (§16).
 *
 * `academic_years.country_code` já existe, é obrigatório e vale «PT» por omissão —
 * é ele o país deste calendário. Não há aqui um segundo sítio para o configurar,
 * não se adivinha pela língua da interface, e não se pergunta ao professor de novo
 * uma coisa que ele já respondeu ao criar o ano.
 *
 * E QUANDO O PAÍS NÃO É PORTUGAL, DIZ-SE. Esta entrega tem UM provider. Um ano
 * letivo declarado espanhol recebe uma frase a dizer que não há feriados nacionais
 * disponíveis para ES — e nunca, em circunstância nenhuma, o calendário português
 * em silêncio. Treze feriados errados escritos sem aviso é o pior desfecho que esta
 * funcionalidade tinha à disposição.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A DEDUPLICAÇÃO É A MESMA DA IMPORTAÇÃO, e não uma segunda parecida:
 * MatchAcademicCalendarExceptions, o mesmo serviço, com a mesma chave natural
 * (espécie + datas) e a mesma comparação exata de designações normalizadas. É isso
 * — e só isso — que faz com que sugerir depois de importar, e importar depois de
 * sugerir, cheguem os dois à mesma conclusão sobre se o 1 de maio já lá está.
 *
 * UM DUPLICADO É ORDINÁRIO E NÃO UMA FALHA. Uma linha que, no instante de gravar,
 * já não é nova é saltada e contada — a mesma disciplina de
 * AcademicCalendarImportController e de TimetableImportController. Um lote de treze
 * feriados de que dois já lá estavam continua a acrescentar os outros onze.
 *
 * A PROVENIÊNCIA NUNCA SE REESCREVE (§28). Quando uma sugestão encontra uma linha
 * que já existe, a linha fica com o `source` que tinha: um feriado escrito à mão
 * continua a dizer «escrita pelo professor» para sempre. Emparelhar quer dizer «não
 * cries um duplicado», e nunca «adota a proveniência do outro».
 */
class NationalHolidaySuggestionController extends Controller
{
    /**
     * «Novo» não é um estado do emparelhador — é o que ESTE ecrã chama à ausência
     * de emparelhamento, exatamente como a pré-visualização da importação lhe chama
     * o mesmo. O emparelhador diz o que encontrou; quem lê decide o que fazer com
     * um «não encontrei nada».
     */
    private const STATE_NEW = 'new';

    public function __construct(
        private readonly NationalHolidayProviders $providers,
        private readonly MatchAcademicCalendarExceptions $matcher,
    ) {}

    /**
     * NÃO ESCREVE NADA. Um JSON e não uma página Inertia: isto é o conteúdo de um
     * diálogo que abre dentro da página de edição do ano letivo, e não um endereço
     * onde alguém aterre — a mesma escolha, e pela mesma razão, que
     * `reports.context` e `lessons.previous-summary` já fazem.
     */
    public function index(AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize('update', $academicYear);

        $provider = $this->providers->for($academicYear->country_code);

        if ($provider === null) {
            return response()->json([
                'country_code' => $academicYear->country_code,
                'supported' => false,
                'message' => $this->refusal($academicYear),
                'suggestions' => [],
            ]);
        }

        /** @var Collection<int, AcademicCalendarException> $existing */
        $existing = $academicYear->exceptions()->get();

        $suggestions = array_map(
            fn (NationalHoliday $holiday): array => $this->describe($holiday, $existing),
            $provider->between(
                $academicYear->starts_on->toDateString(),
                $academicYear->ends_on->toDateString(),
            ),
        );

        return response()->json([
            'country_code' => $academicYear->country_code,
            'supported' => true,
            'message' => null,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * A ÚNICA AÇÃO QUE ESCREVE — e escreve exatamente as linhas que o professor
     * marcou, com os títulos que o PROVIDER dá e nunca com os que o pedido trouxe
     * (o pedido não traz nenhum, ver NationalHolidaySuggestionRequest).
     */
    public function store(NationalHolidaySuggestionRequest $request, AcademicYear $academicYear): RedirectResponse
    {
        Gate::authorize('update', $academicYear);

        $provider = $this->providers->for($academicYear->country_code);

        if ($provider === null) {
            return back()->withErrors(['dates' => $this->refusal($academicYear)]);
        }

        $byDate = [];
        foreach ($provider->between($academicYear->starts_on->toDateString(), $academicYear->ends_on->toDateString()) as $holiday) {
            $byDate[$holiday->date] = $holiday;
        }

        $dates = $request->dates();

        // UMA DATA QUE NÃO É FERIADO NACIONAL NÃO É UMA LINHA A SALTAR. Um
        // duplicado é ordinário e conta-se; isto é outra coisa — é um pedido que
        // este ecrã não podia ter produzido, e escrever metade dele seria escrever
        // metade de uma coisa que ninguém pediu. Recusa-se inteiro, e nada é
        // gravado.
        foreach ($dates as $date) {
            if (! isset($byDate[$date])) {
                return back()->withErrors([
                    'dates' => __('Só é possível acrescentar por aqui os feriados nacionais deste ano letivo. Recarregue a lista e tente de novo.'),
                ]);
            }
        }

        $result = ['created' => 0, 'retitled' => 0, 'existed' => 0, 'conflicted' => 0];

        // UMA TRANSAÇÃO PARA O LOTE, como a confirmação da importação: uma falha
        // inesperada a meio não pode deixar para trás meia dúzia de feriados.
        DB::transaction(function () use ($academicYear, $byDate, $dates, &$result): void {
            /** @var Collection<int, AcademicCalendarException> $existing */
            $existing = $academicYear->exceptions()->get();

            foreach ($dates as $date) {
                $holiday = $byDate[$date];

                // RELIDO AGORA, e nunca acreditado a partir do que o diálogo disse
                // há dez segundos: entre abrir a lista e carregar no botão, uma
                // importação noutro separador pode ter escrito este mesmo dia.
                $match = $this->matcher->match(
                    AcademicCalendarExceptionType::Holiday,
                    $holiday->date,
                    $holiday->date,
                    $holiday->title,
                    $existing,
                );

                if ($match->is(AcademicCalendarExceptionMatch::STATE_EXISTS)) {
                    $result['existed']++;

                    continue;
                }

                // A DESIGNAÇÃO, E MAIS NADA. As datas são as mesmas por definição
                // deste estado, a observação é do professor, e o `source` fica
                // exatamente o que era (§28): uma linha escrita à mão que passe a
                // chamar-se «Dia do Trabalhador» continua a ser uma linha escrita à
                // mão.
                if ($match->is(AcademicCalendarExceptionMatch::STATE_CORRESPONDENCE)) {
                    $target = $existing->firstWhere('ulid', $match->current['ulid'] ?? null);

                    if ($target instanceof AcademicCalendarException) {
                        $target->title = $holiday->title;
                        $target->save();

                        $result['retitled']++;
                    }

                    continue;
                }

                if ($match->is(AcademicCalendarExceptionMatch::STATE_CONFLICT)) {
                    $result['conflicted']++;

                    continue;
                }

                $created = $academicYear->exceptions()->create([
                    'type' => AcademicCalendarExceptionType::Holiday->value,
                    'title' => $holiday->title,
                    'starts_on' => $holiday->date,
                    'ends_on' => $holiday->date,
                    // Um feriado nacional é um dia sem nada a acrescentar: a
                    // observação existe para o que o professor tenha a dizer, e
                    // inventar-lhe uma frase seria pôr palavras na boca dele.
                    'note' => null,
                    'source' => AcademicCalendarExceptionSource::Suggested->value,
                ]);

                // Mantido a par dentro do próprio lote, para que duas datas iguais
                // do mesmo payload não pudessem ser ambas criadas.
                $existing->push($created);

                $result['created']++;
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->summary($result)]);

        // `back()` para a página de edição do ano, que é onde o resultado se vê —
        // e a resposta do Inertia traz-lhe as props já refrescadas, com a lista de
        // exceções reordenada, exatamente como as três ações do CRUD manual.
        return back();
    }

    // ─────────────────────────────────────────────────────────────── utilitários

    /**
     * @param  Collection<int, AcademicCalendarException>  $existing
     * @return array<string, mixed>
     */
    private function describe(NationalHoliday $holiday, Collection $existing): array
    {
        // UM FERIADO É SEMPRE UM DIA — `starts_on === ends_on`, a convenção que
        // este modelo já usa — e é sempre do tipo `holiday`: é isso que ele é.
        $match = $this->matcher->match(
            AcademicCalendarExceptionType::Holiday,
            $holiday->date,
            $holiday->date,
            $holiday->title,
            $existing,
        );

        return [
            'date' => $holiday->date,
            'title' => $holiday->title,
            'state' => $match->state ?? self::STATE_NEW,
            'current' => $match->current,
        ];
    }

    private function refusal(AcademicYear $academicYear): string
    {
        $code = mb_strtoupper(trim($academicYear->country_code));

        return $code === ''
            ? __('Este ano letivo não tem país indicado, por isso não há feriados nacionais para sugerir. Indique o país em «Editar ano letivo».')
            : __('Não há feriados nacionais disponíveis para :country. Nesta versão só Portugal (PT) tem lista de feriados nacionais — os feriados deste ano letivo têm de ser escritos à mão ou importados do calendário da escola.', ['country' => $code]);
    }

    /**
     * O QUE ACONTECEU DE FACTO, contado — e não «feriados adicionados» a esconder
     * as duas linhas que ficaram por criar, que são exatamente aquelas sobre as
     * quais ainda há alguma coisa a decidir. A mesma regra do resumo da importação.
     *
     * @param  array<string, int>  $result
     */
    private function summary(array $result): string
    {
        $parts = [];

        $parts[] = $result['created'] > 0
            ? __(':total feriado(s) acrescentado(s) ao calendário.', ['total' => $result['created']])
            : __('Nenhum feriado foi acrescentado.');

        if ($result['retitled'] > 0) {
            $parts[] = __(':total designação(ões) atualizada(s).', ['total' => $result['retitled']]);
        }

        if ($result['existed'] > 0) {
            $parts[] = __(':total já estavam no calendário.', ['total' => $result['existed']]);
        }

        if ($result['conflicted'] > 0) {
            $parts[] = __(':total ficaram por criar por se sobreporem a datas já marcadas.', ['total' => $result['conflicted']]);
        }

        return implode(' ', $parts);
    }
}
