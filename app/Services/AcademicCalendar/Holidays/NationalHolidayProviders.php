<?php

namespace App\Services\AcademicCalendar\Holidays;

use Illuminate\Contracts\Container\Container;

/**
 * Que provider responde por que país — e um `null` honesto quando nenhum responde.
 *
 * UM ARRAY COM UMA ENTRADA, e é isso que isto tem de ser. A tentação era desenhar
 * um sistema de extensões para os países que ainda não existem: um contrato de
 * registo, uma descoberta automática, um ficheiro de configuração. Nada disso tem
 * hoje um segundo caso para justificar a forma, e uma abstração desenhada contra
 * necessidades imaginadas acerta quase sempre no problema errado. Quando houver um
 * segundo país, este array cresce uma linha e o desenho que ele então precisar
 * escreve-se com esse país à frente.
 *
 * `null` E NUNCA UM RECURSO A PORTUGAL. Um ano letivo declarado espanhol não pode
 * receber o calendário português em silêncio: o professor teria treze feriados
 * errados no seu ano e nada no ecrã lhe dizia porquê. Quem chama isto tem de saber
 * dizer «não há» — ver NationalHolidaySuggestionController.
 */
class NationalHolidayProviders
{
    /**
     * @var array<string, class-string<NationalHolidayProvider>>
     */
    private const PROVIDERS = [
        'PT' => PortugalNationalHolidayProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  string|null  $countryCode  o `country_code` do ano letivo — o único sítio onde o país deste calendário se decide (§16)
     */
    public function for(?string $countryCode): ?NationalHolidayProvider
    {
        $code = mb_strtoupper(trim((string) $countryCode));

        if ($code === '' || ! isset(self::PROVIDERS[$code])) {
            return null;
        }

        /** @var NationalHolidayProvider */
        return $this->container->make(self::PROVIDERS[$code]);
    }

    /**
     * Os países que esta versão sabe — para o ecrã poder dizer QUAL é o que há, em
     * vez de só dizer que este não é.
     *
     * @return list<string>
     */
    public function supportedCountryCodes(): array
    {
        return array_keys(self::PROVIDERS);
    }
}
