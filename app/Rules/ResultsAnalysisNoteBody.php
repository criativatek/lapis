<?php

namespace App\Rules;

use App\Models\ResultsAnalysisNote;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The observations text must fit BOTH limits of
 * `ResultsAnalysisNote::bodyLimitViolation()` — the same check the backup
 * importer applies — and is refused here, before any write, with a message
 * the teacher can act on instead of a 500 from the database.
 */
class ResultsAnalysisNoteBody implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $violation = ResultsAnalysisNote::bodyLimitViolation($value);

        if ($violation === 'characters') {
            $fail(__('As observações não podem ter mais de :max caracteres. Reduza o texto e volte a guardar.', [
                'max' => number_format(ResultsAnalysisNote::BODY_MAX_LENGTH, 0, ',', ' '),
            ]));
        } elseif ($violation === 'bytes') {
            $fail(__('As observações ocupam demasiado espaço para serem guardadas. Emojis e alguns símbolos contam mais do que uma letra — reduza o texto e volte a guardar.'));
        }
    }
}
