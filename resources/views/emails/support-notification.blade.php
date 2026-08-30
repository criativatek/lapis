{{--
    O aviso de um pedido de suporte.

    O QUE ESTE EMAIL NÃO LEVA, e é a razão de existir assim: o assunto que a
    pessoa escreveu, a descrição do problema, e o corpo de qualquer mensagem do
    fio. Um pedido de suporte contém quase sempre mais dados pessoais do que
    qualquer outro texto que um professor escreve, e um email atravessa
    servidores que não são nossos para ficar numa caixa de entrada que não
    controlamos. Sai daqui a referência, a categoria, o estado — e um caminho
    para o sítio onde o conteúdo está protegido por autenticação.

    O RODAPÉ DIZ A VERDADE SOBRE AS RESPOSTAS. Não há processamento de email de
    entrada na V1: responder a esta mensagem chega à caixa de suporte e não
    entra no histórico do pedido. Quem não o disser aqui deixa a pessoa
    descobrir sozinha que a resposta se perdeu.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Suporte — Lapispro') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:8px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="font-size:18px; margin:0 0 16px;">{{ __('Lapispro') }}</h1>

                            <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                @if ($forTeam)
                                    {{ __('Entrou um pedido de suporte novo.') }}
                                @elseif ($type === \App\Models\SupportNotificationType::RequestReplied)
                                    {{ __('Há uma resposta nova no seu pedido de suporte.') }}
                                @elseif ($type === \App\Models\SupportNotificationType::WaitingReminder)
                                    {{ __('O seu pedido de suporte está à espera de uma resposta sua.') }}
                                @else
                                    {{ __('Recebemos o seu pedido de suporte.') }}
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; font-size:14px; line-height:1.8;">
                                <tr>
                                    <td style="color:#71717a; padding-right:12px;">{{ __('Referência') }}</td>
                                    <td style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $reference }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#71717a; padding-right:12px;">{{ __('Assunto') }}</td>
                                    <td>{{ $categoryLabel }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#71717a; padding-right:12px;">{{ __('Estado') }}</td>
                                    <td>{{ $statusLabel }}</td>
                                </tr>
                            </table>

                            @if ($url !== null)
                                <p style="text-align:center; margin:24px 0;">
                                    <a href="{{ $url }}" style="display:inline-block; background-color:#18181b; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:6px; font-size:15px;">
                                        {{ $forTeam ? __('Abrir no backoffice') : __('Ver pedido') }}
                                    </a>
                                </p>
                            @endif

                            @unless ($forTeam)
                                <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0 0 8px;">
                                    {{ __('Guarde esta referência: é por ela que identificamos o seu pedido.') }}
                                </p>
                            @endunless

                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:16px 0 0; border-top:1px solid #e4e4e7; padding-top:16px;">
                                {{ __('Se responder a este email, a sua mensagem chega à nossa caixa de suporte — mas não fica guardada no histórico do pedido no Lapispro.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
