<?php

namespace App\Http\Requests\Timetable;

use App\Actions\Timetable\ActivitySessionAction;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Guardar o registo de uma sessão: ou de uma ocorrência do bloco (`POST`, por
 * atividade e data) ou de uma sessão que já existe (`PUT`, por ulid).
 *
 * A autorização é SEMPRE do dono, e responde 404 a quem não o é (`Gate::authorize`
 * e não `can()`: um 403 confirmaria que a atividade ou a sessão existe). A
 * turma associada nunca dá acesso.
 */
class ActivitySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $session = $this->route('timetableActivitySession');

        if ($session instanceof TimetableActivitySession) {
            Gate::forUser($user)->authorize('update', $session);

            return true;
        }

        $activity = $this->activity();

        if ($activity !== null) {
            Gate::forUser($user)->authorize('update', $activity);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // Sem espaços nas pontas: o texto só com espaços é vazio.
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.TimetableActivitySession::SUMMARY_MAX],
            'action' => ['required', 'string', Rule::enum(ActivitySessionAction::class)],
        ];

        if ($this->route('timetableActivitySession') instanceof TimetableActivitySession) {
            return $rules;
        }

        return $rules + [
            // Uma atividade que não existe (ou é de outra organização, que o
            // âmbito global não deixa ver) falha aqui, sem nunca a confirmar.
            'activity_ulid' => ['required', 'string', 'size:26', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->activity() === null) {
                    $fail(__('A atividade indicada não existe.'));
                }
            }],
            'occurs_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'summary' => 'sumário',
            'action' => 'ação',
            'activity_ulid' => 'atividade',
            'occurs_on' => 'data',
        ];
    }

    /**
     * A atividade pedida, dentro da organização atual (o âmbito global).
     */
    public function activity(): ?TimetableActivity
    {
        $ulid = $this->input('activity_ulid');

        return is_string($ulid) && strlen($ulid) === 26
            ? TimetableActivity::query()->where('ulid', $ulid)->first()
            : null;
    }

    public function sessionAction(): ActivitySessionAction
    {
        return ActivitySessionAction::from((string) $this->validated('action'));
    }

    /**
     * O texto normalizado: sem espaços nas pontas, e `null` quando fica vazio.
     */
    public function summary(): ?string
    {
        $summary = $this->validated('summary');

        if (! is_string($summary)) {
            return null;
        }

        $trimmed = trim($summary);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * O pedido trouxe o campo? Sem ele, o texto guardado não se toca.
     */
    public function summaryGiven(): bool
    {
        return array_key_exists('summary', $this->validated());
    }
}
