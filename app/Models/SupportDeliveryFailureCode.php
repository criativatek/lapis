<?php

namespace App\Models;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * COMO A ENTREGA FALHOU — classificado da CLASSE da excepção e do código SMTP
 * numérico, nunca do texto.
 *
 * A mensagem de uma falha de SMTP cita quase sempre o endereço de destino e,
 * com frequência, a resposta inteira do servidor. Guardá-la reintroduziria na
 * tabela técnica exactamente o dado pessoal que a ausência de uma coluna
 * `recipient_email` recusa — e um stack trace acrescentaria caminhos de
 * ficheiros e, no pior caso, credenciais de transporte. Por isso o que fica
 * gravado é um destes cinco valores e mais nada.
 *
 * O MAPEAMENTO É VERIFICÁVEL NO SYMFONY MAILER, não inferido:
 *
 *  - `UnexpectedResponseException` é lançada por `SmtpTransport` com o **código
 *    SMTP numérico** como código da excepção (`assertResponseCode()` faz
 *    `sscanf($response, '%3d')` e passa-o ao construtor). Dá para classificar
 *    lendo `getCode()`, sem tocar na mensagem.
 *  - `TransportExceptionInterface` sem código é falha de ligação: o servidor
 *    não respondeu de todo.
 *  - Tudo o resto é `Unknown`, por decisão: um código que não se sabe mapear
 *    com segurança é um código que não se inventa.
 *
 * NÃO EXISTE `timeout`, e é deliberado. O Symfony sinaliza um tempo esgotado
 * como `TransportException` genérica, distinguível apenas pela mensagem — e ler
 * a mensagem é precisamente o que este enum existe para não fazer. Um valor que
 * nada consegue produzir em segurança é um valor morto no vocabulário.
 */
enum SupportDeliveryFailureCode: string
{
    /** O servidor não respondeu: rede, DNS, porta, TLS. */
    case SmtpConnection = 'smtp_connection';

    /** O servidor recusou as credenciais (5xx de autenticação). */
    case SmtpAuth = 'smtp_auth';

    /** O servidor recusou o destinatário. Quase sempre um endereço errado. */
    case InvalidRecipient = 'invalid_recipient';

    /** O servidor recusou a mensagem por outra razão sua. */
    case RejectedByServer = 'rejected_by_server';

    /** Não classificável com segurança. Melhor do que adivinhar. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::SmtpConnection => __('Sem ligação ao servidor de email'),
            self::SmtpAuth => __('Credenciais recusadas'),
            self::InvalidRecipient => __('Destinatário recusado'),
            self::RejectedByServer => __('Mensagem recusada pelo servidor'),
            self::Unknown => __('Falha não classificada'),
        };
    }

    /**
     * Classifica uma excepção de envio SEM LER A SUA MENSAGEM.
     *
     * Só a classe e, quando existe, o código SMTP numérico. Ver o comentário do
     * enum para a origem de cada ramo.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof UnexpectedResponseException) {
            return self::fromSmtpCode($exception->getCode());
        }

        if ($exception instanceof TransportExceptionInterface) {
            // Um transporte que falha sem resposta do servidor não chegou a
            // falar com ele: é ligação, não recusa.
            return $exception->getCode() === 0
                ? self::SmtpConnection
                : self::fromSmtpCode($exception->getCode());
        }

        return self::Unknown;
    }

    /**
     * Os códigos SMTP que se sabem ler, e só esses.
     *
     * 530/534/535/538 são as recusas de autenticação do RFC 4954. 550/551/553
     * são as do destinatário. Os restantes 4xx e 5xx são recusas do servidor
     * cuja causa não se pode afirmar sem ler o texto — e não se lê.
     */
    protected static function fromSmtpCode(int $code): self
    {
        return match (true) {
            in_array($code, [530, 534, 535, 538], true) => self::SmtpAuth,
            in_array($code, [550, 551, 553], true) => self::InvalidRecipient,
            $code >= 400 && $code < 600 => self::RejectedByServer,
            default => self::Unknown,
        };
    }
}
