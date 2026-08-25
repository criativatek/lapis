<?php

namespace Tests\Unit\AcademicYears;

use App\Models\AcademicPeriodKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AcademicPeriodKind::label() e ::pluralLabel() — o nome de uma espécie de
 * período, no singular e no plural.
 *
 * O PLURAL EXISTE PORQUE HÁ UM SÍTIO QUE CONTA PERÍODOS EM VOZ ALTA: «2
 * semestres», «3 períodos», na vista de Ano do calendário. Dizer «períodos» a
 * um ano feito de semestres é descrever mal uma estrutura que está escrita, e
 * está escrita AQUI — não se infere do texto de nenhuma etiqueta.
 *
 * Nenhum dos dois `match` tem braço `default`, pelo que uma espécie nova
 * acrescentada ao enum sem um nome escrito em ambos rebenta aqui em vez de
 * passar em silêncio.
 */
class AcademicPeriodKindLabelTest extends TestCase
{
    #[Test]
    public function every_kind_has_a_singular_and_a_plural_name(): void
    {
        foreach (AcademicPeriodKind::cases() as $kind) {
            $this->assertNotSame('', $kind->label());
            $this->assertNotSame('', $kind->pluralLabel());
            // O plural nunca é o singular: se fosse, contar em voz alta voltava
            // a dar «2 semestre».
            $this->assertNotSame($kind->label(), $kind->pluralLabel());
        }
    }

    #[Test]
    public function each_of_the_five_kinds_is_named_in_portuguese(): void
    {
        $this->assertSame([
            'semester' => ['Semestre', 'Semestres'],
            'term' => ['Período', 'Períodos'],
            'trimester' => ['Trimestre', 'Trimestres'],
            'module' => ['Módulo', 'Módulos'],
            'other' => ['Outro', 'Outros'],
        ], collect(AcademicPeriodKind::cases())
            ->mapWithKeys(fn (AcademicPeriodKind $kind): array => [
                $kind->value => [$kind->label(), $kind->pluralLabel()],
            ])
            ->all());
    }
}
