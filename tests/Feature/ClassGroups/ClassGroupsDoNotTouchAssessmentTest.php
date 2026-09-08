<?php

namespace Tests\Feature\ClassGroups;

use App\Actions\ClassGroups\AssignClassGroupMemberships;
use App\Models\AcademicPeriod;
use App\Models\ClassGroup;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassElements;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ClassResultsCalculator;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A GARANTIA MAIS IMPORTANTE DESTA FUNCIONALIDADE: os grupos NÃO entram na
 * avaliação.
 *
 * Um grupo é uma partição do horário. A pauta, o Quadro Síntese, as
 * classificações, os instrumentos e os relatórios continuam a ser da TURMA
 * INTEIRA — nenhum grupo entra num cálculo, numa proposta, num denominador ou
 * num filtro (§19 do briefing). Um aluno de T1 é avaliado com os mesmos
 * elementos, na mesma pauta e contra o mesmo perfil que um aluno de T2.
 *
 * O CASO É O MESMO ANTES E DEPOIS. Cada leitura é feita duas vezes contra a
 * turma do DemoDataSeeder — antes de existirem grupos, e depois de a turma
 * estar partida ao meio — e as duas respostas têm de ser iguais. Não é uma
 * contagem escrita à mão: é a leitura contra ela própria, e por isso continua
 * a valer no dia em que o seeder mudar de tamanho.
 */
class ClassGroupsDoNotTouchAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    #[Test]
    public function splitting_the_class_in_two_changes_no_assessment_reading(): void
    {
        [$class, $period] = $this->context();

        $before = $this->readings($class, $period);

        $this->splitInHalf($class);

        $after = $this->readings($class, $period);

        $this->assertSame(
            $before,
            $after,
            'Desdobrar a turma em T1/T2 alterou uma leitura de avaliação. Um grupo é do horário, e de mais nada.',
        );
    }

    #[Test]
    public function every_student_stays_on_the_sheet_whatever_group_they_are_in(): void
    {
        [$class, $period] = $this->context();

        $roster = $this->asTenant(fn (): int => $class->activeEnrollments()->count());

        $this->assertGreaterThan(2, $roster, 'O cenário precisa de alunos suficientes para partir ao meio.');

        $this->splitInHalf($class);

        $sheet = $this->asTenant(
            fn (): array => app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period),
        );

        $this->assertCount($roster, $sheet['students']);
    }

    #[Test]
    public function the_class_synopsis_still_covers_the_whole_class(): void
    {
        [$class] = $this->context();

        $roster = $this->asTenant(fn (): int => $class->activeEnrollments()->count());

        $this->splitInHalf($class);

        $synopsis = $this->asTenant(fn (): array => app(BuildClassSynopsis::class)->for($class));

        $this->assertCount($roster, $synopsis['students']);
    }

    /**
     * O sentinela que aguenta o refactor de daqui a um ano: NENHUM ficheiro da
     * avaliação pode sequer mencionar um grupo.
     *
     * Um teste de contagem prova que hoje o número está certo; este prova que
     * ninguém introduziu a ideia. É a diferença entre «a pauta tem seis alunos»
     * e «a pauta não sabe o que é um grupo» — e é a segunda que tem de ser
     * verdade para que a primeira continue a sê-lo depois de alguém acrescentar
     * um filtro «só T1» a um ecrã por parecer útil.
     */
    #[Test]
    public function no_assessment_code_mentions_class_groups_at_all(): void
    {
        $offenders = [];

        foreach ($this->assessmentFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'class_group') || str_contains($contents, 'ClassGroup')) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Um ficheiro da avaliação passou a conhecer os grupos da turma. A avaliação é sempre da turma inteira (§19).',
        );
    }

    /**
     * Metade da turma para T1, metade para T2 — pela ordem da pauta, para que
     * o corte seja o mesmo de execução para execução.
     */
    protected function splitInHalf(SchoolClass $class): void
    {
        $this->asTenant(function () use ($class): void {
            $first = ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 0]);
            $second = ClassGroup::create(['class_id' => $class->id, 'label' => 'T2', 'position' => 1]);

            $enrollments = $class->activeEnrollments()->orderBy('class_number')->get();
            $half = (int) ceil($enrollments->count() / 2);
            $assignments = [];

            foreach ($enrollments as $index => $enrollment) {
                $assignments[$enrollment->id] = $index < $half ? $first->id : $second->id;
            }

            app(AssignClassGroupMemberships::class)->execute($class, $assignments);
        });
    }

    /**
     * As leituras que têm de ficar iguais, reduzidas aos seus DADOS.
     *
     * Serializadas por JSON antes de comparar, e não guardadas como estão: os
     * resultados por período trazem modelos Eloquent lá dentro, e duas
     * chamadas devolvem sempre instâncias diferentes do mesmo aluno. Comparar
     * os objetos falharia por causa do identificador da instância — que não é
     * uma diferença nenhuma — e escondia a diferença que importa. Reduzidas a
     * JSON, o que se compara é o que a pauta diz.
     *
     * @return array<string, mixed>
     */
    protected function readings(SchoolClass $class, AcademicPeriod $period): array
    {
        $readings = $this->asTenant(fn (): array => [
            'sheet' => app(BuildEvaluationSheet::class)->for($class, $period, ClassificationScope::Period),
            'synopsis' => app(BuildClassSynopsis::class)->for($class),
            'period_results' => app(ClassResultsCalculator::class)->forPeriod($class, $period),
            'accumulated' => app(ClassResultsCalculator::class)->forAccumulated($class, $period),
            'elements' => app(BuildClassElements::class)->for(
                $class,
                $class->academicYear->periods()->orderBy('sequence')->get(),
            ),
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($readings), true);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    protected function assessmentFiles(): array
    {
        $files = [];

        foreach ([app_path('Services/Assessment'), app_path('Services/Reporting')] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return array{SchoolClass, AcademicPeriod} */
    protected function context(): array
    {
        return $this->asTenant(function (): array {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$schoolClass, $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }
}
