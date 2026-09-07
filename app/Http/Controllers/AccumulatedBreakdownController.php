<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Assessment\AccumulatedBreakdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * DE ONDE VEM AQUELE NÚMERO — a decomposição do desempenho acumulado de um
 * aluno, pedida quando alguém a quer ver.
 *
 * A PEDIDO, E NÃO NA PÁGINA. Uma turma de trinta alunos com cinco domínios e
 * duas unidades tem trezentas células de acumulado; mandar a decomposição de
 * todas elas no `Inertia::render` do Quadro Síntese multiplicaria por muito o
 * peso de uma página que já é grande, para responder a uma pergunta que se faz
 * sobre uma célula de cada vez (§26). Este endpoint responde sobre uma.
 *
 * JSON, E NÃO UMA VISITA INERTIA. O que volta abre num painel sobre a tabela;
 * uma visita substituiria a página e perderia o sítio onde o professor estava.
 *
 * A AUTORIZAÇÃO É A DA TURMA, e as duas perguntas que o âmbito de organização
 * não faz são feitas aqui: se o período é do ano letivo desta turma, e se a
 * matrícula é desta turma. Um ulid válido de outra turma da mesma escola é
 * exatamente o IDOR que o âmbito global não apanha (ADR-0002).
 */
class AccumulatedBreakdownController extends Controller
{
    public function __construct(
        protected AccumulatedBreakdown $breakdown,
    ) {}

    public function show(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        ?Domain $domain = null,
    ): JsonResponse {
        Gate::authorize('view', $class);

        abort_unless((int) $period->academic_year_id === (int) $class->academic_year_id, 404);
        abort_unless((int) $enrollment->class_id === (int) $class->getKey(), 404);

        // O DOMÍNIO TEM DE SER DESTE PERFIL. Um domínio de outro perfil não tem
        // resposta nenhuma nesta turma, e devolver-lhe uma decomposição vazia
        // seria dizer «este domínio existe aqui e não tem elementos», que é uma
        // afirmação diferente de «este domínio não é desta turma».
        if ($domain !== null) {
            $version = $class->profileVersion;

            abort_if($version === null, 404);
            abort_unless(
                $version->domains()->where('domain_id', $domain->getKey())->exists(),
                404,
            );
        }

        return response()->json(
            $this->breakdown->for($class, $period, $enrollment, $domain?->getKey()),
        );
    }
}
