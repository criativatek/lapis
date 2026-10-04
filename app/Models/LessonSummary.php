<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $lesson_id
 * @property string $content
 * @property string|null $private_notes
 * @property string|null $resources
 * @property string|null $homework
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 */
#[Fillable(['lesson_id', 'content', 'private_notes', 'resources', 'homework', 'reviewed_at', 'reviewed_by'])]
class LessonSummary extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * TODA a escrita num sumário sobe `lessons.summary_version`, aqui e não em
     * cada ação. As ações que gravam (SaveLessonSummary, ClearLessonSummary,
     * ShiftLessonPlanning, importação de backup) e as que apagam a linha
     * (limpar, deslocar o planeamento) passam todas pelo modelo, e um
     * contador mantido à mão em cada uma perdia-se no dia em que aparecesse
     * uma nova. A versão vive na AULA (ver a migração) porque a linha é
     * apagada e recriada; e sobe sem tocar em `lessons.updated_at`, que
     * continua a dizer quando a AULA mudou, não quando o texto foi mexido.
     *
     * Um apagamento por query builder (`$lesson->summary()->delete()`) não
     * dispara eventos — quem o faz tem de apagar pelo modelo.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $summary) => $summary->bumpLessonSummaryVersion());
        static::deleted(fn (self $summary) => $summary->bumpLessonSummaryVersion());
    }

    private function bumpLessonSummaryVersion(): void
    {
        Lesson::query()->whereKey($this->lesson_id)->toBase()->increment('summary_version');
    }

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
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
