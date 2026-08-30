<?php

namespace App\Models;

/**
 * O QUE UM CÓDIGO CARREGA — a família do benefício, e nada mais.
 *
 * Três casos, e os três são COMERCIAIS: mexem no preço e no termo, nunca no que
 * a conta pode fazer. Nenhum deles desbloqueia um módulo, nenhum toca em
 * `plan_version_id` e nenhum é lido por
 * `App\Support\Entitlements\Entitlements` — é a mesma regra que
 * `CommercialCondition` foi escrito para proteger, e um voucher não é sítio para
 * a quebrar.
 *
 * A LISTA ESTÁ FECHADA NESTES TRÊS, aqui e na CHECK constraint da migração.
 * Nada neste enum antecipa famílias futuras: quando uma for decidida, terá o
 * seu próprio ADR, e alargar a lista — nos dois sítios — será parte dessa
 * fatia, não desta.
 */
enum VoucherBenefitType: string
{
    /**
     * Um preço final explícito. «Este código dá-lhe o Pro por 19,90 €.»
     *
     * NÃO É UM DESCONTO. Não se calcula a partir de tabela nenhuma, e por isso
     * uma alteração do preço de tabela não mexe no que este código promete.
     */
    case FixedPrice = 'fixed_price';

    /**
     * Uma percentagem sobre o preço em vigor no momento do resgate.
     *
     * É a única família cujo valor depende de algo exterior ao voucher, e é por
     * isso que o RESULTADO é congelado no resgate: 30 % era o benefício, 31,43 €
     * foi o contrato, e ninguém tem de repetir a aritmética — nem o
     * arredondamento — sobre um preço de tabela que entretanto mudou.
     */
    case PercentDiscount = 'percent_discount';

    /**
     * Gratuito até uma data.
     *
     * A data é `commercial_term_ends_at` e NUNCA `ends_at`: acabado o termo, a
     * conta deve uma conversa, não um corte de acesso (ADR-0008 §8). É a mesma
     * forma que a promoção do Base gratuito já tem, com a diferença de que esta
     * é atribuída por um código em vez de por uma janela de calendário.
     */
    case FreeUntil = 'free_until';

    public function label(): string
    {
        return match ($this) {
            self::FixedPrice => __('Preço fixo'),
            self::PercentDiscount => __('Desconto percentual'),
            self::FreeUntil => __('Gratuito até uma data'),
        };
    }

    /**
     * Se este benefício decide a QUANTIA de um pagamento.
     *
     * Os dois primeiros decidem quanto se transfere; o terceiro dispensa a
     * transferência por completo. É esta pergunta — e não uma lista de casos
     * repetida em cada chamador — que separa os dois caminhos do checkout.
     */
    public function isPriced(): bool
    {
        return $this !== self::FreeUntil;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
