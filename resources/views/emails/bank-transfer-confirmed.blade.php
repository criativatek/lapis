<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Recebemos o seu pagamento') }}</title>
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
                                {{ __('Recebemos a sua transferência. Obrigado — e bem-vindo ao :plan.', ['plan' => $planName]) }}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7; border-radius:6px; padding:16px; margin:0 0 20px;">
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Valor recebido') }}</td></tr>
                                <tr><td style="font-size:20px; font-weight:600; padding-bottom:16px;">{{ $amount }}</td></tr>
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Referência') }}</td></tr>
                                <tr><td style="font-size:15px; letter-spacing:0.06em;{{ $periodEndsAt ? ' padding-bottom:16px;' : '' }}">{{ $reference }}</td></tr>
                                @if ($periodEndsAt)
                                <tr><td style="font-size:12px; color:#71717a; padding-bottom:4px;">{{ __('Subscrição válida até') }}</td></tr>
                                <tr><td style="font-size:15px;">{{ $periodEndsAt->translatedFormat('d \d\e F \d\e Y') }}</td></tr>
                                @endif
                            </table>

                            {{-- Honesto sobre o que ainda falta: activar o plano é
                                 um acto à parte, e pode acontecer minutos depois.
                                 Dizer «a sua conta é agora Pro» quando ainda não é
                                 gera exactamente o email de resposta que este email
                                 existe para evitar. --}}
                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0 0 8px;">
                                {{ __('Vamos ativar o seu plano. Se dentro de algumas horas ainda não o vir alterado na sua conta, responda a este email com a referência acima.') }}
                            </p>

                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0;">
                                {{ __('A subscrição é anual e não é renovada automaticamente — avisamo-lo antes de terminar.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
