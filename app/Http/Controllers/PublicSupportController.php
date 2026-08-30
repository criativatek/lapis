<?php

namespace App\Http\Controllers;

use App\Actions\Support\OpenSupportRequest;
use App\Http\Requests\Support\StorePublicSupportRequestRequest;
use App\Models\SupportCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Fale connosco» — para quem ainda não tem conta, ou não consegue entrar nela.
 *
 * CRIA E MAIS NADA. Não há aqui um `show`, não há pesquisa por referência, não
 * há URL assinada. Um visitante recebe a referência no ecrã e por email, e é
 * tudo o que existe: dar-lhe um portal obrigaria a tratar o endereço de email
 * como credencial de acesso durante toda a vida do pedido, e transformaria a
 * caixa de correio da pessoa numa chave. Quem quer histórico cria conta
 * (ADR-0011 §3).
 *
 * A ROTA É LIMITADA A 5 PEDIDOS POR MINUTO. O IP serve a esse limitador e não
 * é gravado em lado nenhum — nem ele, nem o User-Agent. Sem CAPTCHA na V1: um
 * formulário de ajuda que obriga a decifrar imagens fecha a porta exactamente a
 * quem já está com dificuldades.
 */
class PublicSupportController extends Controller
{
    public function __construct(protected OpenSupportRequest $open) {}

    public function create(): Response
    {
        return Inertia::render('marketing/Contacto', [
            'categories' => SupportCategory::options(),
        ]);
    }

    public function store(StorePublicSupportRequestRequest $request): RedirectResponse
    {
        /** @var array{requester_name: string, requester_email: string, category: string, subject: string, description: string} $dados */
        $dados = $request->validated();

        // Sem utilizador e sem organização: um pedido de visitante fica para
        // sempre sem `user_id`, mesmo que a pessoa crie conta amanhã com o
        // mesmo email. Ligá-los depois seria dar o histórico a quem provou
        // apenas ter o endereço.
        $pedido = $this->open->open($dados);

        return back()->with('supportReference', $pedido->reference);
    }
}
