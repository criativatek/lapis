<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\DecideDomainAppreciation;
use App\Support\Assessment\DomainDecisionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A apreciação de UM domínio, decidida pelo professor.
 *
 * O MESMO DESENHO DA DECISÃO GLOBAL, uma escala abaixo: a página mostra a
 * proposta, o professor escreve outra coisa se quiser, e o que fica registado é
 * a decisão dele — nunca uma conclusão do sistema apresentada como decidida
 * (§3.3). Quem escreve é um serviço, com a sua validação, o seu bloqueio de
 * linha e o seu rasto; este controlador traduz o pedido e devolve o ecrã.
 *
 * NADA AQUI FECHA NADA. Não há estado final, não há publicação e não há
 * confirmação: enquanto a autorização se mantiver, o professor volta atrás
 * quantas vezes quiser. Guardar a pauta, exportar ou ter histórico não mudam
 * isso — essas são cópias do que era verdade num momento (§9).
 *
 * `scale_level_id` a null é «voltar à proposta do Lapispro», e é uma escolha
 * tão explícita como qualquer outra: apaga a decisão, não a substitui por uma
 * decisão vazia.
 */
class EvaluationSheetDomainDecisionController extends Controller
{
    /**
     * O âmbito da Pauta. Não há seletor de âmbito nesse ecrã, e uma decisão tem
     * de pertencer ao mesmo universo que os números ao lado dos quais foi tomada.
     */
    protected const SCOPE = ClassificationScope::Period;

    public function __construct(
        protected DecideDomainAppreciation $decisions,
    ) {}

    public function store(
        Request $request,
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        Domain $domain,
    ): RedirectResponse {
        Gate::authorize('update', $class);

        // O período tem de ser do ano letivo desta turma e a matrícula tem de
        // ser desta turma. Ambos chegam por ulid e o âmbito global já garantiu a
        // organização; o que falta é a pergunta que o âmbito não faz — se são
        // desta turma. Um ulid válido de outra turma é exatamente o IDOR que
        // uma `exists:` nunca apanharia.
        abort_unless((int) $period->academic_year_id === (int) $class->academic_year_id, 404);
        abort_unless((int) $enrollment->class_id === (int) $class->getKey(), 404);

        $data = $request->validate([
            // Nunca uma regra `exists:` — corre no query builder e não vê o
            // âmbito de organização (ADR-0002). Que este nível é desta escala é
            // verificado pelo serviço, contra a escala real desta turma.
            'scale_level_id' => ['present', 'nullable', 'integer'],
        ], [], ['scale_level_id' => 'apreciação']);

        try {
            $this->decisions->decide(
                $class,
                $period,
                self::SCOPE,
                $enrollment,
                $domain,
                $data['scale_level_id'] === null ? null : (int) $data['scale_level_id'],
                $this->user(),
            );
        } catch (DomainDecisionException $exception) {
            throw ValidationException::withMessages(['scale_level_id' => $exception->getMessage()]);
        }

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
