<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Um registo do caderno da turma: texto privado de um professor sobre a turma
 * como grupo. Só o autor o lê (`ClassNotebookEntryPolicy`) — nem um colega da
 * mesma turma. Eliminar é soft delete.
 *
 * `lock_version` e `edited_at` dizem respeito ao CONTEÚDO (título/registo);
 * fixar não lhes toca.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $author_id
 * @property string|null $title
 * @property string $body
 * @property bool $is_pinned
 * @property int $lock_version
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['class_id', 'author_id', 'title', 'body', 'is_pinned', 'lock_version', 'edited_at'])]
class ClassNotebookEntry extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /**
     * Os dois limites do registo, como em `ResultsAnalysisNote`: caracteres são
     * o que o professor vê; bytes são o que a coluna `TEXT` guarda (65 535 em
     * UTF-8). 20 000 emojis são 80 000 bytes.
     */
    public const int BODY_MAX_LENGTH = 20000;

    public const int BODY_MAX_BYTES = 65535;

    public const int TITLE_MAX_LENGTH = 160;

    /**
     * Que limite um registo ultrapassa, se algum: `'characters'`, `'bytes'` ou null.
     */
    public static function bodyLimitViolation(string $body): ?string
    {
        if (mb_strlen($body, 'UTF-8') > self::BODY_MAX_LENGTH) {
            return 'characters';
        }

        if (strlen($body) > self::BODY_MAX_BYTES) {
            return 'bytes';
        }

        return null;
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
            'is_pinned' => 'boolean',
            'lock_version' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Fixados primeiro; dentro de cada grupo, o mais recente primeiro pela data
     * de CRIAÇÃO (editar um registo antigo nunca o promove).
     *
     * @param  Builder<ClassNotebookEntry>  $query
     * @return Builder<ClassNotebookEntry>
     */
    public function scopeOrderedForNotebook(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
