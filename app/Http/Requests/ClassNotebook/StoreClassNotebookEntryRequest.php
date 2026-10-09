<?php

namespace App\Http\Requests\ClassNotebook;

use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\ClassNotebookEntry;
use App\Models\SchoolClass;
use App\Rules\ClassNotebookEntryBody;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * O formato de um registo — e, ANTES dele, quem pode escrevê-lo.
 *
 * A AUTORIZAÇÃO VIVE AQUI, E NÃO SÓ NO CONTROLADOR, porque um Form Request
 * valida antes de o controlador correr. Com `authorize()` a devolver `true`,
 * um colega da mesma turma que enviasse um registo vazio para o ULID de um
 * registo meu recebia «Escreve o registo antes de guardar.» em vez de 404 — e
 * a mensagem confirmava que o registo existe. Aqui a turma, a autoria e a
 * impersonação decidem primeiro; o controlador volta a verificar.
 */
class StoreClassNotebookEntryRequest extends FormRequest
{
    use RefusesDuringImpersonation;

    public function authorize(): bool
    {
        $class = $this->route('class');
        abort_unless($class instanceof SchoolClass, 404);
        Gate::authorize('view', $class);

        $entry = $this->route('notebookEntry');

        if ($entry instanceof ClassNotebookEntry) {
            Gate::authorize('update', $entry);
        }

        $this->refuseDuringImpersonation($this);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:'.ClassNotebookEntry::TITLE_MAX_LENGTH],
            'body' => ['required', 'string', new ClassNotebookEntryBody],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => 'O título não pode ter mais de '.ClassNotebookEntry::TITLE_MAX_LENGTH.' caracteres.',
            'title.string' => 'O título tem de ser texto.',
            'body.required' => ClassNotebookEntryBody::BLANK_MESSAGE,
            'body.string' => ClassNotebookEntryBody::BLANK_MESSAGE,
        ];
    }
}
