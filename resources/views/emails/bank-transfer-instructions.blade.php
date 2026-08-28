<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Dados para pagamento') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:8px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="font-size:18px; margin:0 0 16px;">{{ config('app.name') }}</h1>

                            <p style="font-size:15px; line-height:1.6; margin:0 0 20px;">
                                {{ __('Recebemos o seu pedido de subscrição do :plan. Para o concluir, faça uma transferência com os dados abaixo.', ['plan' => $planName]) }}
                            </p>

                            {{-- A referência primeiro e em destaque: é o único
                                 campo que, se for esquecido, faz o pagamento
                                 chegar sem se saber de quem é. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7; border-radius:6px; padding:16px; margin:0 0 20px;">
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Referência (escreva-a na descrição)') }}</td></tr>
                                <tr><td style="font-size:20px; font-weight:600; letter-spacing:0.06em; padding-bottom:16px;">{{ $reference }}</td></tr>
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Valor') }}</td></tr>
                                <tr><td style="font-size:20px; font-weight:600; padding-bottom:16px;">{{ $amount }}</td></tr>
                                @if ($beneficiary)
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Beneficiário') }}</td></tr>
                                <tr><td style="font-size:15px; padding-bottom:16px;">{{ $beneficiary }}</td></tr>
                                @endif
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('IBAN') }}</td></tr>
                                <tr><td style="font-size:15px; letter-spacing:0.04em;{{ $bic ? ' padding-bottom:16px;' : '' }}">{{ $iban }}</td></tr>
                                @if ($bic)
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('BIC/SWIFT') }}</td></tr>
                                <tr><td style="font-size:15px;">{{ $bic }}</td></tr>
                                @endif
                            </table>

                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0 0 8px;">
                                {{ __('O plano é ativado depois de confirmarmos a entrada do pagamento. Até lá a sua conta mantém o plano atual e nada se perde.') }}
                            </p>

                            @if ($expiresAt)
                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0 0 8px;">
                                {{ __('Estes dados são válidos até :date.', ['date' => $expiresAt->translatedFormat('d \d\e F \d\e Y')]) }}
                            </p>
                            @endif

                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0;">
                                {{ __('Se não foi o senhor a pedir esta subscrição, ignore este email — nada foi cobrado nem alterado.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
