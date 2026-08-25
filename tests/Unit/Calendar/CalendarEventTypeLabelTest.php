<?php

namespace Tests\Unit\Calendar;

use App\Models\CalendarEventType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CalendarEventType::label() e ::shortLabel() — o nome que o professor lê para
 * cada uma das quatro espécies de acontecimento, por extenso e na forma curta
 * que cabe numa célula da grelha.
 *
 * ESTE FICHEIRO EXISTE SOBRETUDO POR CAUSA DE UMA LINHA. `Other` é a única cujo
 * VALOR e cujo RÓTULO não se parecem: o valor guardado é `other` — está escrito
 * em linhas que já existem na base de dados desde a Fase 5.3 — e o que se lê é
 * «Data relevante». As duas asserções ficam lado a lado de propósito, porque a
 * maneira mais fácil de estragar isto é «arrumar» a divergência num dos dois
 * sentidos: renomear o valor (uma migração a troco de nada, e todas as linhas
 * antigas por trás) ou repor o rótulo antigo.
 *
 * «DATA RELEVANTE» NÃO É UMA EXCEÇÃO LETIVA e o rótulo tem de continuar a não o
 * sugerir. Não diz que naquele dia não há aula — isso é uma
 * AcademicCalendarException, outra tabela e outra decisão; diz que é uma data
 * que importa e que não é uma reunião, uma atividade nem uma visita.
 *
 * Nenhum dos dois `match` do enum tem braço `default`, pelo que uma espécie nova
 * acrescentada sem um nome escrito em ambos rebenta aqui em vez de passar em
 * silêncio.
 */
class CalendarEventTypeLabelTest extends TestCase
{
    #[Test]
    public function each_of_the_four_kinds_is_named_in_portuguese(): void
    {
        $this->assertSame([
            'meeting' => ['Reunião', 'REUNIÃO'],
            'activity' => ['Atividade', 'ATIVIDADE'],
            'field_trip' => ['Visita de estudo', 'VISITA'],
            'other' => ['Data relevante', 'DATA RELEVANTE'],
        ], collect(CalendarEventType::cases())
            ->mapWithKeys(fn (CalendarEventType $type): array => [
                $type->value => [$type->label(), $type->shortLabel()],
            ])
            ->all());
    }

    /**
     * O VALOR NÃO SE MEXE. Renomear a etiqueta é uma mudança de texto e nada
     * mais: cada linha `type = 'other'` gravada antes disto continua a ser
     * exatamente a mesma linha, sem migração nenhuma.
     */
    #[Test]
    public function the_stored_value_of_data_relevante_is_still_other(): void
    {
        $this->assertSame('other', CalendarEventType::Other->value);
        $this->assertSame(CalendarEventType::Other, CalendarEventType::from('other'));
        $this->assertSame(
            ['meeting', 'activity', 'field_trip', 'other'],
            CalendarEventType::values(),
        );
    }

    #[Test]
    public function every_kind_has_a_written_word_and_never_relies_on_colour(): void
    {
        foreach (CalendarEventType::cases() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertNotSame('', $type->shortLabel());
        }
    }
}
