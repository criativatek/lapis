<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE WARNING AND THE BARRIER HAVE TO AGREE, AND THEY LIVE IN DIFFERENT
 * LANGUAGES.
 *
 * `AiPayloadSanitizer` runs on the server and REMOVES what it finds.
 * `resources/js/lib/personalData.ts` runs in the browser and ASKS THE TEACHER
 * about the same shapes before the request leaves. The second is only useful if
 * it looks for what the first would strip — otherwise a teacher is warned about
 * things that would have been fine and not warned about things that would not.
 *
 * A plain-text scan over both files, in the same spirit as
 * `CatalogCoherenceTest` and `AiArchitectureTest`: deliberately not a parser,
 * deliberately simple, and good enough to notice that somebody added a rule to
 * one side and not the other.
 *
 * IT ALSO PINS DOWN THE TWO THINGS THE DETECTOR MUST NOT DO — call anything,
 * and read a roster in the Centro de Ajuda. Both are one-line mistakes that
 * would pass every other test in the suite.
 */
class PersonalDataGuardCoherenceTest extends TestCase
{
    private const DETECTOR = 'resources/js/lib/personalData.ts';

    private const GUARD = 'resources/js/composables/useAiTextPrivacyGuard.ts';

    private const HELP_PANEL = 'resources/js/components/ai/HelpAssistantPanel.vue';

    /**
     * Every rule the server-side sanitiser strips is a rule the browser-side
     * detector warns about.
     */
    #[Test]
    public function the_detector_covers_every_sanitiser_rule(): void
    {
        $sanitiser = File::get(app_path('Support/Privacy/AiPayloadSanitizer.php'));
        $detector = File::get(base_path(self::DETECTOR));

        preg_match(
            '/protected const RULES = \[(.*?)\n    \];/s',
            $sanitiser,
            $block,
        );

        $this->assertNotEmpty($block, 'Não foi possível ler AiPayloadSanitizer::RULES.');

        preg_match_all("/^\s*'([a-z_]+)' => \[/m", $block[1], $matches);

        $ruleKeys = $matches[1];

        $this->assertNotEmpty($ruleKeys, 'AiPayloadSanitizer::RULES não devolveu chaves.');

        foreach ($ruleKeys as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $detector,
                "O sanitizador remove «{$key}» e o detetor do browser não avisa sobre isso. ".
                'Um professor seria avisado sobre umas coisas e não sobre outras, sem critério visível.',
            );
        }
    }

    /**
     * The detector is a set of regular expressions and nothing else.
     *
     * NO NETWORK, NO SERVICE, NO MODEL. §3 of the privacy addendum is explicit
     * — «NÃO chamar qualquer serviço externo para fazer esta deteção» — and
     * this is what makes it structural rather than a promise. It also matters
     * for a reason nobody states: a detector that phoned home would be sending
     * the very text it exists to keep from being sent.
     */
    #[Test]
    public function the_detector_calls_nothing(): void
    {
        foreach ([self::DETECTOR, self::GUARD] as $path) {
            $source = File::get(base_path($path));

            foreach (['fetch(', 'XMLHttpRequest', 'axios', 'router.post', 'router.get', 'import(', 'navigator.send'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$path} contém «{$forbidden}». A deteção é local e não fala com nada.",
                );
            }
        }
    }

    /**
     * THE CENTRO DE AJUDA MUST NOT FETCH A ROSTER TO CHECK FOR NAMES.
     *
     * §4 is explicit about it, and the reasoning is worth keeping next to the
     * assertion: loading student data in order to check whether a question
     * mentions a student would be a worse trade than the one it solves. The
     * panel therefore calls the guard with no names at all, and an arbitrary
     * name goes undetected there — which the Política de Privacidade and the
     * Centro de Ajuda both say out loud.
     */
    #[Test]
    public function the_help_assistant_passes_no_names_and_loads_no_roster(): void
    {
        $panel = File::get(base_path(self::HELP_PANEL));

        $this->assertStringContainsString('useAiTextPrivacyGuard', $panel, 'O assistente perdeu o guarda de texto livre.');

        $this->assertStringNotContainsString(
            'knownNames',
            $panel,
            'O assistente do Centro de Ajuda passa nomes ao detetor. Não tem roster e não pode ir buscar um.',
        );

        // The call itself, with no options argument at all — the precise form
        // of «passa nenhum nome». A blocklist of words like «roster» or
        // «alunos» cannot be used here: this file legitimately contains both,
        // once in a docblock saying it holds no roster and once in the sentence
        // that asks teachers not to write student names.
        $this->assertMatchesRegularExpression(
            '/privacy\.run\(\s*question\.value\s*,\s*send\s*\)/',
            $panel,
            'A chamada ao guarda no Assistente deixou de ser «texto e submit» — passou a levar mais alguma coisa.',
        );

        // And it imports nothing that could produce a student.
        foreach (['@/routes/students', '@/routes/classes', 'enrollment', 'Enrollment'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $panel,
                "O assistente do Centro de Ajuda refere «{$forbidden}». Não deve conhecer aluno nenhum.",
            );
        }
    }

    /**
     * Every screen where a teacher types text destined for an engine goes
     * through the shared guard.
     *
     * THE LIST IS EXPLICIT AND SHORT, and that is the point: three surfaces,
     * named, so a fourth one added next year fails this test until somebody
     * decides whether it needs the guard. The structured readings — a period's
     * results, a class's statistics, a student's synthesis — take no typed
     * input at all and are deliberately absent.
     */
    #[Test]
    public function every_free_text_surface_uses_the_shared_guard(): void
    {
        $surfaces = [
            self::HELP_PANEL => 'a pergunta do Assistente',
            'resources/js/pages/student-progress/Show.vue' => 'o objetivo do professor nas estratégias',
            'resources/js/pages/reports/Show.vue' => 'o corpo de uma secção antes de ser aperfeiçoada',
        ];

        foreach ($surfaces as $path => $what) {
            $source = File::get(base_path($path));

            $this->assertStringContainsString(
                'useAiTextPrivacyGuard',
                $source,
                "{$what} ({$path}) envia texto escrito por um professor sem passar pelo guarda.",
            );
            $this->assertStringContainsString(
                'AiTextPrivacyNotice',
                $source,
                "{$what} ({$path}) não mostra o aviso.",
            );
        }
    }

    /**
     * The structured readings show no free-text warning, because there is no
     * free text in them to warn about.
     *
     * A notice under a button that takes no input would be noise, and noise is
     * what teaches people to stop reading notices.
     */
    #[Test]
    public function the_structured_readings_carry_no_free_text_warning(): void
    {
        $panel = File::get(base_path('resources/js/components/ai/AiReadingPanel.vue'));

        $this->assertStringNotContainsString('AiTextPrivacyNotice', $panel);
        $this->assertStringNotContainsString('useAiTextPrivacyGuard', $panel);
    }
}
