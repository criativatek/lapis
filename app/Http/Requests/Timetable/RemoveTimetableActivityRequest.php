<?php

namespace App\Http\Requests\Timetable;

use App\Models\TimetableActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Eliminar o bloco de uma «outra atividade». A data é OPCIONAL e só conta
 * quando há sessões com registos (o bloco então fecha em vez de se apagar):
 * «deixa de vigorar a partir de», nunca no passado, por omissão hoje.
 */
class RemoveTimetableActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $activity = $this->route('timetableActivity');

        if ($user === null || ! $activity instanceof TimetableActivity) {
            return false;
        }

        // `Gate::authorize`: a política responde 404 a quem não é o dono.
        Gate::forUser($user)->authorize('delete', $activity);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['effective_from' => 'deixa de vigorar a partir de'];
    }

    public function effectiveFrom(): ?string
    {
        $date = $this->validated('effective_from');

        return is_string($date) && $date !== '' ? $date : null;
    }
}
