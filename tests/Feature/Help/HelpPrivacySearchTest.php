<?php

namespace Tests\Feature\Help;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §11 of the Onboarding & Help brief: nothing in this feature may ever
 * receive, store or log a student's name, grade, pedagogical comment, or any
 * other student personal data. Help articles are static, authored content —
 * trivially compliant on their own — and `HelpController::search()` reads
 * only the `q` query string (see its own docblock), never a request body and
 * never any other field. This test proves that property from the outside:
 * a payload shaped like what this application actually stores about a
 * student (a name and a grade), sent under a field the controller never
 * asks for, must never come back in the response and must never be logged.
 */
class HelpPrivacySearchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_search_endpoint_only_ever_uses_the_query_string_and_never_logs_it(): void
    {
        $user = User::factory()->create();

        // Shaped like real pedagogical data — a student's name, a grade, a
        // pedagogical comment — under a field HelpController::search() never
        // reads. A tampered/careless client attaching this to the request
        // must not be able to smuggle it anywhere.
        $sensitive = 'Maria Fernandes Costa — nota 17,5 — revela dificuldades de concentração';

        /** @var list<string> $logged */
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $response = $this->actingAs($user)->get('/help/search?'.http_build_query([
            'q' => 'turma',
            'student_note' => $sensitive,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('help/Search')
            ->where('query', 'turma'));

        // Never echoed back: the response carries only the `q` value and
        // static article content, never the extra field.
        $this->assertStringNotContainsString($sensitive, (string) $response->getContent());

        // Never logged: nothing captured during this request mentions it.
        foreach ($logged as $entry) {
            $this->assertStringNotContainsString($sensitive, $entry);
        }
    }

    #[Test]
    public function the_show_endpoint_also_never_echoes_extra_request_input(): void
    {
        $user = User::factory()->create();
        $sensitive = 'João Alberto Santos — nota 9 — medida de apoio individual';

        $response = $this->actingAs($user)
            ->get('/help/classes.create?'.http_build_query(['student_note' => $sensitive]));

        $response->assertOk();
        $this->assertStringNotContainsString($sensitive, (string) $response->getContent());
    }
}
