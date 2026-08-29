<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Preços praticados, em cêntimos
    |--------------------------------------------------------------------------
    |
    | Por chave de plano. `float` nunca toca em dinheiro, pela mesma razão que
    | nunca toca numa classificação (§24.4).
    |
    | O Base é gratuito e o Institucional é sob consulta — nenhum dos dois está
    | aqui, e é isso que faz o checkout recusá-los, em vez de haver uma lista de
    | exclusões noutro sítio a ficar desactualizada.
    |
    | ISTO É O QUE SE PEDE AO CLIENTE, e não o que se conclui que ele pagou. O
    | `RecordSubscriptionPayment` continua a registar exactamente o valor que
    | entrou, venha ele daqui ou não.
    |
    */

    'prices' => [
        'pro' => (int) env('BILLING_PRO_PRICE_CENTS', 4490),
    ],

    /*
    | A condição Membro Fundador, como a landing a promete: «os primeiros 250»
    | e «até 31 de dezembro de 2026», o que ocorrer primeiro.
    |
    | O SERVIDOR TEM DE SABER CONTAR. O número vivia só em `commercial.ts`, que
    | não conta nada — o 251.º comprador veria o preço de fundador numa página
    | sem forma de saber que era o 251.º. Ver App\Support\Commercial\FounderAvailability.
    */

    'founder' => [
        'price_cents' => (int) env('BILLING_FOUNDER_PRICE_CENTS', 2990),
        'seats' => (int) env('BILLING_FOUNDER_SEATS', 250),
        // Inclusivo: um pedido feito nesse dia ainda conta.
        'deadline' => env('BILLING_FOUNDER_DEADLINE', '2026-12-31'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Promoções por tempo limitado
    |--------------------------------------------------------------------------
    |
    | A landing diz «Gratuito no ano letivo 2026/27» sobre o plano Base
    | (`commercial.ts:69`, `LandingFaq.vue:67`, `LandingSeo.php:143`) e até aqui
    | isso não estava escrito em lado nenhum da base de dados: uma conta Base
    | criada hoje ficava com as quatro colunas de snapshot a NULL, e «quem
    | aderiu ao abrigo da promoção» era arqueologia sobre `created_at`.
    |
    | UMA DATA, NÃO DUAS. É ao mesmo tempo o fim do termo comercial e o fim da
    | janela de adesão, porque é isso que a frase promete: quem criar conta em
    | junho de 2027 ainda adere «no ano letivo 2026/27», e é gratuito até ao fim
    | desse mesmo ano letivo. Duas datas seriam duas promessas, e a página só
    | faz uma.
    |
    | O QUE ACONTECE DEPOIS DESTA DATA NÃO ESTÁ AQUI, e é deliberado
    | (ADR-0008 §8): `commercial_term_ends_at` é prova do que foi acordado e
    | nada no resolvedor de direitos a lê. Terminada a condição, a conta deve
    | uma conversa, não um corte de acesso.
    |
    */

    'promotions' => [
        'free_base' => [
            'enabled' => (bool) env('BILLING_FREE_BASE_ENABLED', true),
            // Fim do ano letivo 2026/27. Inclusivo: uma adesão feita nesse dia
            // ainda é feita ao abrigo da promoção.
            'ends_at' => env('BILLING_FREE_BASE_ENDS_AT', '2027-08-31'),
        ],
    ],

    'currency' => env('BILLING_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Transferência bancária
    |--------------------------------------------------------------------------
    |
    | Não há gateway: o cliente transfere e um administrador confirma. É lento e
    | é honesto — e continuará a fazer sentido depois de haver gateway, porque é
    | assim que uma escola paga.
    |
    | FICA NO .env E NÃO NA BASE DE DADOS, ao contrário do SMTP e do endereço de
    | contacto. Um IBAN é para onde vai dinheiro: numa tabela, quem entrasse no
    | backoffice redirecionava os pagamentos sem tocar no servidor.
    |
    */

    'bank_transfer' => [
        'enabled' => (bool) env('BILLING_BANK_TRANSFER_ENABLED', true),
        'beneficiary' => env('BILLING_BANK_BENEFICIARY'),
        'iban' => env('BILLING_BANK_IBAN'),
        'bic' => env('BILLING_BANK_BIC'),

        // Quantos dias o pedido fica de pé. Não cobra nada por si — deixa de
        // aparecer como pendente a quem confirma.
        'window_days' => (int) env('BILLING_BANK_WINDOW_DAYS', 14),
    ],

    /*
    | O prefixo da referência que o cliente escreve na descrição da
    | transferência. É por ela que se liga uma linha do extrato a um pedido,
    | muitas vezes lida ao telefone — por isso curta e sem caracteres que se
    | confundam.
    */

    'reference_prefix' => env('BILLING_REFERENCE_PREFIX', 'LPRO'),

];
