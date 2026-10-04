<?php

namespace App\Models;

use App\Services\Classes\ClassIdentityTones;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A ligação professor ↔ turma (`class_teachers`), com o papel e a cor de
 * identidade que ESSE professor dá à turma.
 *
 * O tom nasce aqui, no `creating`, para que TODO o caminho que associa um
 * professor a uma turma (`$class->teachers()->attach(...)`: criar turma,
 * reatribuir, importar backup, seeders) o receba sem se lembrar de o pedir.
 * O `attach()` de uma relação com pivot personalizado grava pelo modelo e por
 * isso dispara este evento; um `insert` à mão em `class_teachers` não — não o
 * faças. Um tom passado explicitamente é respeitado.
 *
 * @property int $id
 * @property int $class_id
 * @property int $user_id
 * @property string $role
 * @property ClassIdentityTone|null $identity_tone
 */
#[Fillable(['class_id', 'user_id', 'role', 'identity_tone'])]
class ClassTeacher extends Pivot
{
    protected $table = 'class_teachers';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'identity_tone' => ClassIdentityTone::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            if ($pivot->identity_tone !== null) {
                return;
            }

            $class = SchoolClass::withoutGlobalScopes()->find($pivot->class_id);

            if ($class !== null) {
                $pivot->identity_tone = app(ClassIdentityTones::class)->nextFor((int) $pivot->user_id, $class);
            }
        });
    }
}
