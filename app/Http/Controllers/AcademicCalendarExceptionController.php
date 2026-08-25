<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicCalendarExceptionRequest;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
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
    public function store(AcademicCalendarExceptionRequest $request, AcademicYear $academicYear): RedirectResponse
    {
        Gate::authorize('update', $academicYear);

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
    public function destroy(AcademicYear $academicYear, AcademicCalendarException $exception): RedirectResponse
    {
        Gate::authorize('update', $academicYear);
        $this->assertBelongsTo($academicYear, $exception);

        $exception->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Eliminado do calendário do ano letivo.']);

        return back();
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
