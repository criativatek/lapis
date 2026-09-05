<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentRecorder;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A autoavaliação na Pauta de Avaliação.
 *
 * O professor decide comparando: o que a evidência diz, o que o Lapispro propõe,
 * o que o aluno diz de si próprio. Faltando a terceira, a comparação faz-se de
 * memória ou noutro ecrã — que é a mesma coisa que não a fazer.
 *
 * E NUNCA ENTRA NO CÁLCULO. Aparece ao lado do resultado, jamais dentro dele
 * (§15). Estes testes fixam as duas metades: que chega, e que não pesa.
 */
class EvaluationSheetSelfAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    private function classUlid(User $teacher): string
    {
        return $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    /**
     * Uma autoavaliação submetida para um aluno: o juízo global e um domínio.
     *
     * Escrita pelo MESMO serviço que o formulário usa, para que o que o teste
     * grava seja o que a aplicação grava.
     *
     * @return int o domínio sobre o qual o aluno se pronunciou — a ordem das
     *             perguntas do modelo não é a das colunas da pauta, e assumir
     *             que era faria o teste passar por acaso
     */
    private function submitSelfAssessment(User $teacher, string $studentName, string $globalCode, string $domainCode): int
    {
        return $this->asTenant($teacher, function () use ($studentName, $globalCode, $domainCode): int {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            /** @var AcademicPeriod $period */
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            /** @var Enrollment $enrollment */
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $studentName);

            $template = app(SelfAssessmentTemplateProvider::class)->forClass($class);
            $template->questions->load('domain');

            /** @var SelfAssessmentQuestion $global */
            $global = $template->questions->firstWhere('role', SelfAssessmentQuestionRole::Global);
            /** @var SelfAssessmentQuestion $perDomain */
            $perDomain = $template->questions->whereNotNull('domain_id')->sortBy('sequence')->first();

            $levelOf = fn (SelfAssessmentQuestion $question, string $code): int => $question->scale_id === null
                ? 0
                : (int) ScaleLevel::query()
                    ->where('scale_id', $question->scale_id)
                    ->where('code', $code)
                    ->firstOrFail()->id;

            app(SelfAssessmentRecorder::class)->save(
                $class,
                $period,
                $enrollment,
                ['answers' => [
                    $global->id => $levelOf($global, $globalCode),
                    $perDomain->id => $levelOf($perDomain, $domainCode),
                ]],
                SelfAssessmentFilledBy::TeacherInterview,
            );

            return (int) $perDomain->domain_id;
        });
    }

    /**
     * A linha de um domínio na pauta deste aluno.
     *
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function domainRow(array $student, int $domainId): array
    {
        foreach ($student['domains'] as $domain) {
            if ((int) $domain['domain_id'] === $domainId) {
                return $domain;
            }
        }

        $this->fail("A pauta não tem o domínio {$domainId}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function student(User $teacher, string $classUlid, string $name): array
    {
        foreach ($this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"))['sheet']['students'] as $student) {
            if ($student['name'] === $name) {
                return $student;
            }
        }

        $this->fail("Não há linha para {$name} na pauta.");
    }

    // ------------------------------------------------------------- presente

    #[Test]
    public function what_the_student_said_reaches_the_sheet_beside_what_the_evidence_says(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $domainId = $this->submitSelfAssessment($teacher, 'Carolina Nunes', '4', '3');

        $carolina = $this->student($teacher, $classUlid, 'Carolina Nunes');

        // O NÚMERO É O JUÍZO, e a menção vem ao lado — como em toda a
        // aplicação.
        $this->assertSame('4', $carolina['self_assessment']['code']);
        $this->assertSame('Bom', $carolina['self_assessment']['label']);
        $this->assertArrayHasKey('sequence', $carolina['self_assessment']);
        $this->assertArrayHasKey('is_negative', $carolina['self_assessment']);

        // E o que ela disse sobre um domínio, no próprio domínio — nunca
        // espalhado por todos.
        $this->assertSame('3', $this->domainRow($carolina, $domainId)['self_assessment']['code']);

        foreach ($carolina['domains'] as $domain) {
            if ((int) $domain['domain_id'] !== $domainId) {
                $this->assertNull($domain['self_assessment']);
            }
        }
    }

    #[Test]
    public function a_student_who_said_nothing_gets_an_absence_and_never_a_zero(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $this->submitSelfAssessment($teacher, 'Carolina Nunes', '4', '3');

        $other = $this->student($teacher, $classUlid, 'Diogo Ferreira');

        $this->assertNull($other['self_assessment']);

        foreach ($other['domains'] as $domain) {
            $this->assertNull($domain['self_assessment']);
        }
    }

    #[Test]
    public function a_class_where_nobody_self_assessed_carries_the_absence_and_nothing_else(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        foreach ($this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"))['sheet']['students'] as $student) {
            // A chave existe sempre — é o ecrã que decide não desenhar uma
            // coluna vazia, e para isso precisa de a poder ler.
            $this->assertArrayHasKey('self_assessment', $student);
            $this->assertNull($student['self_assessment']);
        }
    }

    #[Test]
    public function a_draft_is_not_a_submission(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $this->submitSelfAssessment($teacher, 'Carolina Nunes', '4', '3');

        // Voltar ao rascunho é voltar a «ainda não disse»: o que o aluno está a
        // rever não é ainda o que ele disse.
        $this->asTenant($teacher, function (): void {
            SelfAssessment::query()->firstOrFail()
                ->forceFill(['status' => SelfAssessmentStatus::Draft])->save();
        });

        $this->assertNull($this->student($teacher, $classUlid, 'Carolina Nunes')['self_assessment']);
    }

    // ----------------------------------------------- nunca entra no cálculo

    #[Test]
    public function the_self_assessment_changes_no_result_and_no_proposal(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $before = $this->student($teacher, $classUlid, 'Carolina Nunes');

        // Uma autoavaliação deliberadamente distante do resultado: se pesasse
        // alguma coisa, ver-se-ia.
        $this->submitSelfAssessment($teacher, 'Carolina Nunes', '1', '1');

        $after = $this->student($teacher, $classUlid, 'Carolina Nunes');

        $this->assertSame($before['overall'], $after['overall']);
        $this->assertSame($before['classification'], $after['classification']);

        foreach ($before['domains'] as $index => $domain) {
            $this->assertSame($domain['normalized_value'], $after['domains'][$index]['normalized_value']);
            $this->assertSame($domain['scale_level_code'], $after['domains'][$index]['scale_level_code']);
        }
    }

    // -------------------------------------------------------- o que se guarda

    #[Test]
    public function a_kept_sheet_freezes_what_the_student_had_said(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $domainId = $this->submitSelfAssessment($teacher, 'Carolina Nunes', '4', '3');

        // Dentro do 1.º Semestre do cenário demo (14/09/2026 a 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $periodUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);
        $effectiveAt = '2026-12-15';

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Momento com autoavaliação',
            'effective_at' => $effectiveAt,
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payload = $this->asTenant($teacher, fn (): array => EvaluationSheetExport::query()
            ->orderByDesc('id')->firstOrFail()->payload);

        $carolina = collect($payload['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertSame('4', $carolina['self_assessment']['code']);
        $this->assertSame('3', $this->domainRow($carolina, $domainId)['self_assessment']['code']);

        // E O QUE FICOU GUARDADO NÃO SE MEXE. O aluno muda de ideias depois; a
        // fotografia continua a dizer o que dizia.
        $this->submitSelfAssessment($teacher, 'Carolina Nunes', '2', '2');

        $again = $this->asTenant($teacher, fn (): array => EvaluationSheetExport::query()
            ->orderByDesc('id')->firstOrFail()->payload);

        $this->assertSame('4', collect($again['students'])->firstWhere('name', 'Carolina Nunes')['self_assessment']['code']);
        // E a pauta ATUAL acompanha, que é o outro lado da mesma regra.
        $this->assertSame('2', $this->student($teacher, $classUlid, 'Carolina Nunes')['self_assessment']['code']);
    }

    #[Test]
    public function a_sheet_kept_before_this_feature_still_opens(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        // Dentro do 1.º Semestre do cenário demo (14/09/2026 a 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $periodUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);
        $effectiveAt = '2026-12-15';

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Momento antigo',
            'effective_at' => $effectiveAt,
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Uma pauta guardada ANTES desta funcionalidade não traz a chave de
        // todo — e tem de continuar a abrir exatamente como abria. Reescrito e
        // re-selado, porque o que se está a provar é a leitura do payload
        // antigo, não a deteção de adulteração (que tem o seu próprio teste).
        $exportUlid = $this->asTenant($teacher, function (): string {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;

            foreach ($payload['students'] as $index => $student) {
                unset($payload['students'][$index]['self_assessment']);

                foreach ($student['domains'] as $domainIndex => $domain) {
                    unset($payload['students'][$index]['domains'][$domainIndex]['self_assessment']);
                }
            }

            // O modelo é imutável por desenho; este é o único sítio em toda a
            // suite que precisa de fabricar um registo antigo, e fá-lo pela
            // base de dados em vez de abrir uma porta no modelo.
            DB::table('evaluation_sheet_exports')
                ->where('id', $export->id)
                ->update([
                    'payload' => json_encode($payload),
                    'payload_hash' => CanonicalPayload::hash($payload),
                ]);

            return $export->ulid;
        });

        $response = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$exportUlid}");
        $props = $this->props($response);

        $this->assertNull($props['integrityFailure']);
        $this->assertNotNull($props['snapshot']);
        $this->assertArrayNotHasKey('self_assessment', $props['snapshot']['students'][0]);
    }
}
