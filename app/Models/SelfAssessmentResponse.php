<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One answer within a self-assessment (§15). A scale answer points at a
 * scale_level; text and boolean answers use their own columns.
 *
 * @property int $id
 * @property int $self_assessment_id
 * @property int $self_assessment_question_id
 * @property int|null $scale_level_id
 * @property string|null $text_value
 * @property bool|null $boolean_value
 */
#[Fillable([
    'self_assessment_id', 'self_assessment_question_id', 'scale_level_id', 'text_value', 'boolean_value',
])]
class SelfAssessmentResponse extends Model
{
    protected function casts(): array
    {
        return ['boolean_value' => 'boolean'];
    }
}
