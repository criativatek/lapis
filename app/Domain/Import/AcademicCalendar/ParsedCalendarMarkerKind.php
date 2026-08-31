<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * PORQUE É QUE uma data lida da grelha vai para o calendário do professor em vez
 * de ir para a estrutura do ano — e é só isso que isto responde.
 *
 * As duas razões são diferentes e o professor merece ler a certa. Um `CohortEnd`
 * não é classificável porque a aplicação NÃO MODELA COORTES; um `SchoolEvent` é
 * perfeitamente classificável — é um acontecimento escolar — e só não é uma
 * exceção letiva porque não impede aula nenhuma. Dizer «esta aplicação não sabe
 * classificar isto» sobre uma reunião seria falso.
 *
 * NÃO É O TIPO DO ACONTECIMENTO. Isso é `CalendarEventType`, e viaja ao lado:
 * um `SchoolEvent` tanto pode ser uma reunião como uma atividade como uma data
 * relevante.
 */
enum ParsedCalendarMarkerKind
{
    /** «Fim 9.º ano», «Fim 5/6/7/8.º» — o fim do ano letivo de um grupo de anos de escolaridade. */
    case CohortEnd;

    /** Uma reunião, uma atividade, um convívio, uma data que o documento marca e mais nada. */
    case SchoolEvent;
}
