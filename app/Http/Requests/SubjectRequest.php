<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $subject = $this->route('subject');
        $subjectId = $subject instanceof Subject ? $subject->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            // Code unique within the organization — scoped, not a bare unique:.
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('subjects', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($subjectId),
            ],
        ];
    }
}
