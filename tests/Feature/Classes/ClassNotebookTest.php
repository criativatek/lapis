<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\ClassNotebookEntry;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * O caderno da turma: registos privados de um professor sobre a turma. O que
 * mais importa aqui é a privacidade — só o autor lê, nem um colega da mesma
 * turma — e que um texto escrito nunca se perca por uma validação.
 */
class ClassNotebookTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    // --- Fixtures ----------------------------------------------------------

    protected function url(?SchoolClass $class = null, string $suffix = ''): string
    {
        return '/classes/'.($class ?? $this->schoolClass)->ulid.'/notebook'.$suffix;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function entry(array $attributes = [], ?SchoolClass $class = null, ?User $author = null): ClassNotebookEntry
    {
        return $this->inTenant($this->organization, function () use ($attributes, $class, $author): ClassNotebookEntry {
            $entry = ClassNotebookEntry::create(array_merge([
                'class_id' => ($class ?? $this->schoolClass)->id,
                'author_id' => ($author ?? $this->teacher)->id,
                'title' => null,
                'body' => 'Registo de teste.',
            ], $attributes));

            return $entry->refresh();
        });
    }

    protected function colleague(?SchoolClass $teaching = null): User
    {
        $colleague = User::factory()->create();

        $this->inTenant($this->organization, function () use ($colleague, $teaching): void {
            $this->organization->members()->attach($colleague, ['joined_at' => now()]);

            if ($teaching !== null) {
                $teaching->teachers()->attach($colleague, ['role' => 'co_teacher']);
            }
        });

        return $colleague;
    }

    protected function asUser(User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    protected function otherClass(string $label = '9.º Z'): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($label): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'subject_id' => $this->schoolClass->subject_id,
                'label' => $label,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    protected function rows(?User $author = null, ?SchoolClass $class = null): int
    {
        return $this->inTenant($this->organization, fn (): int => ClassNotebookEntry::withTrashed()
            ->where('class_id', ($class ?? $this->schoolClass)->id)
            ->when($author, fn ($query) => $query->where('author_id', $author->id))
            ->count());
    }

    /**
     * @return array<string, mixed>
     */
    protected function toast(): array
    {
        $flash = session('inertia.flash_data');
        $this->assertIsArray($flash, 'Nenhuma mensagem foi mostrada ao professor.');

        return $flash['toast'];
    }

    // --- Criar -------------------------------------------------------------

    #[Test]
    public function an_entry_can_be_created_with_a_title(): void
    {
        $this->asTeacher()
            ->post($this->url(), ['title' => 'Reunião de turma', 'body' => 'Foi decidido rever os lugares.'])
            ->assertRedirect($this->url());

        $entry = $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->sole());
        $this->assertSame('Reunião de turma', $entry->title);
        $this->assertSame('Foi decidido rever os lugares.', $entry->body);
        $this->assertSame($this->teacher->id, $entry->author_id);
        $this->assertSame($this->schoolClass->id, $entry->class_id);
        $this->assertFalse($entry->is_pinned);
        $this->assertSame(0, $entry->lock_version);
        $this->assertNull($entry->edited_at);
        $this->assertSame('Registo guardado.', $this->toast()['message']);
    }

    #[Test]
    public function the_title_is_optional(): void
    {
        $this->asTeacher()->post($this->url(), ['title' => '', 'body' => 'Só texto.'])->assertRedirect();
        $this->asTeacher()->post($this->url(), ['body' => 'Sem campo de título.'])->assertRedirect();

        $titles = $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->pluck('title')->all());
        $this->assertSame([null, null], $titles);
    }

    #[Test]
    public function paragraphs_are_preserved_byte_for_byte(): void
    {
        $body = "Primeiro parágrafo.\n\nSegundo parágrafo, com    espaços internos.\n  - item indentado\n\n\nTerceiro após três quebras.";

        $this->asTeacher()->post($this->url(), ['body' => $body])->assertRedirect();

        $this->assertSame($body, $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->sole()->body));
    }

    #[Test]
    public function an_empty_or_blank_body_is_refused_and_nothing_is_saved(): void
    {
        foreach (['', '   ', "\n\n  \n", "\u{00A0}", " \u{00A0} \u{200B}\u{FEFF} "] as $body) {
            $this->asTeacher()
                ->from($this->url())
                ->post($this->url(), ['title' => 'Título', 'body' => $body])
                ->assertRedirect($this->url())
                ->assertSessionHasErrors(['body' => 'Escreve o registo antes de guardar.']);
        }

        $this->asTeacher()->from($this->url())->post($this->url(), ['title' => 'Só título'])
            ->assertSessionHasErrors(['body' => 'Escreve o registo antes de guardar.']);

        $this->assertSame(0, $this->rows());
    }

    #[Test]
    public function the_body_has_a_character_limit_and_a_byte_limit(): void
    {
        $this->asTeacher()->post($this->url(), ['body' => str_repeat('a', 20000)])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows());

        $this->asTeacher()->post($this->url(), ['body' => str_repeat('a', 20001)])->assertSessionHasErrors('body');
        // 17 000 emojis cabem nos caracteres mas não nos bytes da coluna.
        $this->asTeacher()->post($this->url(), ['body' => str_repeat('😀', 17000)])->assertSessionHasErrors('body');

        $this->assertSame(1, $this->rows());
    }

    #[Test]
    public function the_title_has_a_limit(): void
    {
        $this->asTeacher()->post($this->url(), ['title' => str_repeat('t', 161), 'body' => 'Texto.'])
            ->assertSessionHasErrors(['title' => 'O título não pode ter mais de 160 caracteres.']);
        $this->assertSame(0, $this->rows());

        $this->asTeacher()->post($this->url(), ['title' => str_repeat('t', 160), 'body' => 'Texto.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows());
    }

    // --- Editar ------------------------------------------------------------

    #[Test]
    public function editing_keeps_created_at_and_order_and_records_the_edit(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $older = $this->entry(['body' => 'Mais antigo.']);
        Carbon::setTestNow('2026-10-02 09:00:00');
        $newer = $this->entry(['body' => 'Mais recente.']);

        Carbon::setTestNow('2026-10-09 12:00:00');
        $this->asTeacher()
            ->put($this->url(suffix: "/{$older->ulid}"), ['title' => 'Novo título', 'body' => 'Alterado.', 'lock_version' => 0])
            ->assertRedirect();

        $older->refresh();
        $this->assertSame('Novo título', $older->title);
        $this->assertSame('Alterado.', $older->body);
        $this->assertSame('2026-10-01 09:00:00', $older->created_at?->toDateTimeString());
        $this->assertSame('2026-10-09 12:00:00', $older->edited_at?->toDateTimeString());
        $this->assertSame(1, $older->lock_version);
        $this->assertSame('Alterações guardadas.', $this->toast()['message']);

        // Editar um registo antigo nunca o promove a recente.
        $this->asTeacher()->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.data.0.ulid', $newer->ulid)
            ->where('entries.data.1.ulid', $older->ulid));
    }

    #[Test]
    public function saving_without_changes_is_not_an_edit(): void
    {
        $entry = $this->entry(['title' => 'Igual', 'body' => 'Igual.']);

        $this->asTeacher()
            ->put($this->url(suffix: "/{$entry->ulid}"), ['title' => 'Igual', 'body' => 'Igual.', 'lock_version' => 0])
            ->assertRedirect();

        $entry->refresh();
        $this->assertNull($entry->edited_at);
        $this->assertSame(0, $entry->lock_version);
    }

    #[Test]
    public function a_stale_lock_version_is_refused_and_the_content_is_unchanged(): void
    {
        $entry = $this->entry(['body' => 'Original.']);

        $this->asTeacher()
            ->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'Primeira edição.', 'lock_version' => 0])
            ->assertSessionHasNoErrors();

        $this->asTeacher()
            ->from($this->url())
            ->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'Edição a partir de um separador velho.', 'lock_version' => 0])
            ->assertSessionHasErrors(['lock_version' => 'Este registo foi alterado noutro separador ou dispositivo. O teu texto continua aqui: copia-o antes de recarregar a página.']);

        $entry->refresh();
        $this->assertSame('Primeira edição.', $entry->body);
        $this->assertSame(1, $entry->lock_version);
    }

    #[Test]
    public function an_invalid_edit_changes_nothing(): void
    {
        $entry = $this->entry(['body' => 'Original.']);

        $this->asTeacher()->put($this->url(suffix: "/{$entry->ulid}"), ['body' => ' ', 'lock_version' => 0])
            ->assertSessionHasErrors('body');
        $this->asTeacher()->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'Sem versão.'])
            ->assertSessionHasErrors('lock_version');

        $entry->refresh();
        $this->assertSame('Original.', $entry->body);
        $this->assertNull($entry->edited_at);
    }

    // --- Fixar -------------------------------------------------------------

    #[Test]
    public function pinned_entries_come_first_and_each_group_is_newest_first(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $a = $this->entry(['body' => 'A']);
        Carbon::setTestNow('2026-10-02 09:00:00');
        $b = $this->entry(['body' => 'B']);
        Carbon::setTestNow('2026-10-03 09:00:00');
        $c = $this->entry(['body' => 'C']);
        Carbon::setTestNow('2026-10-04 09:00:00');
        $d = $this->entry(['body' => 'D']);

        $order = fn (): array => $this->asTeacher()->get($this->url())->viewData('page')['props']['entries']['data'];
        $ulids = fn (): array => array_column($order(), 'ulid');

        $this->assertSame([$d->ulid, $c->ulid, $b->ulid, $a->ulid], $ulids());

        $this->asTeacher()->patch($this->url(suffix: "/{$a->ulid}/pin"), ['pinned' => true])->assertRedirect();
        $this->assertSame('Registo fixado no topo.', $this->toast()['message']);
        $this->asTeacher()->patch($this->url(suffix: "/{$c->ulid}/pin"), ['pinned' => true])->assertRedirect();

        $this->assertSame([$c->ulid, $a->ulid, $d->ulid, $b->ulid], $ulids());

        $this->asTeacher()->patch($this->url(suffix: "/{$a->ulid}/pin"), ['pinned' => false])->assertRedirect();
        $this->assertSame('Registo desafixado.', $this->toast()['message']);

        $this->assertSame([$c->ulid, $d->ulid, $b->ulid, $a->ulid], $ulids());
    }

    #[Test]
    public function pinning_is_not_an_edit(): void
    {
        $entry = $this->entry();

        $this->asTeacher()->patch($this->url(suffix: "/{$entry->ulid}/pin"), ['pinned' => true])->assertRedirect();

        $entry->refresh();
        $this->assertTrue($entry->is_pinned);
        $this->assertNull($entry->edited_at);
        $this->assertSame(0, $entry->lock_version);

        $this->asTeacher()->patch($this->url(suffix: "/{$entry->ulid}/pin"), [])->assertSessionHasErrors('pinned');
    }

    // --- Eliminar ----------------------------------------------------------

    #[Test]
    public function deleting_is_a_soft_delete_and_the_routes_then_answer_404(): void
    {
        $entry = $this->entry(['body' => 'Para eliminar.']);
        $this->entry(['body' => 'Fica.']);

        $this->asTeacher()->delete($this->url(suffix: "/{$entry->ulid}"))->assertRedirect();
        $this->assertSame('Registo eliminado.', $this->toast()['message']);

        $this->assertSame(2, $this->rows());
        $this->assertNotNull($this->inTenant($this->organization, fn () => ClassNotebookEntry::withTrashed()->find($entry->id)->deleted_at));

        $this->asTeacher()->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.body', 'Fica.')
            ->where('totalEntries', 1));

        $this->asTeacher()->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'x', 'lock_version' => 0])->assertNotFound();
        $this->asTeacher()->patch($this->url(suffix: "/{$entry->ulid}/pin"), ['pinned' => true])->assertNotFound();
        $this->asTeacher()->delete($this->url(suffix: "/{$entry->ulid}"))->assertNotFound();
    }

    // --- Pesquisa ----------------------------------------------------------

    #[Test]
    public function search_matches_title_and_body_only_in_my_notebook_of_this_class(): void
    {
        $byTitle = $this->entry(['title' => 'Visita de estudo', 'body' => 'Autorizações.']);
        $byBody = $this->entry(['title' => 'Outra coisa', 'body' => 'Preparar a visita ao museu.']);
        $this->entry(['title' => 'Irrelevante', 'body' => 'Sem relação.']);

        $colleague = $this->colleague($this->schoolClass);
        $this->entry(['body' => 'A visita do colega.'], author: $colleague);
        $this->entry(['body' => 'Visita noutra turma.'], class: $this->otherClass());

        $this->asTeacher()->get($this->url().'?q=visita')->assertInertia(function (AssertableInertia $page) use ($byTitle, $byBody): void {
            $page->has('entries.data', 2)
                ->where('filters.q', 'visita')
                ->where('totalEntries', 3);

            $ulids = array_column($page->toArray()['props']['entries']['data'], 'ulid');
            $this->assertEqualsCanonicalizing([$byTitle->ulid, $byBody->ulid], $ulids);
        });
    }

    #[Test]
    public function percent_underscore_and_backslash_are_searched_literally(): void
    {
        $percent = $this->entry(['body' => 'Taxa de 100% de presenças.']);
        $underscore = $this->entry(['body' => 'Ficheiro aula_1 partilhado.']);
        $backslash = $this->entry(['body' => 'Caminho a\\b indicado.']);
        $this->entry(['body' => 'Taxa de 1000 presenças e aulaX1 sem marcas.']);

        foreach ([['%', $percent], ['aula_1', $underscore], ['a\\b', $backslash], ['!', null]] as [$term, $expected]) {
            $this->asTeacher()->get($this->url().'?q='.urlencode($term))
                ->assertInertia(fn (AssertableInertia $page) => $expected === null
                    ? $page->has('entries.data', 0)
                    : $page->has('entries.data', 1)->where('entries.data.0.ulid', $expected->ulid));
        }
    }

    // --- Paginação ---------------------------------------------------------

    #[Test]
    public function the_list_is_paginated_by_twenty_with_pinned_first_and_the_search_kept_in_the_links(): void
    {
        foreach (range(1, 45) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00')->addMinutes($i));
            $this->entry(['body' => "Registo número {$i}"]);
        }
        $oldest = $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->orderBy('id')->first());
        $this->asTeacher()->patch($this->url(suffix: "/{$oldest->ulid}/pin"), ['pinned' => true]);

        $this->asTeacher()->get($this->url().'?q=Registo')->assertInertia(function (AssertableInertia $page) use ($oldest): void {
            $page->has('entries.data', 20)
                ->where('entries.total', 45)
                ->where('entries.data.0.ulid', $oldest->ulid)
                ->where('entries.data.1.body', 'Registo número 45');

            $links = $page->toArray()['props']['entries']['links'];
            $this->assertStringContainsString('q=Registo', (string) $links[2]['url']);
        });

        $this->asTeacher()->get($this->url().'?page=3')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 5));
    }

    #[Test]
    public function a_page_beyond_the_last_redirects_to_the_last_one(): void
    {
        foreach (range(1, 21) as $i) {
            $this->entry(['body' => "Registo {$i}"]);
        }

        $this->asTeacher()->get($this->url().'?page=9')->assertRedirect($this->url().'?page=2');
        $this->asTeacher()->get($this->url().'?page=9&q=Registo')->assertRedirect($this->url().'?q=Registo&page=2');
        // Um caderno vazio não entra em ciclo.
        $this->asTeacher()->get($this->url(suffix: '?page=1&q=nada'))->assertOk();
    }

    // --- Privacidade -------------------------------------------------------

    #[Test]
    public function a_co_teacher_of_the_same_class_gets_404_on_my_entry_and_never_sees_it(): void
    {
        $entry = $this->entry(['body' => 'Privado.']);
        $colleague = $this->colleague($this->schoolClass);

        $this->asUser($colleague)->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'Invadido.', 'lock_version' => 0])->assertNotFound();
        $this->asUser($colleague)->patch($this->url(suffix: "/{$entry->ulid}/pin"), ['pinned' => true])->assertNotFound();
        $this->asUser($colleague)->delete($this->url(suffix: "/{$entry->ulid}"))->assertNotFound();

        $entry->refresh();
        $this->assertSame('Privado.', $entry->body);
        $this->assertFalse($entry->is_pinned);
        $this->assertNull($entry->deleted_at);

        $this->asUser($colleague)->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 0)
            ->where('totalEntries', 0));
        $this->asUser($colleague)->get($this->url().'?q=Privado')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
        $this->asUser($colleague)->get("/classes/{$this->schoolClass->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notebook.count', 0));
    }

    #[Test]
    public function an_invalid_request_from_someone_else_is_refused_before_it_is_validated(): void
    {
        // A validação de um Form Request corre antes do controlador. Se fosse
        // ela a responder, «Escreve o registo antes de guardar.» dita a um
        // colega confirmava que o registo existe — tem de ser o mesmo 404 que
        // um pedido válido recebe, sem erros de validação na sessão.
        $entry = $this->entry(['body' => 'Privado.']);
        $coTeacher = $this->colleague($this->schoolClass);

        $this->asUser($coTeacher)->put($this->url(suffix: "/{$entry->ulid}"), ['body' => '   '])
            ->assertNotFound()
            ->assertSessionHasNoErrors();

        $this->asUser($this->colleague())->post($this->url(), ['body' => ''])
            ->assertForbidden()
            ->assertSessionHasNoErrors();

        $impersonation = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];
        $this->actingAs($this->teacher)->withSession($impersonation)->post($this->url(), ['body' => ''])
            ->assertForbidden()
            ->assertSessionHasNoErrors();

        $this->assertSame('Privado.', $entry->refresh()->body);
        $this->assertSame(1, $this->rows());
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_gets_403(): void
    {
        $this->entry();
        $colleague = $this->colleague();

        $this->asUser($colleague)->get($this->url())->assertForbidden();
        $this->asUser($colleague)->post($this->url(), ['body' => 'Não devia.'])->assertForbidden();
        $this->assertSame(1, $this->rows());
    }

    #[Test]
    public function another_organization_gets_404(): void
    {
        $entry = $this->entry();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->url())->assertNotFound();
        $this->actingAs($stranger)->post($this->url(), ['body' => 'Não devia.'])->assertNotFound();
        $this->actingAs($stranger)->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'x', 'lock_version' => 0])->assertNotFound();
        $this->actingAs($stranger)->delete($this->url(suffix: "/{$entry->ulid}"))->assertNotFound();
        $this->assertSame(1, $this->rows());
    }

    #[Test]
    public function my_entry_addressed_through_another_class_of_mine_is_404(): void
    {
        $entry = $this->entry(['body' => 'Desta turma.']);
        $other = $this->otherClass();

        $this->asTeacher()->put($this->url($other, "/{$entry->ulid}"), ['body' => 'Trocado.', 'lock_version' => 0])->assertNotFound();
        $this->asTeacher()->patch($this->url($other, "/{$entry->ulid}/pin"), ['pinned' => true])->assertNotFound();
        $this->asTeacher()->delete($this->url($other, "/{$entry->ulid}"))->assertNotFound();

        $entry->refresh();
        $this->assertSame('Desta turma.', $entry->body);
        $this->assertFalse($entry->is_pinned);
        $this->assertNull($entry->deleted_at);
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $entry = $this->entry();

        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->url(), ['body' => 'x'])->assertRedirect('/login');
        $this->delete($this->url(suffix: "/{$entry->ulid}"))->assertRedirect('/login');
    }

    // --- Arquivo e anos letivos --------------------------------------------

    #[Test]
    public function an_archived_class_keeps_a_readable_searchable_and_writable_notebook(): void
    {
        $this->entry(['title' => 'Antes de arquivar', 'body' => 'Conteúdo guardado.']);
        $this->schoolClass->update(['archived_at' => now()]);

        $this->asTeacher()->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('schoolClass.archived', true)
            ->has('entries.data', 1));
        $this->asTeacher()->get($this->url().'?q=guardado')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 1));

        $this->asTeacher()->post($this->url(), ['body' => 'Escrito depois de arquivar.'])->assertRedirect();
        $this->assertSame(2, $this->rows());

        // Restaurar também não mexe em nada.
        $this->asTeacher()->delete("/classes/{$this->schoolClass->ulid}/archive")->assertRedirect();
        $this->assertSame(2, $this->rows());
    }

    #[Test]
    public function a_new_class_in_another_academic_year_starts_with_an_empty_notebook(): void
    {
        $this->entry(['body' => 'Do ano passado.']);

        $next = $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($this->organization)->create([
                    'starts_on' => '2027-09-01',
                    'ends_on' => '2028-06-30',
                ])->id,
                'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
                'label' => $this->schoolClass->label,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });

        $this->asTeacher()->get($this->url($next))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 0)
            ->where('totalEntries', 0));
    }

    // --- Impersonação ------------------------------------------------------

    /**
     * DECIDIDO (2026-10-10): o suporte técnico LÊ o caderno durante um apoio
     * pedido e NUNCA o altera. O ecrã não tem indicação de privacidade
     * (0.161.1): tudo no caderno é privado. Leitura: a lista, a pesquisa, a
     * página seguinte e a contagem no cartão da turma.
     */
    #[Test]
    public function an_impersonation_session_can_read_the_whole_notebook(): void
    {
        $pinned = $this->entry(['title' => 'Combinados', 'body' => 'Entrar em silêncio.', 'is_pinned' => true]);

        foreach (range(1, 21) as $index) {
            $this->entry(['body' => "Registo {$index}."]);
        }

        $session = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)->get($this->url())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/Notebook')
                ->where('can.write', false)
                ->where('totalEntries', 22)
                ->has('entries.data', 20)
                ->where('entries.data.0.ulid', $pinned->ulid)
                ->where('entries.data.0.body', 'Entrar em silêncio.'));

        $this->actingAs($this->teacher)->withSession($session)->get($this->url().'?q=silêncio')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.write', false)
                ->has('entries.data', 1)
                ->where('entries.data.0.ulid', $pinned->ulid));

        $this->actingAs($this->teacher)->withSession($session)->get($this->url().'?page=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 2));

        $this->actingAs($this->teacher)->withSession($session)->get("/classes/{$this->schoolClass->ulid}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notebook.count', 22));
    }

    /**
     * …e nenhuma escrita passa: criar, editar, fixar, desafixar e eliminar,
     * válidas ou inválidas (a recusa vem antes da validação — um pedido
     * inválido também é 403, sem mensagem de validação). Nada muda na base.
     * Nem pela porta lateral do backup: exportar e importar também recusam a
     * sessão de suporte.
     */
    #[Test]
    public function an_impersonation_session_can_never_write_to_the_notebook(): void
    {
        $entry = $this->entry(['body' => 'Original.']);
        $pinned = $this->entry(['body' => 'Fixado.', 'is_pinned' => true]);
        $session = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];
        $as = fn () => $this->actingAs($this->teacher)->withSession($session);

        $as()->post($this->url(), ['body' => 'Novo.'])->assertForbidden()->assertSessionHasNoErrors();
        $as()->post($this->url(), ['body' => ''])->assertForbidden()->assertSessionHasNoErrors();
        $as()->put($this->url(suffix: "/{$entry->ulid}"), ['body' => 'Alterado.', 'lock_version' => 0])->assertForbidden()->assertSessionHasNoErrors();
        $as()->put($this->url(suffix: "/{$entry->ulid}"), ['body' => '   '])->assertForbidden()->assertSessionHasNoErrors();
        $as()->patch($this->url(suffix: "/{$entry->ulid}/pin"), ['pinned' => true])->assertForbidden();
        $as()->patch($this->url(suffix: "/{$pinned->ulid}/pin"), ['pinned' => false])->assertForbidden();
        $as()->delete($this->url(suffix: "/{$entry->ulid}"))->assertForbidden();

        // A recusa é a da sessão de suporte, e não outra (a organização das
        // fixtures está no Pro, que inclui o restauro): a mensagem prova-o.
        $refusal = 'Não é possível realizar esta ação durante uma sessão de suporte.';
        $as()->postJson('/data-exports')->assertForbidden()->assertJsonPath('message', $refusal);
        $as()->postJson('/data-imports', [])->assertForbidden()->assertJsonPath('message', $refusal);
        $as()->postJson($this->url(), ['body' => 'Novo.'])->assertForbidden()->assertJsonPath('message', $refusal);

        $entry->refresh();
        $pinned->refresh();
        $this->assertSame('Original.', $entry->body);
        $this->assertFalse($entry->is_pinned);
        $this->assertNull($entry->deleted_at);
        $this->assertSame(0, $entry->lock_version);
        $this->assertNull($entry->edited_at);
        $this->assertTrue($pinned->is_pinned);
        $this->assertSame(2, $this->rows());
    }

    // --- Eliminação definitiva da turma ------------------------------------

    #[Test]
    public function a_class_in_preparation_with_a_notebook_entry_cannot_be_deleted(): void
    {
        $this->entry();
        $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->sole()->delete());

        $this->asTeacher()->delete("/classes/{$this->schoolClass->ulid}")->assertRedirect();

        $this->assertSame('error', $this->toast()['type']);
        $this->assertStringContainsString('registos do caderno da turma', $this->toast()['message']);
        $this->assertNotNull(SchoolClass::withoutGlobalScopes()->find($this->schoolClass->id));
        $this->assertSame(1, $this->rows());
    }

    // --- Cartão da turma ---------------------------------------------------

    #[Test]
    public function the_class_page_counts_only_my_entries(): void
    {
        $this->entry();
        $this->entry();
        $this->entry(['body' => 'Noutra turma.'], class: $this->otherClass());
        $this->entry(['body' => 'Do colega.'], author: $this->colleague($this->schoolClass));
        $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->where('body', 'Registo de teste.')->first()->delete());

        $this->asTeacher()->get("/classes/{$this->schoolClass->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notebook.count', 1));
    }

    #[Test]
    public function the_notebook_props_describe_the_class_and_the_entries(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');
        $entry = $this->entry(['title' => 'Título', 'body' => 'Corpo.']);

        $this->asTeacher()->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->component('classes/Notebook')
            ->where('schoolClass.ulid', $this->schoolClass->ulid)
            ->where('schoolClass.archived', false)
            ->where('can.write', true)
            ->where('filters.q', '')
            ->where('totalEntries', 1)
            ->where('entries.data.0.ulid', $entry->ulid)
            ->where('entries.data.0.edited_at', null)
            ->where('entries.data.0.lock_version', 0)
            ->where('entries.data.0.is_pinned', false));
    }
}
