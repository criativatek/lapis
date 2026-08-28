<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A credential arriving from the backoffice, on its way to being encrypted.
 *
 * THE FIELD NAME IS ON THE `dontFlash` LIST, AND THAT LIST IS IN
 * `bootstrap/app.php`, NOT HERE. When validation fails Laravel flashes the input
 * back to the session so the form can be redrawn with what the operator typed —
 * minus whatever `dontFlash` names, which by default is three password fields
 * and nothing else. Without the registration, a rejected credential sits in the
 * session store in the clear.
 *
 * A `protected $dontFlash` PROPERTY ON THIS CLASS WOULD DO NOTHING. It reads as
 * though it would, which is worse than not having one: the list the framework
 * actually consults lives on the exception handler. This was caught by
 * `AdminAiSettingsTest::a_rejected_credential_is_not_flashed_back_into_the_session`
 * and is written down here so nobody re-adds the property and believes it.
 *
 * SEPARATE FROM `UpdateAiSettingsRequest` FOR A SECOND REASON TOO: storing a key
 * is a different act from changing a model, it gets its own audit event, and
 * keeping them apart means the ordinary settings round-trip has no field
 * anywhere in it that could carry a secret.
 *
 * NO `confirmed` RULE. A credential is pasted, not typed, so asking for it twice
 * catches nothing and mostly produces two pastes of the wrong thing. «Testar
 * ligação» is the confirmation, and it tests the real thing against the real
 * endpoint.
 */
class StoreAiCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A minimum length that no real API key falls under, so an
            // accidental submit of a half-pasted value is refused before it
            // replaces a working credential. No format rule and no vendor
            // prefix check: the shape of a key is the vendor's business and
            // changes without warning.
            'ai_credential' => ['required', 'string', 'min:16', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Deliberately does not echo the value or its length back.
            'ai_credential.min' => 'A credencial parece incompleta. Cole a chave completa.',
            'ai_credential.required' => 'Indique a credencial a guardar.',
        ];
    }

    public function credential(): string
    {
        return trim((string) $this->validated('ai_credential'));
    }
}
