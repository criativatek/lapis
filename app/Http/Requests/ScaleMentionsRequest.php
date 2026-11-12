<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScaleMentionsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'labels' => ['required', 'array', 'size:5'],
            'labels.*' => ['required', 'string', 'min:1', 'max:64'],
            'profile_name' => ['required', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $labels = array_map(
                fn ($label): string => mb_strtolower(trim((string) $label)),
                (array) $this->input('labels', []),
            );

            if (count($labels) !== count(array_unique($labels))) {
                $validator->errors()->add('labels', 'Duas menções não podem ter o mesmo nome.');
            }
        });
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_values(array_map(fn ($label): string => trim((string) $label), (array) $this->validated('labels')));
    }
}
