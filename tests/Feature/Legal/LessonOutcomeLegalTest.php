<?php

namespace Tests\Feature\Legal;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Http\Requests\Lessons\RecordLessonOutcomeRequest;
use App\Models\TeacherAbsenceReason;
use App\Support\Legal\LegalDocuments;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 0.146.0 — o fecho legal do resultado real da aula: a Política e o Acordo
 * nomeiam o novo tratamento, os Termos ficam intactos, o motivo da ausência é
 * só categoria, e nada disto chega à IA.
 */
class LessonOutcomeLegalTest extends TestCase
{
    /** sha256 dos Termos em vigor (2026-09-03). Mudar isto é uma decisão legal. */
    private const TERMS_SHA256 = '76414f530001dc25a21315d5ff7c54902ff59ef05c920a6696979c922ec03cf4';

    #[Test]
    public function the_privacy_policy_names_the_lesson_outcome_and_the_absence_category(): void
    {
        $teacher = implode(' ', $this->body(LegalDocuments::privacy(), 'Dados do professor'));
        $this->assertStringContainsString('resultado de cada aula', $teacher);
        $this->assertStringContainsString('categoria de ausência', $teacher);
        $this->assertStringContainsString('não é pedido nem guardado texto livre', $teacher);

        $students = implode(' ', $this->body(LegalDocuments::privacy(), 'Dados dos alunos'));
        $this->assertStringContainsString('outra atividade letiva', $students);
        $this->assertStringContainsString('descrição curta opcional', $students);

        // Nenhuma linguagem de saúde no texto que descreve este tratamento.
        $all = mb_strtolower($teacher);
        foreach (['doença', 'diagnóstico', 'consulta médica'] as $word) {
            $this->assertStringNotContainsString($word, $all);
        }
    }

    #[Test]
    public function the_processing_agreement_names_the_new_data_without_article_9(): void
    {
        $body = implode(' ', $this->body(LegalDocuments::processing(), 'Objeto, duração e natureza do tratamento'));

        $this->assertStringContainsString('resultado da ocorrência de cada aula', $body);
        $this->assertStringContainsString('atividade letiva da turma', $body);
        $this->assertStringContainsString('categoria operacional de ausência do professor', $body);
        $this->assertStringContainsString('não constitui categoria especial', $body);
    }

    #[Test]
    public function the_terms_are_unchanged(): void
    {
        $this->assertSame('2026-09-03', LegalDocuments::terms()['effective_from']);
        $this->assertSame(self::TERMS_SHA256, hash('sha256', (string) json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE)));
    }

    #[Test]
    public function the_changed_documents_take_effect_with_this_release(): void
    {
        $this->assertSame('2026-09-15', LegalDocuments::privacy()['effective_from']);
        $this->assertSame('2026-09-15', LegalDocuments::processing()['effective_from']);
    }

    #[Test]
    public function the_absence_reason_is_a_closed_list_with_no_free_text(): void
    {
        $this->assertSame(['training', 'official_duty', 'other'], array_column(TeacherAbsenceReason::cases(), 'value'));

        $rules = (new RecordLessonOutcomeRequest)->rules();
        // Não existe nenhum campo de texto para o motivo, nem para «Outro».
        $this->assertSame(['outcome', 'reason', 'note'], array_keys($rules));
    }

    #[Test]
    public function outcome_note_and_reason_never_reach_an_ai_prompt(): void
    {
        $this->assertFalse(SectionCatalogue::isRewritable(SectionKey::ClassLessons));

        $finder = (new Finder)->files()->name('*.php')->in(app_path())->path('/(^|\/)Ai\//');

        $this->assertNotEmpty(iterator_to_array($finder));

        foreach ($finder as $file) {
            $source = $file->getContents();
            foreach (['outcome_note', 'outcome_reason', 'LessonOutcome', 'ClassLessonRecord'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, $file->getRelativePathname().' não pode ler '.$needle);
            }
        }
    }

    /**
     * @param  array{sections: list<array{heading: string, body: list<string>}>}  $document
     * @return list<string>
     */
    private function body(array $document, string $heading): array
    {
        foreach ($document['sections'] as $section) {
            if ($section['heading'] === $heading) {
                return $section['body'];
            }
        }

        $this->fail("Secção «{$heading}» não encontrada.");
    }
}
