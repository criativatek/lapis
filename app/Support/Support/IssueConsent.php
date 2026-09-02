<?php

namespace App\Support\Support;

/**
 * O que quem reporta aceitou, e o que aquele aceite cobria.
 *
 * SEM ÂMBITO, UM ACEITE NÃO SERVE PARA NADA. Saber que alguém carregou numa
 * caixa não diz o que estava dentro do que aceitou: um aceite dado quando não
 * havia imagem não cobre a imagem que apareceu a seguir. Por isso guarda-se o
 * âmbito, e não só o facto.
 *
 * A CERTIFICAÇÃO DA IMAGEM É UMA DECISÃO À PARTE do aceite geral, e é uma
 * CONDIÇÃO e não um campo. Uma captura de ecrã do Lapispro é, por construção,
 * uma imagem dos dados sobre que o defeito é. Quem a envia tem de a ter visto e
 * de o dizer — e se não disser, a imagem não segue e o reporte segue sem ela.
 * Bloquear o envio inteiro ensinaria a marcar sem olhar, que é o oposto do que
 * esta caixa existe para fazer.
 *
 * A REGRA VIVE AQUI, NO SERVIDOR. A caixa no ecrã é como a pessoa a exerce, não
 * onde ela é imposta: uma imagem que chegue sem certificação é descartada,
 * mesmo que o cliente jure que ela foi marcada.
 */
class IssueConsent
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'consent_terms_version' => ['nullable', 'string', 'max:40'],
            'screenshot_certified' => ['nullable', 'boolean'],
            // O aviso concreto que foi mostrado à pessoa no momento de decidir
            // — «esta página mostra nomes de alunos» — ou a sua ausência. Sem
            // isto, não se sabe se a certificação foi informada.
            'screenshot_warning' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** Se este aceite é válido para a versão em vigor dos Termos. */
    public static function isCurrent(?string $version): bool
    {
        return $version !== null && $version !== '' && $version === self::currentVersion();
    }

    public static function currentVersion(): string
    {
        return (string) config('lapis.legal.terms_effective_from');
    }

    /**
     * O âmbito, tal como fica gravado.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function scope(array $data, int $screenshots, int $uploads): array
    {
        return [
            'screenshot_certified' => (bool) ($data['screenshot_certified'] ?? false),
            'screenshot_warning' => $data['screenshot_warning'] ?? null,
            'screenshots' => $screenshots,
            'uploads' => $uploads,
        ];
    }
}
