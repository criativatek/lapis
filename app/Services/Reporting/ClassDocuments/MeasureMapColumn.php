<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Models\InterventionStatus;

/**
 * The two columns of the Mapa de Medidas Educativas — a PRESENTATION enum,
 * never stored.
 *
 * Nothing persisted knows about it: `interventions.status` keeps its five
 * values and no column or migration was added. This only answers «in which
 * column of the printed map does a measure in this state belong, if any».
 *
 * THE MATCH HAS NO `default` ON PURPOSE. A sixth InterventionStatus must stop
 * this from passing static analysis until somebody decides where it goes (or
 * that it goes nowhere). With a `default` it would silently land in whichever
 * column the default named, on a document that leaves the classroom.
 */
enum MeasureMapColumn: string
{
    case Implemented = 'implemented';
    case Planned = 'planned';

    /**
     * The column's heading — the one place the map's own vocabulary is
     * written, so the screen and the PDF cannot title it differently.
     */
    public function label(): string
    {
        return match ($this) {
            self::Implemented => __('Implementadas'),
            self::Planned => __('A implementar'),
        };
    }

    /**
     * The states whose measures are printed under this column.
     *
     * @return list<InterventionStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            InterventionStatus::cases(),
            fn (InterventionStatus $status): bool => self::forStatus($status) === $this,
        ));
    }

    /**
     * The column a measure in this state is printed under, or NULL when it is
     * not printed at all.
     *
     *   New        → Planned. InterventionStatus documents it as «something
     *                decided and not yet under way» — exactly «a implementar».
     *   InProgress → Implemented. It is under way, so it is being implemented.
     *   Concluded  → Implemented. It was carried out; leaving it off the map
     *                would erase what the student has had.
     *   Suspended  → Implemented. «Paused, and may resume»: it was started and
     *                has not been abandoned, so it still belongs to what has
     *                been put in place. The item prints its real state
     *                («Suspensa»), so the map never claims it is running.
     *   Cancelled  → none. «Abandoned», and kept only for rows that already
     *                said so (legacy): it is neither something in place nor
     *                something still to come.
     */
    public static function forStatus(InterventionStatus $status): ?self
    {
        return match ($status) {
            InterventionStatus::New => self::Planned,
            InterventionStatus::InProgress => self::Implemented,
            InterventionStatus::Concluded => self::Implemented,
            InterventionStatus::Suspended => self::Implemented,
            InterventionStatus::Cancelled => null,
        };
    }
}
