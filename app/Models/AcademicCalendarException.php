<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AcademicCalendarExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma «exceção letiva» (Fase 5.4) — um feriado, uma interrupção letiva ou um
 * dia não letivo: uma data em que a aula NÃO acontece.
 *
 * ESTRUTURA DO ANO, E NÃO UM ACONTECIMENTO. Isto vive ao lado do
 * AcademicPeriod, e não ao lado do CalendarEvent, e a diferença não é de
 * arrumação — é de natureza:
 *
 *   - um CalendarEvent é PESSOAL (`user_id`, CalendarEventPolicy) e nunca
 *     impede uma aula: uma reunião às 18h não apaga a aula das 10h;
 *   - uma exceção é da ORGANIZAÇÃO inteira, exatamente como um período
 *     (`organization_id` e mais nada — não há dono), e é a única coisa deste
 *     calendário que diz «neste dia não há aula».
 *
 * É por isso que tem `academic_year_id` e o CalendarEvent não tem: um feriado
 * não é apenas uma data solta, é uma decisão sobre a forma DESTE ano letivo, e
 * é editada exatamente onde os períodos são editados — em «Estrutura do Ano
 * Letivo», pela mesma pessoa, sob a mesma AcademicYearPolicy.
 *
 * ESTA FASE NÃO MATERIALIZA NADA. Nenhuma aula é criada, apagada ou marcada a
 * partir daqui: isso é a fase seguinte, que lê este modelo. Aqui há o modelo,
 * o CRUD e a leitura no calendário, e mais nada.
 *
 * UM DIA SÓ É `starts_on === ends_on`, a mesma convenção que o CalendarEvent já
 * usa — e não uma coluna booleana a dizer duas vezes o que as duas datas já
 * dizem uma vez.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $academic_year_id
 * @property AcademicCalendarExceptionType $type
 * @property string $title
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $note
 * @property AcademicCalendarExceptionSource $source
 */
#[Fillable(['academic_year_id', 'type', 'title', 'starts_on', 'ends_on', 'note', 'source'])]
class AcademicCalendarException extends Model
{
    /** @use HasFactory<AcademicCalendarExceptionFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => AcademicCalendarExceptionType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'source' => AcademicCalendarExceptionSource::class,
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
