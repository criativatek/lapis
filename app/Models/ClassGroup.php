<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um grupo fixo de alunos dentro de uma turma — «T1», «T2», «PL1», «B».
 *
 * PERTENCE À TURMA, e não o contrário: a turma continua a ser uma só
 * SchoolClass, com uma disciplina, um perfil de avaliação e uma pauta. O grupo
 * existe apenas para os tempos do horário em que a turma se desdobra, e para o
 * sumário dessas aulas.
 *
 * O RÓTULO É LIVRE E ÚNICO POR TURMA. Nada em código conhece «T1»: a validação
 * é de forma (não vazio, até 40 caracteres) e de unicidade dentro da turma.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property string $label
 * @property int $position
 * @property Carbon|null $archived_at
 */
#[Fillable(['class_id', 'label', 'position', 'archived_at'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
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

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Os grupos que ainda aceitam trabalho novo — pertenças novas, tempos do
     * horário novos. Um grupo arquivado continua a ler-se em toda a parte
     * (uma aula de novembro continua a dizer «8.º F · T1»); o que ele deixa de
     * fazer é aparecer nas escolhas de amanhã (§24 do briefing).
     *
     * @param  Builder<ClassGroup>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return HasMany<ClassGroupMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClassGroupMembership::class);
    }

    /**
     * As pertenças AINDA ABERTAS — as que não têm data de fim.
     *
     * Deliberadamente não é a relação por omissão, pela mesma razão que
     * SchoolClass::activeEnrollments() não é: quem pergunta «quem está em T1
     * hoje» e quem pergunta «quem esteve em T1 em novembro» querem respostas
     * diferentes, e nenhuma das duas se deve alcançar por acidente.
     *
     * @return HasMany<ClassGroupMembership, $this>
     */
    public function currentMemberships(): HasMany
    {
        return $this->memberships()->whereNull('effective_until');
    }

    /**
     * @return HasMany<RecurringLessonSlot, $this>
     */
    public function recurringLessonSlots(): HasMany
    {
        return $this->hasMany(RecurringLessonSlot::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
