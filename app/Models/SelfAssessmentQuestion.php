<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question of a self-assessment template (§15). A domain-linked scale question
 * is what lets the answer be compared, on read, with the calculated domain result.
 *
 * @property int $id
 * @property int $self_assessment_template_id
 * @property int|null $domain_id
 * @property string $prompt
 * @property string $answer_kind
 * @property int|null $scale_id
 * @property int $sequence
 */
#[Fillable(['self_assessment_template_id', 'domain_id', 'prompt', 'answer_kind', 'scale_id', 'sequence'])]
class SelfAssessmentQuestion extends Model
{
    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
