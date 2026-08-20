<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That an error message is a sentence and never an identifier.
 *
 * THIS IS THE TEST THAT DID NOT EXIST. `lang/pt_PT/validation.php` opens by
 * explaining that the application runs with APP_LOCALE and
 * APP_FALLBACK_LOCALE both set to pt_PT, so a missing key has nowhere to fall
 * back to and reaches the teacher as «validation.password.mixed». The file
 * said so from the start, and was still shipped with 47 keys missing —
 * including every message the password rules produce.
 *
 * It escaped in the most ordinary way available: the strict rules only apply
 * in production (`AppServiceProvider` returns null for `Password::defaults()`
 * outside it), so locally the validation never fails, so the message is never
 * asked for, so nobody sees the key. Diligence was never going to catch this.
 * A comparison against the framework's own list will.
 */
class ValidationMessagesTest extends TestCase
{
    /**
     * Flatten a translation array to dotted keys, so `password.mixed` and
     * `between.string` are compared as themselves rather than as their parents.
     *
     * @param  array<string, mixed>  $messages
     * @return list<string>
     */
    protected function flatten(array $messages, string $prefix = ''): array
    {
        $keys = [];

        foreach ($messages as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $keys = [...$keys, ...$this->flatten($value, $dotted)];

                continue;
            }

            $keys[] = $dotted;
        }

        return $keys;
    }

    #[Test]
    public function every_message_the_framework_defines_is_written_in_portuguese(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $portuguese = require lang_path('pt_PT/validation.php');

        // `custom` and `attributes` are per-application structures, not
        // framework messages — the English file ships them empty as scaffolding
        // and ours fills `attributes` with the words a teacher recognises.
        unset($english['custom'], $english['attributes'], $portuguese['custom'], $portuguese['attributes']);

        $missing = array_diff($this->flatten($english), $this->flatten($portuguese));

        $this->assertSame([], array_values($missing), sprintf(
            'Sem tradução em pt_PT, estas chaves chegam ao professor como identificadores: %s',
            implode(', ', $missing),
        ));
    }

    /**
     * The six rules a password faces in production, each failed on purpose.
     *
     * Asserted on the rendered message rather than on the key's existence: a
     * key present but empty, or a typo in the nesting, would satisfy the test
     * above and still show the teacher nothing useful.
     */
    #[Test]
    public function a_rejected_password_is_told_what_to_fix_and_never_shown_a_key(): void
    {
        $rule = Password::min(12)->mixedCase()->letters()->numbers()->symbols();

        $cases = [
            'curta' => 'Ab1!',
            'sem maiúscula' => 'palavrapasse1!',
            'sem minúscula' => 'PALAVRAPASSE1!',
            'sem algarismo' => 'PalavraPasse!!',
            'sem símbolo' => 'PalavraPasse11',
        ];

        foreach ($cases as $why => $password) {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => $rule],
            );

            $this->assertTrue($validator->fails(), "«{$why}» devia ter sido recusada.");

            foreach ($validator->errors()->get('password') as $message) {
                $this->assertStringNotContainsString('validation.', $message,
                    "«{$why}» produziu um identificador em vez de uma frase: {$message}");
                $this->assertStringContainsString('palavra-passe', $message,
                    "«{$why}» não nomeia o campo em português: {$message}");
            }
        }
    }

    /**
     * The one that actually blocked somebody. `uncompromised` consults an
     * external service, so the rule is not exercised here — only that the
     * sentence it would print exists, is Portuguese, and says what to do
     * instead of only what went wrong.
     */
    #[Test]
    public function a_leaked_password_is_explained_rather_than_merely_refused(): void
    {
        $message = __('validation.password.uncompromised', ['attribute' => 'palavra-passe']);

        $this->assertStringNotContainsString('validation.', $message);
        $this->assertStringContainsString('fuga de dados', $message);
        $this->assertStringContainsString('Escolha outra', $message);
    }
}
