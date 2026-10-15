<?php

namespace App\Services\Classes;

use App\Models\ProfileVersionStatus;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\Gate;

/**
 * A oferta de «Rever e aplicar alterações»: só numa turma não arquivada,
 * quando o perfil tem uma versão corrente ativa, mais recente do que a da
 * turma, e quem vê pode alterar a turma. Não migra nada — leva à
 * pré-visualização existente, que traz as validações e a confirmação com motivo.
 *
 * Vive aqui, e não no controlador da turma, porque a página da turma e a
 * autoavaliação têm de oferecer a mesma coisa pelas mesmas regras: duas cópias
 * acabariam a discordar sobre quando há uma versão por rever.
 */
class ClassProfileUpdateOffer
{
    /**
     * @return array{version_number: int, scale_name: string|null, review_url: string}|null
     */
    public function for(SchoolClass $class): ?array
    {
        $classVersion = $class->profileVersion;
        $profile = $classVersion?->profile;

        // Uma turma arquivada é histórico: a versão com que foi avaliada fica como
        // estava, e nada no ecrã convida a mudá-la.
        if ($class->isArchived() || $classVersion === null || $profile === null || $profile->current_version_id === null
            || $profile->current_version_id === $classVersion->id
            || ! Gate::allows('update', $class)) {
            return null;
        }

        $current = $profile->currentVersion()->with('scale')->first();

        if ($current === null
            || $current->status !== ProfileVersionStatus::Active
            || $current->version_number <= $classVersion->version_number) {
            return null;
        }

        return [
            'version_number' => $current->version_number,
            'scale_name' => $current->scale?->name,
            'review_url' => route('classes.profile-migration.create', ['class' => $class->ulid, 'to' => $current->ulid], false),
        ];
    }
}
