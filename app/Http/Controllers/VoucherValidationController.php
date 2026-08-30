<?php

namespace App\Http\Controllers;

use App\Models\VoucherBenefitType;
use App\Support\Commercial\VoucherCode;
use App\Support\Commercial\Vouchers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A landing pergunta «este código vale?» — e recebe uma resposta VERDADEIRA.
 *
 * Foi o fim do fake success: até aqui a página aceitava um código e respondia
 * «esta página não valida códigos». Agora valida — contra o motor — e continua
 * sem fingir mais do que sabe:
 *
 *  - **SEM SESSÃO, SEM ORGANIZAÇÃO.** Quem pergunta ainda nem conta tem, e por
 *    isso «já foi utilizado por esta conta» não é verificável aqui. É-o no
 *    resgate, autenticado, que continua a ser o único sítio onde algo se
 *    consome. Este endpoint NUNCA resgata nada.
 *  - **TRÊS CATEGORIAS CÁ PARA FORA**, achatadas por
 *    `VoucherOutcome::publicCategory()`: dizer «não existe» vs. «foi
 *    desactivado» a quem anda a adivinhar seria entregar o mapa dos códigos.
 *    Throttled na rota pela mesma razão.
 *  - **NUNCA DIZ QUANTO VALE.** O benefício é do código e mostra-se a quem o
 *    resgata, autenticado. Aqui diz-se apenas que vale e ONDE se resgata — o
 *    checkout para os com preço, a página do plano para os `free_until`.
 */
class VoucherValidationController extends Controller
{
    public function __construct(protected Vouchers $vouchers) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $resolution = $this->vouchers->resolve($validated['code']);

        $category = $resolution->outcome->publicCategory();

        return response()->json([
            'category' => $category,
            'message' => match ($category) {
                'valid' => __('O código :code é válido. Resgata-o :where, e é aí que o benefício é confirmado e aplicado.', [
                    'code' => VoucherCode::present($validated['code']),
                    'where' => $resolution->voucher?->benefit_type === VoucherBenefitType::FreeUntil
                        ? __('na página do plano, depois de entrar na sua conta')
                        : __('no checkout, depois de entrar na sua conta'),
                ]),
                'expired' => __('Este código já não está disponível: expirou ou atingiu o limite de utilizações.'),
                default => __('Este código não é reconhecido. Verifique se o escreveu como o recebeu.'),
            },
        ]);
    }
}
