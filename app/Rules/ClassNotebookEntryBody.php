<?php

namespace App\Rules;

use App\Models\ClassNotebookEntry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O registo do caderno não pode ser vazio — nem só espaços, incluindo os que
 * `trim()` não vê (NBSP, espaço de largura zero, BOM) — e tem de caber nos dois
 * limites de `ClassNotebookEntry::bodyLimitViolation()`, recusado antes de
 * qualquer escrita.
 */
class ClassNotebookEntryBody implements ValidationRule
{
    public const string BLANK_MESSAGE = 'Escreve o registo antes de guardar.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $visible = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $value);

        if ($visible === null || $visible === '') {
            $fail(self::BLANK_MESSAGE);

            return;
        }

        $violation = ClassNotebookEntry::bodyLimitViolation($value);

        if ($violation === 'characters') {
            $fail(__('O registo não pode ter mais de :max caracteres. Reduz o texto e volta a guardar.', [
                'max' => number_format(ClassNotebookEntry::BODY_MAX_LENGTH, 0, ',', ' '),
            ]));
        } elseif ($violation === 'bytes') {
            $fail(__('O registo ocupa demasiado espaço para ser guardado. Emojis e alguns símbolos contam mais do que uma letra — reduz o texto e volta a guardar.'));
        }
    }
}
