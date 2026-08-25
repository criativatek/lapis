<?php

namespace App\Http\Controllers;

use App\Actions\Calendar\SaveCalendarEvent;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Calendar\AcademicCalendarImportConfirmRequest;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\Import\AcademicCalendar\AcademicCalendarFileException;
use App\Services\Import\AcademicCalendar\AcademicCalendarParser;
use App\Services\Import\AcademicCalendar\BuildAcademicCalendarImportPreview;
use App\Support\AcademicYears\AcademicYearValidationException;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importar o calendário escolar publicado pela escola para a estrutura deste ano
 * letivo.
 *
 * TRÊS PASSOS E NENHUM ASSISTENTE, exatamente como a importação de horários que
 * lhe serviu de molde: escolher um ficheiro, rever o que foi lido, confirmar. O
 * passo do meio é onde está todo o valor — nada é criado até o professor ter
 * visto, linha a linha, o que seria acrescentado, o que já lá está, o que mudaria
 * e o que o documento diz de duas maneiras ao mesmo tempo.
 *
 * ATALHO PARA O ECRÃ MANUAL, E NUNCA UM SUBSTITUTO DELE. Tudo o que isto escreve
 * é um AcademicPeriod, uma AcademicCalendarException ou um CalendarEvent
 * ordinários, indistinguíveis dos escritos à mão em «Estrutura do Ano Letivo» e no
 * calendário, editáveis e removíveis exatamente no mesmo sítio. Não cria nenhuma
 * Lesson e não apaga nenhuma: a materialização continua atrás dos botões do
 * professor, e limita-se a LER as exceções que daqui saiam (Fase 5.5).
 *
 * E NÃO GUARDA NADA. O .xlsx é lido do caminho temporário do upload e esquecido —
 * nunca se torna um ficheiro nosso, nunca é armazenado, e nunca é fonte de verdade
 * depois do facto. A única marca que fica de ter vindo daqui é a coluna `source` a
 * dizer «imported» em cada exceção escrita, que é toda a proveniência que o §14
 * pede e não precisou de tabela nenhuma para existir.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SÓ .XLSX, NESTA PASSAGEM, E É UMA DECISÃO E NÃO UM ESQUECIMENTO.
 *
 * O enunciado pede «PDF; Excel». Há um calendário real em Excel contra o qual
 * cada regra do leitor foi verificada; não há nenhum em PDF. Escrever um leitor de
 * PDF para uma disposição que ninguém viu seria escrever regras que ninguém pode
 * confirmar — e um importador que se engana em silêncio sobre a estrutura de um
 * ano é pior do que um que ainda não existe. O ecrã de entrada di-lo por palavras
 * ao professor, em vez de aceitar um PDF e falhar de forma confusa.
 */
class AcademicCalendarImportController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        private readonly AcademicCalendarParser $parser,
        private readonly BuildAcademicCalendarImportPreview $previewBuilder,
        private readonly AcademicYearService $academicYears,
        private readonly SaveCalendarEvent $saveCalendarEvent,
        private readonly ResolveSelectedAcademicYear $resolveAcademicYear,
    ) {}

    /**
     * `module:calendar` — a MESMA entitlement que as duas vistas do calendário e
     * os acontecimentos já carregam. Importar o calendário é parte de ter o
     * calendário, e não uma segunda coisa para vender.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:calendar'];
    }

    public function create(Request $request): Response
    {
        $academicYear = $this->selectedAcademicYear($request);

        // Ver a página exige poder escrever o que ela vai propor: quem não pode
        // alterar a estrutura do ano não tem nada a fazer a escolher um ficheiro
        // para a alterar. Sem ano nenhum selecionado a página explica-o e não
        // oferece o carregamento.
        if ($academicYear !== null) {
            Gate::authorize('update', $academicYear);
        }

        return Inertia::render('academic-calendar-imports/Create', [
            'academicYear' => $academicYear === null ? null : [
                'label' => $academicYear->label,
                'starts_on' => $academicYear->starts_on->toDateString(),
                'ends_on' => $academicYear->ends_on->toDateString(),
            ],
        ]);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $data = $request->validate([
            // `mimes` verifica o conteúdo real e não a palavra do navegador, e a
            // extensão é verificada ao mesmo tempo — um ficheiro renomeado falha nas
            // duas contas. O teto de 8 MB é o mesmo das outras importações desta
            // aplicação e fica muito acima de qualquer calendário real (o de
            // referência tem 200 KB). Só `xlsx`, e nunca `xls`: também para o
            // formato antigo não há documento real contra o qual verificar seja o
            // que for.
            'calendar' => ['required', 'file', 'max:8192', 'mimes:xlsx'],
        ]);

        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            return back()->withErrors(['calendar' => __('Selecione um ano letivo antes de importar um calendário.')]);
        }

        Gate::authorize('update', $academicYear);

        try {
            // O ano civil em que o ano letivo escolhido começa serve APENAS de
            // recurso, para o caso de o título do documento não dizer a que ano se
            // refere. Quando o documento o diz — e o de referência diz —, é o
            // documento que manda, e a discrepância aparece como aviso mais abaixo
            // em vez de ser silenciosamente corrigida.
            $calendar = $this->parser->parse(
                $data['calendar']->getRealPath(),
                (int) $academicYear->starts_on->year,
            );
        } catch (AcademicCalendarFileException $exception) {
            // Todas estas mensagens são escritas para o professor — uma folha que
            // não é um calendário, um pacote que não abre em segurança. Resultados
            // esperados, e não rastreios de pilha.
            return back()->withErrors(['calendar' => $exception->getMessage()]);
        }

        if ($calendar->isEmpty()) {
            return back()->withErrors(['calendar' => AcademicCalendarFileException::nothingFound()->getMessage()]);
        }

        return Inertia::render('academic-calendar-imports/Preview', [
            ...$this->previewBuilder->build($calendar, $academicYear),
            'academicYear' => [
                'ulid' => $academicYear->ulid,
                'label' => $academicYear->label,
                'starts_on' => $academicYear->starts_on->toDateString(),
                'ends_on' => $academicYear->ends_on->toDateString(),
            ],
            'schoolName' => $calendar->schoolName,
            'fileAcademicYear' => $calendar->academicYearLabel,
            // Nunca bloqueia a importação — um professor pode legitimamente estar a
            // preparar o ano seguinte a partir do ficheiro deste ano —, mas é dito
            // com todas as letras, e o ano selecionado nunca muda nas suas costas.
            // A mesma disciplina do importador de horários.
            'yearMismatch' => $calendar->academicYearNormalised !== null
                && $calendar->academicYearNormalised !== $academicYear->label,
        ]);
    }

    public function confirm(AcademicCalendarImportConfirmRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        /** @var AcademicYear $academicYear */
        $academicYear = $request->academicYear();

        $result = [
            'periods_created' => 0,
            'periods_updated' => 0,
            'exceptions_created' => 0,
            'events_created' => 0,
            'existed' => 0,
            'conflicted' => 0,
        ];

        try {
            // UMA TRANSAÇÃO PARA O LOTE: uma falha inesperada a meio não pode
            // deixar para trás um ano meio importado. Duplicados e conflitos NÃO
            // são falhas — são saltados e contados, porque um lote de treze
            // feriados novos e dois já lá postos deve continuar a acrescentar os
            // treze.
            DB::transaction(function () use ($request, $academicYear, &$result): void {
                $this->writePeriods($request, $academicYear, $result);
                $this->writeExceptions($request, $academicYear, $result);
                $this->writeEvents($request, $academicYear, $result);
            });
        } catch (AcademicYearValidationException $exception) {
            // A única recusa do AcademicYearService que é uma frase e não um erro:
            // um período que não pode ser removido. Não deveria acontecer por este
            // caminho (nada é removido daqui), mas se acontecer é lida e não
            // engolida.
            return back()->withErrors(['semesters' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->summary($request, $result),
        ]);

        return to_route('calendar.index');
    }

    // ─────────────────────────────────────────────────────────────── as escritas

    /**
     * Os períodos, ATRAVÉS DO AcademicYearService e não de um segundo caminho
     * paralelo que escrevesse `academic_periods` à mão.
     *
     * A LISTA SUBMETIDA É A LISTA INTEIRA, e tem de ser: `syncPeriods()` faz um
     * diff e remove tudo o que deixe de vir no pedido. Enviar só os dois semestres
     * importados apagaria em silêncio os outros períodos que o ano já tivesse. Por
     * isso lê-se o que lá está, aplicam-se-lhe as linhas confirmadas por cima, e
     * envia-se o conjunto — que é exatamente o que o formulário de «Estrutura do
     * Ano Letivo» envia quando o professor grava.
     *
     * A ESPÉCIE E A ORDEM DE UM PERÍODO QUE JÁ EXISTE NÃO SE TOCAM. O documento
     * fala de datas; mudar de caminho o `kind` ou a `sequence` de um período que o
     * professor já tinha arrumado era fazer mais do que foi pedido.
     *
     * @param  array<string, int>  $result
     */
    private function writePeriods(AcademicCalendarImportConfirmRequest $request, AcademicYear $academicYear, array &$result): void
    {
        $rows = array_filter($request->rowsOf('semesters'), $request->isIncluded(...));

        if ($rows === []) {
            return;
        }

        // Relido da base de dados dentro da transação, e nunca acreditado a partir
        // da pré-visualização: o que o ano tem AGORA é a única coisa que interessa
        // comparar.
        $existing = $academicYear->periods()->get();
        $submitted = $existing
            ->map(fn (AcademicPeriod $period): array => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'kind' => $period->kind->value,
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ])
            ->all();

        $indexByUlid = [];
        foreach ($submitted as $index => $period) {
            $indexByUlid[$period['ulid']] = $index;
        }

        $nextSequence = $existing->max('sequence') ?? 0;
        $changed = false;

        foreach ($rows as $row) {
            $ulid = is_string($row['ulid'] ?? null) ? $row['ulid'] : null;
            $startsOn = (string) $row['starts_on'];
            $endsOn = (string) $row['ends_on'];

            if ($ulid !== null && isset($indexByUlid[$ulid])) {
                $index = $indexByUlid[$ulid];

                if ($submitted[$index]['starts_on'] === $startsOn && $submitted[$index]['ends_on'] === $endsOn) {
                    $result['existed']++;

                    continue;
                }

                $submitted[$index]['starts_on'] = $startsOn;
                $submitted[$index]['ends_on'] = $endsOn;
                $result['periods_updated']++;
                $changed = true;

                continue;
            }

            // Sem ulid: um período novo. Um rótulo que por acaso já exista no ano
            // não é adotado às escondidas — a pré-visualização é que emparelha por
            // rótulo, e o que ela emparelhou chega aqui com o ulid respetivo.
            $submitted[] = [
                'ulid' => null,
                'label' => (string) $row['label'],
                'kind' => (string) $row['kind'],
                'sequence' => ++$nextSequence,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ];
            $result['periods_created']++;
            $changed = true;
        }

        // Um lote em que todos os períodos já estavam exatamente como o documento
        // os descreve não chega sequer a chamar o serviço: uma gravação que não
        // muda nada continua a ser uma gravação, e não há razão nenhuma para a
        // fazer.
        if (! $changed) {
            return;
        }

        $this->academicYears->update(
            $academicYear,
            [
                'label' => $academicYear->label,
                'starts_on' => $academicYear->starts_on->toDateString(),
                'ends_on' => $academicYear->ends_on->toDateString(),
                'status' => $academicYear->status->value,
                'country_code' => $academicYear->country_code,
                'region_code' => $academicYear->region_code,
            ],
            array_values($submitted),
            // `null` E NÃO `[]`: null é «este pedido não falou de exceções», e um
            // array vazio seria «remove-as todas». As exceções desta importação são
            // escritas logo a seguir, com a sua própria proveniência, e as que já lá
            // estavam não são para tocar.
            null,
        );
    }

    /**
     * As exceções letivas, criadas diretamente — e deliberadamente NÃO através de
     * `AcademicYearService::syncExceptions()`.
     *
     * Aquele método é o do FORMULÁRIO DO ANO, e a sua forma é a certa para o que
     * ele faz e a errada para aqui: faz um diff que apaga tudo o que não venha no
     * pedido (uma importação não sabe nada das exceções que o professor escreveu à
     * mão, e não tem de as apagar), e escreve sempre `source = manual` (o contrário
     * exato do que esta origem tem de registar). O que se reaproveita é a FORMA das
     * linhas — os mesmos campos, a mesma normalização de uma observação em branco —
     * e não o mecanismo de sincronização.
     *
     * `source = imported` EM TODAS ELAS. É esta coluna, e mais nada, a proveniência
     * que o §14 pede: sem tabela de lotes, sem rasto de auditoria, sem chave
     * estrangeira nenhuma para uma importação. Uma palavra a responder «isto foi
     * escrito à mão?».
     *
     * @param  array<string, int>  $result
     */
    private function writeExceptions(AcademicCalendarImportConfirmRequest $request, AcademicYear $academicYear, array &$result): void
    {
        $rows = array_filter($request->rowsOf('exceptions'), $request->isIncluded(...));

        if ($rows === []) {
            return;
        }

        /** @var Collection<int, AcademicCalendarException> $existing */
        $existing = $academicYear->exceptions()->get();

        foreach ($rows as $row) {
            $type = AcademicCalendarExceptionType::from((string) $row['type']);
            $startsOn = (string) $row['starts_on'];
            $endsOn = (string) $row['ends_on'];

            // A MESMA CHAVE NATURAL da pré-visualização, aplicada outra vez e agora
            // contra o que está mesmo na base de dados. É isto — e não o que o
            // navegador devolveu — que torna reimportar o mesmo ficheiro uma
            // operação sem efeito, mesmo que o professor volte a marcar tudo.
            $sameType = $existing->filter(
                fn (AcademicCalendarException $exception): bool => $exception->type === $type,
            );

            $exact = $sameType->first(
                fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() === $startsOn
                    && $exception->ends_on->toDateString() === $endsOn,
            );

            if ($exact !== null) {
                $result['existed']++;

                continue;
            }

            $overlapping = $sameType->first(
                fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $endsOn
                    && $exception->ends_on->toDateString() >= $startsOn,
            );

            if ($overlapping !== null) {
                $result['conflicted']++;

                continue;
            }

            $note = $row['note'] ?? null;

            $created = $academicYear->exceptions()->create([
                'type' => $type->value,
                'title' => (string) $row['title'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                // Uma observação em branco é a AUSÊNCIA de observação e escreve-se
                // null — a mesma convenção de AcademicYearService::exceptionAttributes().
                'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
                'source' => AcademicCalendarExceptionSource::Imported->value,
            ]);

            // Mantido a par dentro do próprio lote, para que duas linhas iguais do
            // mesmo payload não possam ser ambas criadas.
            $existing->push($created);

            $result['exceptions_created']++;
        }
    }

    /**
     * Os «outros acontecimentos», através da ação SaveCalendarEvent que o
     * CalendarEventController já usa — e nunca de um segundo caminho a escrever
     * `calendar_events` à mão.
     *
     * O PROFESSOR QUE IMPORTA FICA A SER O AUTOR, exatamente como se o tivesse
     * escrito no formulário do calendário: um acontecimento é pessoal, e a ação já
     * carimba `user_id` uma vez na criação.
     *
     * @param  array<string, int>  $result
     */
    private function writeEvents(AcademicCalendarImportConfirmRequest $request, AcademicYear $academicYear, array &$result): void
    {
        $rows = array_filter($request->rowsOf('events'), $request->isIncluded(...));

        if ($rows === []) {
            return;
        }

        $actor = $this->user($request);

        foreach ($rows as $row) {
            $title = (string) $row['title'];
            $startsOn = (string) $row['starts_on'];
            $endsOn = (string) $row['ends_on'];

            // A mesma disciplina de chave natural das exceções, com a chave que um
            // acontecimento tem: dono, espécie, título e datas. Sem ela, confirmar
            // duas vezes a mesma proposta deixava dois «Fim 9.º ano» no mesmo dia.
            $duplicate = CalendarEvent::query()
                ->where('user_id', $actor->getKey())
                ->where('type', CalendarEventType::Other->value)
                ->where('title', $title)
                ->whereDate('starts_on', $startsOn)
                ->whereDate('ends_on', $endsOn)
                ->exists();

            if ($duplicate) {
                $result['existed']++;

                continue;
            }

            $this->saveCalendarEvent->execute(
                null,
                [
                    'type' => CalendarEventType::Other->value,
                    'title' => $title,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'starts_at' => null,
                    'ends_at' => null,
                    'description' => __('Importado do calendário escolar de :year.', ['year' => $academicYear->label]),
                ],
                // Sem turmas: o documento não diz de que turmas se trata, e
                // escolher algumas por ele seria inventar.
                [],
                $actor,
            );

            $result['events_created']++;
        }
    }

    // ─────────────────────────────────────────────────────────────── o resultado

    /**
     * O QUE ACONTECEU DE FACTO, contado em vez de resumido para longe.
     *
     * «Importação concluída» sozinho escondia as duas interrupções que colidiram e
     * as nove linhas que o professor deixou por marcar — que são exatamente as
     * coisas sobre as quais ainda há alguma coisa a fazer. A mesma regra do resumo
     * do importador de horários.
     *
     * @param  array<string, int>  $result
     */
    private function summary(AcademicCalendarImportConfirmRequest $request, array $result): string
    {
        $parts = [];

        $written = $result['periods_created'] + $result['periods_updated']
            + $result['exceptions_created'] + $result['events_created'];

        if ($written === 0) {
            $parts[] = __('Calendário importado: nada foi acrescentado.');
        } else {
            $parts[] = __('Calendário importado.');
        }

        if ($result['periods_created'] > 0) {
            $parts[] = __(':total período(s) criado(s).', ['total' => $result['periods_created']]);
        }

        if ($result['periods_updated'] > 0) {
            $parts[] = __(':total período(s) atualizado(s).', ['total' => $result['periods_updated']]);
        }

        if ($result['exceptions_created'] > 0) {
            $parts[] = __(':total feriado(s)/interrupção(ões) criado(s).', ['total' => $result['exceptions_created']]);
        }

        if ($result['events_created'] > 0) {
            $parts[] = __(':total acontecimento(s) criado(s).', ['total' => $result['events_created']]);
        }

        if ($result['existed'] > 0) {
            $parts[] = __(':total entrada(s) já existiam.', ['total' => $result['existed']]);
        }

        if ($result['conflicted'] > 0) {
            $parts[] = __(':total entrada(s) ficaram por criar por se sobreporem a datas já marcadas.', ['total' => $result['conflicted']]);
        }

        $notSelected = $this->notSelectedCount($request);

        if ($notSelected > 0) {
            $parts[] = __(':total linha(s) não foram selecionadas.', ['total' => $notSelected]);
        }

        return implode(' ', $parts);
    }

    private function notSelectedCount(AcademicCalendarImportConfirmRequest $request): int
    {
        $total = 0;

        foreach (['semesters', 'exceptions', 'events'] as $group) {
            foreach ($request->rowsOf($group) as $row) {
                if (! $request->isIncluded($row)) {
                    $total++;
                }
            }
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────── utilitários

    /**
     * A única resposta canónica a «em que ano letivo está este professor a
     * trabalhar», perguntada exatamente como AcademicYearCalendarController a
     * pergunta — mesmo serviço, mesma ordenação, mesma chave de sessão — para que a
     * importação e o calendário não possam discordar sobre qual é o ano.
     */
    private function selectedAcademicYear(Request $request): ?AcademicYear
    {
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');

        return $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
