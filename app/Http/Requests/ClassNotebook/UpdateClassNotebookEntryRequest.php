<?php

namespace App\Http\Requests\ClassNotebook;

/**
 * Como a criação, mais a versão do conteúdo que o ecrã tinha à frente.
 */
class UpdateClassNotebookEntryRequest extends StoreClassNotebookEntryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'lock_version.required' => 'Recarrega a página e tenta outra vez.',
            'lock_version.integer' => 'Recarrega a página e tenta outra vez.',
            'lock_version.min' => 'Recarrega a página e tenta outra vez.',
        ];
    }
}
