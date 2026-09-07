<?php

namespace App\Http\Controllers;

use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ContinuousAssessment;
use App\Services\Assessment\DecideDomainAppreciation;
use App\Support\Assessment\DomainDecisionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A APRECIAÇÃO FINAL DE UM DOMÍNIO — a conclusão do ANO, decidida pelo professor.
 *
 * O IRMÃO DESTE CONTROLADOR É `EvaluationSheetDomainDecisionController`, e a
 * única diferença entre os dois é o ÂMBITO: aquele escreve a leitura de um
 * período, este escreve a do ano. O serviço é o mesmo, a validação é a mesma, o
 * rasto de auditoria é o mesmo — `DecideDomainAppreciation` sempre recebeu o
 * âmbito como argumento, e o que faltava era alguém chamá-lo com o outro valor.
 *
 * UMA DECISÃO DE UM SEMESTRE NÃO É UMA DECISÃO DO ANO (§17). São linhas
 * distintas, com chaves distintas, e nenhuma se converte na outra: um professor
 * que tenha lido «Suficiente» em Oralidade no 1.º semestre não disse por isso
 * nada sobre o ano. É por existirem as duas que a distinção se mantém possível.
 *
 * A UNIDADE NÃO VEM NO ENDEREÇO, e é deliberado. A conclusão do ano escreve-se
 * sempre na unidade que o fecha, e essa é derivada — `ContinuousAssessment::
 * finalUnitOf()`, a mesma que a leitura usa. Aceitá-la do cliente abriria a
 * porta a escrever uma decisão numa unidade que ninguém lê: não daria erro, não
 * apareceria em lado nenhum, e ninguém saberia porquê.
 *
 * NADA AQUI TOCA NUM NÚMERO. A média final continua a ser a média final, a
 * proposta continua a ser a proposta, e os resultados de cada unidade não são
 * recalculados. O que muda é a leitura que o professor assume (§3.3).
 */
class FinalDomainDecisionController extends Controller
{
    /**
     * O âmbito do ano. Não há seletor: quem chega a esta rota está a concluir o
     * ano naquele domínio, e uma rota que aceitasse os dois âmbitos deixaria a
     * escolha do que se está a decidir a cargo de quem faz o pedido.
     */
    protected const SCOPE = ClassificationScope::Accumulated;

    public function __construct(
        protected DecideDomainAppreciation $decisions,
    ) {}

    public function store(
        Request $request,
        SchoolClass $class,
        Enrollment $enrollment,
        Domain $domain,
    ): RedirectResponse {
        Gate::authorize('update', $class);

        // A matrícula tem de ser desta turma. O âmbito de organização não faz
        // esta pergunta, e um ulid válido de outra turma da mesma escola é
        // exatamente o IDOR que uma regra `exists:` deixaria passar (ADR-0002).
        abort_unless((int) $enrollment->class_id === (int) $class->getKey(), 404);

        $period = ContinuousAssessment::finalUnitOf($class);

        // Sem unidades configuradas não há ano para concluir. 404 e não uma
        // linha escrita num sítio inventado.
        abort_if($period === null, 404);

        $data = $request->validate([
            // Nunca uma regra `exists:` — corre no query builder e não vê o
            // âmbito de organização (ADR-0002). Que o nível é desta escala é
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
