<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\CalendarExceptionChange;
use App\Actions\Lessons\CalendarReconciliationPlan;
use App\Actions\Lessons\CalendarReconciliationScope;
use App\Actions\Lessons\ReconcileLessonsWithCalendarExceptions;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\AcademicCalendarExceptionDeleteRequest;
use App\Http\Requests\AcademicCalendarExceptionImpactRequest;
use App\Http\Requests\AcademicCalendarExceptionRequest;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicYear;
use App\Models\User;
use App\Services\Lessons\CalendarReconciliationPresenter;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * As «exceções letivas» de um ano — feriados, interrupções letivas e dias não
 * letivos — criadas, alteradas e eliminadas UMA DE CADA VEZ.
 *
 * PORQUE É QUE ISTO DEIXOU DE SER PARTE DO FORMULÁRIO DO ANO. Até aqui uma
 * exceção nascia, mudava e morria dentro do mesmo pedido que gravava o ano
 * inteiro: as linhas estavam sempre abertas em campos de texto, uma exceção nova
 * caía no fundo de uma lista comprida, e o único botão de gravar era o «Guardar
 * ano letivo» lá em baixo — que dizia, sem querer, que cada linha se gravava
 * sozinha quando nenhuma se gravava. A verificação em uso real apanhou
 * exatamente isso. Agora cada exceção tem os seus próprios três gestos, e cada
 * gesto é um pedido a sério, com uma resposta a sério.
 *
 * OS PERÍODOS NÃO MUDARAM, E É DELIBERADO. Continuam onde estavam, no formulário
 * grande e no mesmo `syncPeriods()` de sempre: um período nunca teve o problema
 * que esta página tinha — são dois ou três, criam-se com o ano, e a sua ordem é
 * uma propriedade do conjunto e não de cada um. As exceções são dezenas, chegam
 * ao longo do ano e cada uma é independente das outras. Duas coisas diferentes,
 * dois desenhos diferentes.
 *
 * A MESMA AUTORIZAÇÃO, E NÃO UMA SEGUNDA. `Gate::authorize('update',
 * $academicYear)` — exatamente a superfície que os períodos já usam. Não há
 * AcademicCalendarExceptionPolicy nenhuma, tal como não há AcademicPeriodPolicy:
 * quem pode reformar a estrutura do ano é quem AcademicYearPolicy diz, e a
 * camada pedagógica (um ano encerrado é só de leitura) vem incluída nela.
 *
 * AS TRÊS AÇÕES RESPONDEM COM `back()`: a página de edição do ano é onde o
 * resultado se vê, e a resposta do Inertia traz-lhe as props já refrescadas —
 * a lista reordenada, a linha com os valores gravados — sem esta camada ter de
 * inventar uma fusão local do que julgava ter escrito.
 */
class AcademicCalendarExceptionController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected ReconcileLessonsWithCalendarExceptions $reconcile,
        protected CalendarReconciliationPresenter $presenter,
        protected Entitlements $entitlements,
    ) {}

    /**
     * PRÉ-VISUALIZAÇÃO DO IMPACTO (só leitura): o que aconteceria às aulas já
     * existentes de TODA a organização se esta exceção fosse criada, alterada
     * ou eliminada. Corre o plano numa transação sempre revertida — nada fica
     * escrito, nem a exceção. Devolve o `plan_token` que o pedido de gravação
     * (com `reconcile`) tem de repetir, e contagens por turma: nunca aulas
     * individuais nem conteúdo pedagógico.
     *
     * SEM O MÓDULO «Aulas e Sumários» (ou com ele só para consulta) não há aulas
     * que esta organização possa ver corrigidas: a resposta é um plano vazio,
     * calculado sem tocar em nada, e o ecrã grava como sempre. Não é uma
     * recusa — gravar uma exceção nunca dependeu do módulo das aulas.
     */
    public function impact(AcademicCalendarExceptionImpactRequest $request, AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize('update', $academicYear);

        $action = (string) $request->validated('action');
        $change = match ($action) {
            CalendarExceptionChange::CREATE => CalendarExceptionChange::create($this->attributes($request)),
            CalendarExceptionChange::UPDATE => CalendarExceptionChange::update($this->exceptionFrom($request, $academicYear), $this->attributes($request)),
            default => CalendarExceptionChange::delete($this->exceptionFrom($request, $academicYear)),
        };

        $plan = $this->lessonsWritable()
            ? $this->reconcile->preview(
                CalendarReconciliationScope::forOrganizationOwner($academicYear),
                $this->user($request),
                $change,
            )
            : CalendarReconciliationPlan::nothing(CalendarReconciliationScope::ORGANIZATION_OWNER, $change->operation);

        return response()
            ->json($this->presenter->owner($plan))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(AcademicCalendarExceptionRequest $request, AcademicYear $academicYear): RedirectResponse
    {
        Gate::authorize('update', $academicYear);

        if ($request->boolean('reconcile')) {
            return $this->reconciled(
                $request,
                $academicYear,
                CalendarExceptionChange::create($this->attributes($request)),
                'Adicionado ao calendário do ano letivo.',
            );
        }

        $academicYear->exceptions()->create([
            ...$request->safe()->only(['type', 'title', 'starts_on', 'ends_on', 'note']),
            // Uma exceção escrita aqui nasce «manual» — a única proveniência que
            // este caminho escreve. `Suggested` e `Imported` têm os seus próprios
            // caminhos e nunca vêm do cliente.
            'source' => AcademicCalendarExceptionSource::Manual->value,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adicionado ao calendário do ano letivo.']);

        return back();
    }

    public function update(
        AcademicCalendarExceptionRequest $request,
        AcademicYear $academicYear,
        AcademicCalendarException $exception,
    ): RedirectResponse {
        Gate::authorize('update', $academicYear);
        $this->assertBelongsTo($academicYear, $exception);

        if ($request->boolean('reconcile')) {
            return $this->reconciled(
                $request,
                $academicYear,
                CalendarExceptionChange::update($exception, $this->attributes($request)),
                'Alterações guardadas.',
            );
        }

        // `source` fica de fora, exatamente como `status` fica de fora ao gravar
        // um período: é uma coisa que se decide uma vez, ao criar, e nunca um
        // efeito secundário de gravar o formulário. Editar uma exceção não a
        // torna noutra coisa.
        $exception->fill($request->safe()->only(['type', 'title', 'starts_on', 'ends_on', 'note']));
        $exception->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alterações guardadas.']);

        return back();
    }

    /**
     * ELIMINAR UMA EXCEÇÃO ELIMINA UMA LINHA. Nada mais: nenhum acontecimento,
     * nenhum período, nenhuma rotina do horário e nenhuma aula são tocados aqui
     * — nada aponta para esta tabela por chave estrangeira, e a materialização
     * das aulas, que a lê, lê-a por datas e volta a ver o que lá estiver. O que
     * acontece a seguir a apagar um feriado é que aquele dia volta a ser letivo,
     * e é tudo o que acontece.
     */
    public function destroy(AcademicCalendarExceptionDeleteRequest $request, AcademicYear $academicYear, AcademicCalendarException $exception): RedirectResponse
    {
        Gate::authorize('update', $academicYear);
        $this->assertBelongsTo($academicYear, $exception);

        if ($request->boolean('reconcile')) {
            return $this->reconciled(
                $request,
                $academicYear,
                CalendarExceptionChange::delete($exception),
                'Eliminado do calendário do ano letivo.',
            );
        }

        $exception->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Eliminado do calendário do ano letivo.']);

        return back();
    }

    /**
     * COM `reconcile`: a alteração da exceção e a retirada das aulas vazias
     * (classe A) acontecem na MESMA transação, no âmbito do responsável (toda a
     * organização), e só se o `plan_token` da pré-visualização ainda coincidir
     * com o estado de agora. Se não coincidir, a ação lança 422 de dentro da
     * transação e NADA fica escrito — nem a exceção. As aulas com plano ou
     * registos nunca se tocam aqui: ficam para os professores respetivos.
     *
     * Retira aulas, por isso é uma escrita no módulo das aulas: recusada
     * durante uma sessão de suporte, e sem o módulo «Aulas e Sumários» com
     * escrita (403, sem gravar nada — nem a exceção).
     */
    private function reconciled(
        Request $request,
        AcademicYear $academicYear,
        CalendarExceptionChange $change,
        string $done,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        if (! $this->lessonsWritable()) {
            abort(403, __('O plano desta organização não permite alterar as aulas. Guarde sem alterar as aulas.'));
        }

        $plan = $this->reconcile->execute(
            CalendarReconciliationScope::forOrganizationOwner($academicYear),
            (string) $request->input('plan_token'),
            $this->user($request),
            $change,
        );

        $retired = $plan->totals['retired'];
        $planned = $plan->totals['awaiting_teacher'];
        $flagged = $plan->totals['flagged'];

        $message = __($done).' '.($retired === 0
            ? __('Nenhuma aula vazia foi retirada.')
            : trans_choice('{1} Foi retirada 1 aula vazia.|[2,*] Foram retiradas :count aulas vazias.', $retired));

        // Contagens EXATAS de cada tipo: uma aula com planeamento espera pelo
        // professor; uma em revisão (registos, ou parceira de uma lição que não
        // sai) fica como está.
        if ($planned > 0) {
            $message .= ' '.trans_choice(
                '{1} 1 aula com planeamento ficou como está: o planeamento não se apaga, e o professor da turma passa-o para a aula seguinte em «Aulas e Sumários».|[2,*] :count aulas com planeamento ficaram como estão: o planeamento não se apaga, e os professores das turmas passam-no para a aula seguinte em «Aulas e Sumários».',
                $planned,
            );
        }

        if ($flagged > 0) {
            $message .= ' '.trans_choice(
                '{1} 1 aula fica como está, para revisão pelo professor respetivo.|[2,*] :count aulas ficam como estão, para revisão pelos professores respetivos.',
                $flagged,
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array{type: string, title: string, starts_on: string, ends_on: string, note?: string|null}
     */
    private function attributes(FormRequest $request): array
    {
        $validated = $request->safe()->only(['type', 'title', 'starts_on', 'ends_on', 'note']);
        $attributes = [
            'type' => (string) $validated['type'],
            'title' => (string) $validated['title'],
            'starts_on' => (string) $validated['starts_on'],
            'ends_on' => (string) $validated['ends_on'],
        ];

        if (array_key_exists('note', $validated)) {
            $attributes['note'] = $validated['note'] === null ? null : (string) $validated['note'];
        }

        return $attributes;
    }

    private function exceptionFrom(AcademicCalendarExceptionImpactRequest $request, AcademicYear $academicYear): AcademicCalendarException
    {
        $exception = AcademicCalendarException::query()->where('ulid', (string) $request->validated('exception'))->firstOrFail();
        $this->assertBelongsTo($academicYear, $exception);

        return $exception;
    }

    /** O módulo «Aulas e Sumários» com escrita — o que `module:lessons` exige a um POST. */
    private function lessonsWritable(): bool
    {
        return $this->entitlements->accessState('lessons')->permitsWrite();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * A exceção resolve-se já dentro da organização (o global scope do modelo),
     * pelo que isto é o que barra um ulid válido de OUTRO ano meu — sem ele, a
     * página de um ano podia adotar, alterar ou apagar a interrupção do ano
     * vizinho. A mesma guarda, escrita da mesma maneira, que
     * EnrollmentController já faz entre uma turma e a sua matrícula.
     */
    private function assertBelongsTo(AcademicYear $academicYear, AcademicCalendarException $exception): void
    {
        abort_unless($exception->academic_year_id === $academicYear->id, 404);
    }
}
