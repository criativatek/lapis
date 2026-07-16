<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates that an id refers to a record inside the resolved organization.
 *
 * Use this instead of a bare `exists:` rule for anything tenant-owned. Laravel's
 * `exists:` runs on the query builder and never sees an Eloquent global scope, so
 * `exists:students,id` happily accepts another organization's student id and the
 * request is only stopped later — if something else happens to catch it. Running
 * the check through the model means the organization scope applies here too.
 *
 * Usage: `'student_id' => ['required', new BelongsToCurrentOrganization(Student::class)]`
 *
 * @template TModel of Model
 */
class BelongsToCurrentOrganization implements ValidationRule
{
    /**
     * @param  class-string<TModel>  $model
     */
    public function __construct(
        protected string $model,
        protected string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->model::query()
            ->where($this->column, $value)
            ->exists();

        if (! $exists) {
            $fail(__('O registo selecionado é inválido.'));
        }
    }
}
