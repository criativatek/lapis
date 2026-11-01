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
    public function the_privacy_policy_describes_unheld_lessons_the_strike_options_and_who_sees_what(): void
    {
        $teacher = implode(' ', $this->body(LegalDocuments::privacy(), 'Dados do professor'));

        // O registo de aulas não realizadas e as causas estruturadas.
        $this->assertStringContainsString('prevista e não realizada', $teacher);
        foreach (['greve', 'escola sem condições de funcionamento', 'encerramento por mau tempo', 'problema nas instalações', 'razão organizacional', 'outra causa'] as $cause) {
            $this->assertStringContainsString($cause, $teacher);
        }

        // As três opções de greve, com o que cada uma é — e que a adesão nunca se presume.
        $this->assertStringContainsString('«Greve» (sem mais especificação, a opção por defeito)', $teacher);
        $this->assertStringContainsString('«Greve — escola encerrada» (a escola estava encerrada por causa da greve)', $teacher);
        $this->assertStringContainsString('«Greve do professor»', $teacher);
        $this->assertStringContainsString('facultativa', $teacher);
        $this->assertStringContainsString('nunca presume nem deduz', $teacher);

        // Visibilidade: a causa é da turma; a declaração individual e a descrição são reservadas ao titular.
        $this->assertStringContainsString('visível para os professores com acesso à turma', $teacher);
        $this->assertStringContainsString('visíveis apenas para o professor que registou o resultado', $teacher);
        $this->assertStringContainsString('inteligência artificial', $teacher);

        // Correções, histórico, motivo escrito, e a correção da direção sem acesso ao reservado.
        $this->assertStringContainsString('Cada correção guarda quem a fez', $teacher);
        $this->assertStringContainsString('motivo curto escrito pelo professor', $teacher);
        $this->assertStringContainsString('sem aceder à indicação do professor', $teacher);

        // O texto não afirma um enquadramento jurídico que ainda não foi validado.
        foreach (['categoria especial', 'consentimento', 'interesse legítimo'] as $claim) {
            $this->assertStringNotContainsString($claim, mb_strtolower($teacher), "«{$claim}» exige validação jurídica e não pode ser afirmado aqui.");
        }
    }

    #[Test]
    public function the_privacy_policy_covers_retention_personal_export_backups_and_rectification(): void
    {
        $retention = implode(' ', $this->body(LegalDocuments::privacy(), 'Durante quanto tempo'));
        $this->assertStringContainsString('Histórico de correções do resultado de uma aula', $retention);
        $this->assertStringContainsString('Não está definido um prazo automático de eliminação', $retention);
        $this->assertStringContainsString('Exportação pessoal e cópias de segurança operacionais', $retention);
        $this->assertStringContainsString('as indicações e correções reservadas de outros professores não fazem parte dela', $retention);
        $this->assertStringContainsString('contêm todos os dados, incluindo os reservados', $retention);

        $rights = implode(' ', $this->body(LegalDocuments::privacy(), 'Os seus direitos'));
        $this->assertStringContainsString('o resultado e a causa de uma aula que registou', $rights);
        $this->assertStringContainsString('a correção no histórico da aula', $rights);

        $students = implode(' ', $this->body(LegalDocuments::privacy(), 'Dados dos alunos'));
        $this->assertStringContainsString('uma aula prevista não se realizou, com a causa', $students);
    }

    #[Test]
    public function the_processing_agreement_names_the_unheld_lesson_cause_and_the_correction_history(): void
    {
        $body = implode(' ', $this->body(LegalDocuments::processing(), 'Objeto, duração e natureza do tratamento'));

        $this->assertStringContainsString('aula prevista e não realizada', $body);
        $this->assertStringContainsString('a escola encerrada por causa da greve', $body);
        $this->assertStringContainsString('facultativo e reservado ao próprio professor', $body);
        $this->assertStringContainsString('os valores anteriores e novos e um motivo curto', $body);
        $this->assertStringContainsString('não identificam alunos', $body);
    }

    #[Test]
    public function the_terms_are_unchanged(): void
    {
        $this->assertSame('2026-09-03', LegalDocuments::terms()['effective_from']);
        $this->assertSame(self::TERMS_SHA256, hash('sha256', (string) json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE)));
    }

    #[Test]
    public function the_changed_documents_carry_a_valid_effective_date_that_is_set_at_publication(): void
    {
        // O texto novo só vale a partir de uma data de entrada em vigor DELIBERADA (config/lapis.php,
        // `LAPIS_LEGAL_PRIVACY_DATE` / `LAPIS_LEGAL_PROCESSING_DATE`), definida quando a validação jurídica
        // estiver feita e com o aviso prévio que a própria Política promete. Aqui só se exige que exista e
        // seja uma data válida, nunca anterior à versão que o texto anterior tinha.
        foreach ([LegalDocuments::privacy(), LegalDocuments::processing()] as $document) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $document['effective_from']);
            $this->assertGreaterThanOrEqual('2026-09-15', $document['effective_from']);
        }
    }

    #[Test]
    public function the_absence_reason_is_a_closed_list_with_no_free_text(): void
    {
        $this->assertSame(['training', 'official_duty', 'other'], array_column(TeacherAbsenceReason::cases(), 'value'));

        // Com a funcionalidade desligada (o defeito) o pedido é o de sempre.
        config(['lapis.lesson_not_held_enabled' => false]);
        $rules = (new RecordLessonOutcomeRequest)->rules();
        // Não existe nenhum campo de texto para o motivo, nem para «Outro».
        $this->assertSame(['outcome', 'reason', 'note'], array_keys($rules));

        // Ligada, só acrescenta o que a Política descreve: a causa, a especificação da greve, a descrição de
        // «outra causa» e o registo retroativo. Nenhum campo novo sem texto que o descreva.
        config(['lapis.lesson_not_held_enabled' => true]);
        $this->assertSame(['outcome', 'reason', 'note', 'cause', 'situation', 'cause_note', 'retroactive'], array_keys((new RecordLessonOutcomeRequest)->rules()));
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
