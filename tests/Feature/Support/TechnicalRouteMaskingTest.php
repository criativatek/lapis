<?php

namespace Tests\Feature\Support;

use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A rota que viaja com um pedido não pode identificar ninguém.
 *
 * `technical_route` existe para o operador saber ONDE a pessoa estava, e uma
 * rota do Lapispro diz isso com o nome do ecrã. Os identificadores que lá vão
 * pelo meio — o ULID da turma, o do aluno, o do relatório — não acrescentam nada
 * a essa resposta e são, esses sim, a identificação de uma criança concreta.
 *
 * O DEFEITO QUE ISTO FECHA. O ecrã enviava o `pathname` do referrer inteiro e o
 * servidor aceitava-o com `max:200` e mais nada, portanto
 * `/classes/01J.../alunos/01J.../edit` ficava gravado tal e qual numa coluna que
 * um operador lê. A máscara vive no SERVIDOR porque é lá que a regra tem de
 * estar: o ecrã também mascara, mas isso é conveniência, e o código que decide o
 * que se remove não pode ser o que viaja no browser de quem envia.
 */
class TechnicalRouteMaskingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_identifier_in_the_route_never_reaches_the_column(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', [
            'category' => 'classes_students',
            'subject' => 'A turma não abre',
            'description' => 'Carrego na turma e fica em branco.',
            'technical_route' => '/classes/01M1CP8P9936KY71CD6GVSJV60/alunos/01M17J5WWA2ZJE568VQ4YC0AXW/edit',
        ])->assertRedirect();

        $route = SupportRequest::query()->sole()->technical_route;

        $this->assertSame('/classes/:id/alunos/:id/edit', $route);
    }

    #[Test]
    public function a_uuid_and_a_long_number_are_masked_the_same_way(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', [
            'category' => 'reports',
            'subject' => 'Relatório em branco',
            'description' => 'Não gera.',
            // Um UUID, e um id sequencial de seis algarismos: nenhum dos dois
            // ajuda a saber em que ecrã a pessoa estava.
            'technical_route' => '/reports/3f2504e0-4f89-11d3-9a0c-0305e82c3301/seccoes/123456',
        ])->assertRedirect();

        $this->assertSame(
            '/reports/:id/seccoes/:id',
            SupportRequest::query()->sole()->technical_route,
        );
    }

    #[Test]
    public function the_part_that_answers_where_the_person_was_survives_intact(): void
    {
        // O oposto da asserção acima, e a que impede uma máscara demasiado
        // gulosa: se isto começar a devolver `/:id/:id`, a coluna deixou de
        // servir para o que existe.
        $this->actingAs(User::factory()->create());

        $this->post('/support', [
            'category' => 'assessment',
            'subject' => 'Grelha estranha',
            'description' => 'Os pesos não somam.',
            'technical_route' => '/assessment-profiles/versoes/comparar',
        ])->assertRedirect();

        $this->assertSame(
            '/assessment-profiles/versoes/comparar',
            SupportRequest::query()->sole()->technical_route,
        );
    }
}
