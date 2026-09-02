<?php

namespace Tests\Feature\Support;

use App\Actions\Support\AnonymiseSupportRequest;
use App\Actions\Support\OpenSupportRequest;
use App\Actions\Support\TriageSupportRequest;
use App\Models\AuditEvent;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\SupportSeverity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As duas decisões que ordenam a fila: quanto pesa, e de quem é.
 *
 * O PAR DA ATRIBUIÇÃO É AFIRMADO AQUI PORQUE A BASE NÃO O PODE AFIRMAR. O MySQL
 * recusa um CHECK sobre uma coluna que tem chave estrangeira com acção
 * referencial (erro 3823), e `assigned_to` tem `ON DELETE SET NULL`. A
 * invariante existe na mesma — só passa a viver numa única porta, e este teste é
 * o que a mantém verdadeira.
 */
class SupportTriageTest extends TestCase
{
    use RefreshDatabase;

    private function request(): SupportRequest
    {
        $user = User::factory()->create();

        return app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Assessment->value,
            'subject' => 'A grelha não soma',
            'description' => 'Os pesos não batem certo.',
        ], $user, $user->personalOrganization());
    }

    #[Test]
    public function an_operator_sets_and_clears_the_severity(): void
    {
        $operator = User::factory()->create();
        $request = $this->request();

        $triaged = app(TriageSupportRequest::class)->setSeverity($request, $operator, SupportSeverity::BlocksWork);
        $this->assertSame(SupportSeverity::BlocksWork, $triaged->severity);

        $cleared = app(TriageSupportRequest::class)->setSeverity($triaged, $operator, null);
        $this->assertNull($cleared->severity);
    }

    #[Test]
    public function assigning_writes_both_halves_and_letting_go_clears_both(): void
    {
        $operator = User::factory()->create();
        $assignee = User::factory()->create();
        $request = $this->request();

        $assigned = app(TriageSupportRequest::class)->assign($request, $operator, $assignee);

        $this->assertSame($assignee->id, $assigned->assigned_to);
        $this->assertNotNull($assigned->assigned_at);

        $released = app(TriageSupportRequest::class)->assign($assigned, $operator, null);

        // As duas metades, sempre juntas. É o que o CHECK diria se o MySQL o
        // deixasse existir sobre uma coluna com `ON DELETE SET NULL`.
        $this->assertNull($released->assigned_to);
        $this->assertNull($released->assigned_at);
    }

    #[Test]
    public function the_trail_names_the_operator_and_never_the_assignee(): void
    {
        $operator = User::factory()->create();
        $assignee = User::factory()->create(['name' => 'Nome Do Responsavel']);
        $request = $this->request();

        app(TriageSupportRequest::class)->assign($request, $operator, $assignee);

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'support.assigned')->sole();

        // Um acto do operador leva autor, ao contrário da criação de um pedido
        // — ADR-0011 §10, e a distinção é o ponto.
        $this->assertSame($operator->id, $event->causer_id);
        // Mas o nome de quem o recebeu não entra: um identificador a mais no
        // rasto é um a mais.
        $this->assertStringNotContainsString('Nome Do Responsavel', json_encode($event->getAttributes()));
    }

    #[Test]
    public function severity_and_assignment_survive_anonymisation(): void
    {
        // São registos de actos do operador, da mesma natureza que
        // `resolved_by`, que também sobrevive. Não dizem nada sobre o titular.
        $operator = User::factory()->create();
        $request = $this->request();

        app(TriageSupportRequest::class)->setSeverity($request, $operator, SupportSeverity::Cosmetic);
        $assigned = app(TriageSupportRequest::class)->assign($request->fresh(), $operator, $operator);

        app(AnonymiseSupportRequest::class)->execute($assigned->fresh());

        $anonymised = $assigned->fresh();

        $this->assertNull($anonymised->subject);
        $this->assertSame(SupportSeverity::Cosmetic, $anonymised->severity);
        $this->assertSame($operator->id, $anonymised->assigned_to);
    }
}
