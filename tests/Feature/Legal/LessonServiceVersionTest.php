<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use App\Support\Legal\PrivacyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A VERSÃO DO SERVIÇO LETIVO: o texto novo da Política e do Acordo nunca aparece com uma data antiga e nunca se
 * considera aceite por quem aceitou a versão anterior. Só existe a partir da sua própria data
 * (`lapis.legal.lesson_service_effective_from`, um ato deliberado de publicação); antes disso — ou sem data — os dois
 * documentos mostram o texto anterior COM a data anterior, sem perder nenhuma referência legal.
 */
class LessonServiceVersionTest extends TestCase
{
    use RefreshDatabase;

    private const TERMS_SHA256 = '76414f530001dc25a21315d5ff7c54902ff59ef05c920a6696979c922ec03cf4';

    private const NEW_SENTENCE = 'prevista e não realizada';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', (string) config('app.timezone')));
    }

    private function everything(): string
    {
        return json_encode([LegalDocuments::privacy(), LegalDocuments::processing()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string|null}> */
    public static function datesThatDoNotPublish(): array
    {
        return [
            'sem data' => [null],
            'data vazia' => [''],
            'data futura' => ['2026-12-01'],
            'data inválida' => ['2026-13-45'],
            'texto qualquer' => ['em breve'],
            'dia inexistente' => ['2026-02-30'],
        ];
    }

    #[Test]
    #[DataProvider('datesThatDoNotPublish')]
    public function without_a_reached_date_both_documents_keep_the_previous_text_and_the_previous_date(?string $date): void
    {
        config(['lapis.legal.lesson_service_effective_from' => $date]);

        $this->assertFalse(PrivacyVersion::describesLessonService());
        $this->assertStringNotContainsString(self::NEW_SENTENCE, $this->everything());
        $this->assertStringNotContainsString('Greve do professor', $this->everything());
        // O texto anterior continua lá, com a data anterior — nunca o texto novo sob uma data antiga.
        $this->assertSame('2026-09-15', LegalDocuments::privacy()['effective_from']);
        $this->assertSame('2026-09-15', LegalDocuments::processing()['effective_from']);
        $this->assertStringContainsString('ou turma em outra atividade letiva) e, quando aplicável, a categoria de ausência', $this->everything());
    }

    #[Test]
    public function once_the_date_is_reached_the_new_text_takes_its_own_date_and_nothing_existing_is_removed(): void
    {
        $before = [LegalDocuments::privacy(), LegalDocuments::processing()];
        config(['lapis.legal.lesson_service_effective_from' => '2026-11-10']);

        $this->assertTrue(PrivacyVersion::describesLessonService());
        $this->assertStringContainsString(self::NEW_SENTENCE, $this->everything());
        $this->assertSame('2026-11-10', LegalDocuments::privacy()['effective_from']);
        $this->assertSame('2026-11-10', LegalDocuments::processing()['effective_from']);

        // Nenhuma secção desaparece e nenhuma frase anterior é perdida (as alteradas ficam como superconjunto).
        $after = [LegalDocuments::privacy(), LegalDocuments::processing()];
        foreach ([0, 1] as $i) {
            $this->assertSame(array_column($before[$i]['sections'], 'heading'), array_column($after[$i]['sections'], 'heading'));
            $this->assertSame($before[$i]['related'], $after[$i]['related']);
        }
        foreach (['não é pedido nem guardado texto livre sobre o motivo pessoal da ausência', 'Registo de atividade: conservado', 'Cópias de segurança da base de dados: rotação de 30 cópias diárias e 12 mensais', 'não constitui categoria especial de dados'] as $kept) {
            $this->assertStringContainsString($kept, $this->everything());
        }
    }

    #[Test]
    public function a_future_date_publishes_nothing_until_it_arrives(): void
    {
        config(['lapis.legal.lesson_service_effective_from' => '2026-11-20']);
        $this->assertStringNotContainsString(self::NEW_SENTENCE, $this->everything());

        $this->travelTo(CarbonImmutable::parse('2026-11-20 00:30:00', (string) config('app.timezone')));
        $this->assertStringContainsString(self::NEW_SENTENCE, $this->everything());
        $this->assertSame('2026-11-20', LegalDocuments::privacy()['effective_from']);
    }

    #[Test]
    public function the_terms_and_the_acceptance_already_given_are_untouched(): void
    {
        $user = User::factory()->create(['terms_version' => '2026-09-03', 'terms_accepted_at' => '2026-09-05 10:00:00']);

        config(['lapis.legal.lesson_service_effective_from' => '2026-11-10']);

        $this->assertSame('2026-09-03', LegalDocuments::terms()['effective_from']);
        $this->assertSame(self::TERMS_SHA256, hash('sha256', (string) json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE)));
        $this->assertSame('2026-09-03', LegalDocuments::termsVersion());
        // Nada se regista como aceite em nome de ninguém: a versão aceite continua a dos Termos.
        $this->assertSame('2026-09-03', $user->refresh()->terms_version);
        $this->assertSame('2026-09-05 10:00:00', $user->terms_accepted_at->toDateTimeString());
    }

    #[Test]
    public function the_privacy_notice_comes_back_for_whoever_closed_it_before_the_new_version_with_its_own_message(): void
    {
        $closedBefore = User::factory()->create(['privacy_notice_dismissed_at' => '2026-10-01 09:00:00']);
        $closedAfter = User::factory()->create(['privacy_notice_dismissed_at' => '2026-11-12 09:00:00']);
        $neverClosed = User::factory()->create(['privacy_notice_dismissed_at' => null]);

        // Sem a nova versão, quem a fechou depois de 2026-09-15 não volta a ver o aviso.
        config(['lapis.legal.lesson_service_effective_from' => null]);
        $this->assertFalse($closedBefore->shouldSeePrivacyNotice());
        $this->assertSame('general', PrivacyVersion::latestChange());

        // Com ela em vigor, volta a todos os que a fecharam ANTES da data; quem a fechou depois já a viu.
        config(['lapis.legal.lesson_service_effective_from' => '2026-11-10']);
        $this->assertTrue($closedBefore->shouldSeePrivacyNotice());
        $this->assertFalse($closedAfter->shouldSeePrivacyNotice());
        $this->assertTrue($neverClosed->shouldSeePrivacyNotice());
        $this->assertSame('lesson_service', PrivacyVersion::latestChange());

        // E o aviso diz o que mudou (e não o texto da versão anterior).
        $this->actingAs($closedBefore)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.should_see_privacy_notice', true)->where('auth.privacy_notice_topic', 'lesson_service'));
    }

    #[Test]
    public function the_pages_show_the_version_that_is_in_force(): void
    {
        config(['lapis.legal.lesson_service_effective_from' => null]);
        $this->get('/privacidade')->assertOk()->assertInertia(fn ($page) => $page->where('document.effective_from', '2026-09-15'));
        $this->get('/tratamento-de-dados')->assertOk()->assertInertia(fn ($page) => $page->where('document.effective_from', '2026-09-15'));

        config(['lapis.legal.lesson_service_effective_from' => '2026-11-10']);
        $this->get('/privacidade')->assertOk()->assertInertia(fn ($page) => $page->where('document.effective_from', '2026-11-10'));
        $this->get('/tratamento-de-dados')->assertOk()->assertInertia(fn ($page) => $page->where('document.effective_from', '2026-11-10'));
    }
}
