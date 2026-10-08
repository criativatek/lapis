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
 * @property int|null $lesson_sequence_id proveniência: a sequência que escreveu este conteúdo (só uma REAPLICAÇÃO a lê)
 * @property int|null $lesson_sequence_item_id proveniência: o elemento da sequência que está nesta aula
 * @property string|null $sequence_content_hash impressão digital do conteúdo quando a sequência o escreveu
 */
#[Fillable(['lesson_id', 'content', 'private_notes', 'resources', 'homework', 'reviewed_at', 'reviewed_by', 'lesson_sequence_id', 'lesson_sequence_item_id', 'sequence_content_hash'])]
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
     * @return BelongsTo<LessonSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(LessonSequence::class, 'lesson_sequence_id');
    }

    /**
     * @return BelongsTo<LessonSequenceItem, $this>
     */
    public function sequenceItem(): BelongsTo
    {
        return $this->belongsTo(LessonSequenceItem::class, 'lesson_sequence_item_id');
    }

    /**
     * A impressão digital do que o professor VÊ neste sumário — os quatro
     * campos de texto, aparados. É a única definição: quem grava a proveniência
     * e quem compara («o professor mexeu-lhe?») usam este método, e por isso
     * nunca divergem.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            trim((string) $this->content),
            trim((string) $this->resources),
            trim((string) $this->homework),
            trim((string) $this->private_notes),
        ]));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
