{{--
    O aviso de um pedido de suporte.

    O QUE ESTE EMAIL NÃO LEVA, e é a razão de existir assim: o resumo que a
    pessoa escreveu, a descrição do problema, e o corpo de qualquer mensagem do
    fio. Um pedido de suporte contém quase sempre mais dados pessoais do que
    qualquer outro texto que um professor escreve, e um email atravessa
    servidores que não são nossos para ficar numa caixa de entrada que não
    controlamos. Sai daqui a referência, a categoria, o estado — e um caminho
    para o sítio onde o conteúdo está protegido por autenticação.

    A CONFIRMAÇÃO DE RECEÇÃO É A ÚNICA QUE CUMPRIMENTA PELO NOME, e esse é o
    único dado pessoal que este template acrescenta: o primeiro nome de quem
    pediu, a caminho da caixa de correio dessa mesma pessoa. Não é conteúdo do
    pedido, e é a diferença entre uma mensagem escrita para alguém e um recibo.

    O «PEDIDO:» É A CATEGORIA, NUNCA O RESUMO. O campo que o formulário chama
    «Resumo» é texto livre, e é exactamente onde é mais fácil escrever o nome de
    um aluno sem pensar. `SupportCategory` é vocabulário fechado: chega para a
    pessoa saber de que pedido se trata, e não pode identificar ninguém.

    O QUE MUDA ENTRE TER CONTA E NÃO TER não é o tom, é a PROMESSA. A quem tem
    conta diz-se que pode acompanhar o pedido, porque pode; a um visitante
    diz-se que a equipa lhe escreve para este endereço, porque é tudo o que
    existe para ele (ADR-0011 §3). Prometer um portal a quem não o tem seria
    mandá-lo procurar um ecrã que não existe.

    O RODAPÉ DIZ A VERDADE SOBRE AS RESPOSTAS. Não há processamento de email de
    entrada na V1: responder a esta mensagem chega à caixa de suporte e não
    entra no histórico do pedido. Quem não o disser aqui deixa a pessoa
    descobrir sozinha que a resposta se perdeu.
--}}
@php
    /** A confirmação de receção tem corpo próprio; os restantes avisos partilham o antigo. */
    $isAcknowledgement = ! $forTeam && $type === \App\Models\SupportNotificationType::RequestReceived;
@endphp
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

                            @if ($isAcknowledgement)
                                <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                    @if ($firstName !== null)
                                        {{ __('Olá, :name,', ['name' => $firstName]) }}
                                    @else
                                        {{ __('Olá,') }}
                                    @endif
                                </p>

                                <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                    {{ __('Recebemos o seu pedido de suporte e já ficou registado com a referência :reference.', ['reference' => $reference]) }}
                                </p>

                                <p style="font-size:15px; line-height:1.6; margin:0 0 24px;">
                                    {{ __('Lamentamos o incómodo que motivou o seu contacto. A nossa equipa irá analisar a situação com atenção e procurará dar-lhe resposta com a maior brevidade possível.') }}
                                </p>

                                {{-- A referência, destacada: é o que a pessoa cita ao telefone. --}}
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; background-color:#f4f4f5; border-radius:6px;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <p style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#71717a; margin:0 0 4px;">
                                                {{ __('Referência') }}
                                            </p>
                                            <p style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:20px; font-weight:600; margin:0 0 12px;">
                                                {{ $reference }}
                                            </p>
                                            <p style="font-size:14px; line-height:1.5; color:#3f3f46; margin:0;">
                                                {{ __('Pedido: :subject', ['subject' => $categoryLabel]) }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                @if ($authenticated)
                                    <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                        {{ __('Pode acompanhar este pedido e consultar as respostas da equipa de suporte diretamente no Lapispro.') }}
                                    </p>

                                    @if ($url !== null)
                                        <p style="text-align:center; margin:24px 0;">
                                            <a href="{{ $url }}" style="display:inline-block; background-color:#18181b; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:6px; font-size:15px;">
                                                {{ __('Ver pedido') }}
                                            </a>
                                        </p>
                                    @endif

                                    <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                        {{ __('Para proteger a privacidade dos seus alunos, recomendamos que não envie por email dados pessoais, informação de saúde ou outros dados sensíveis. Sempre que for necessário acrescentar informação ao pedido, utilize a área de suporte do Lapispro.') }}
                                    </p>
                                @else
                                    <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                        {{ __('A nossa equipa entrará em contacto através deste endereço de email sempre que for necessária informação adicional ou quando houver novidades sobre o pedido.') }}
                                    </p>

                                    <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                        {{ __('Para proteger a privacidade dos alunos, evite responder a este email com dados pessoais, informação de saúde ou outros dados sensíveis.') }}
                                    </p>
                                @endif

                                <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                    {{ __('Obrigado por nos ter contactado e por nos ajudar a melhorar o Lapispro.') }}
                                </p>

                                <p style="font-size:15px; line-height:1.6; margin:0;">
                                    {{ __('Equipa de Suporte Lapispro') }}
                                </p>

                                <p style="font-size:13px; line-height:1.6; color:#71717a; margin:24px 0 0; border-top:1px solid #e4e4e7; padding-top:16px;">
                                    {{ __('Esta mensagem é uma confirmação automática da receção do seu pedido. As respostas enviadas diretamente para este email não são adicionadas ao pedido no Lapispro.') }}
                                </p>
                            @else
                                <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                    @if ($forTeam)
                                        {{ __('Entrou um pedido de suporte novo.') }}
                                    @elseif ($type === \App\Models\SupportNotificationType::RequestReplied)
                                        {{ __('Há uma resposta nova no seu pedido de suporte.') }}
                                    @else
                                        {{ __('O seu pedido de suporte está à espera de uma resposta sua.') }}
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
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
