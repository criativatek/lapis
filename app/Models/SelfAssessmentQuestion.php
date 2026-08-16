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
 * @property SelfAssessmentQuestionRole|null $role
 */
#[Fillable(['self_assessment_template_id', 'domain_id', 'role', 'prompt', 'answer_kind', 'scale_id', 'sequence'])]
class SelfAssessmentQuestion extends Model
{
    protected function casts(): array
    {
        return ['role' => SelfAssessmentQuestionRole::class];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Whether this question is coherent with itself.
     *
     * The two ways of identifying a question are exclusive: a question is about
     * a DOMAIN, or it has a ROLE that applies to the whole period. One that
     * claimed both would be answerable in two places, and the results screen
     * would have to pick.
     *
     * And a role fixes what kind of answer it takes: the overall judgement is a
     * scale answer, the reflections are written ones. A «global» question
     * storing text could never be read as a self-assessment at all.
     */
    public function isCoherent(): bool
    {
        if ($this->role === null) {
            // No role: it must be a domain question, which is the other way of
            // being identified. A question that is neither is anonymous.
            return $this->domain_id !== null;
        }

        return $this->domain_id === null
            && $this->answer_kind === $this->role->answerKind();
    }
}
