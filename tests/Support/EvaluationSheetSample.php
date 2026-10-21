<?php

namespace Tests\Support;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use Illuminate\Support\Carbon;

/**
 * A small Pauta made by hand, in memory: five students that between them take
 * every branch of the sheet's cell text (a decision on a domain, a proposal
 * nobody decided, no classification, a decision on a percentage scale, a missing
 * domain, a coverage warning, no class number, an old payload without the newer
 * keys), as a kept moment and as the live sheet.
 *
 * The characterization fixtures of the CSV and the Excel were generated from
 * THIS data by the code that wrote them before their cell text was shared:
 * changing it changes those files.
 */
final class EvaluationSheetSample
{
    public static function kept(bool $withSelfAssessment): EvaluationSheetDocument
    {
        $sheet = self::sheet($withSelfAssessment);

        $export = (new EvaluationSheetExport)->forceFill([
            'scope' => ClassificationScope::Period,
            'moment_label' => 'Momento intercalar do 1.º Semestre',
            'effective_at' => Carbon::parse('2026-12-15'),
            'exported_at' => Carbon::parse('2026-12-16 10:30:00', 'UTC'),
            'payload' => [
                'version' => 1,
                'class' => ['label' => '7.º A', 'subject' => 'Português', 'academic_year' => '2026/2027'],
                'period' => ['label' => '1.º Semestre', 'kind_label' => 'Semestre'],
                'moment' => ['label' => 'Momento intercalar do 1.º Semestre', 'effective_at' => '2026-12-15', 'kind' => 'interim'],
                'author' => ['name' => 'Professora Teste'],
                'domains' => $sheet['domains'],
                'students' => $sheet['students'],
                'warnings' => ['Aviso de cobertura registado no momento.'],
            ],
        ]);

        return EvaluationSheetDocument::fromSnapshot($export);
    }

    public static function live(): EvaluationSheetDocument
    {
        return EvaluationSheetDocument::fromLiveSheet(self::schoolClass(), self::period(), self::sheet(withSelfAssessment: false), ClassificationScope::Period);
    }

    public static function schoolClass(): SchoolClass
    {
        $class = new SchoolClass;
        $class->label = '7.º A';
        $class->setRelation('subject', (new Subject)->forceFill(['name' => 'Português']));
        $class->setRelation('academicYear', (new AcademicYear)->forceFill(['label' => '2026/2027']));

        return $class;
    }

    public static function period(): AcademicPeriod
    {
        $period = new AcademicPeriod;
        $period->label = '1.º Semestre';
        $period->kind = AcademicPeriodKind::Semester;

        return $period;
    }

    /**
     * @return array{domains: list<array<string, mixed>>, students: list<array<string, mixed>>}
     */
    public static function sheet(bool $withSelfAssessment): array
    {
        $reading = fn (string $display, bool $previous = false, bool $earlier = false): array => [
            'display' => $display, 'code' => $display, 'criteria' => 'Perfil A · versão 2', 'previous_criteria' => $previous, 'earlier' => $earlier,
        ];

        $domains = [
            ['domain_id' => 10, 'name' => 'Oralidade', 'color' => '#2563EB'],
            ['domain_id' => 11, 'name' => 'Escrita', 'color' => 'não é uma cor'],
        ];

        $students = [
            // Tudo preenchido: decisão num domínio, proposta no outro, decisão final por nível.
            [
                'enrollment_id' => 1, 'class_number' => 1, 'name' => 'Aluno Um',
                'overall' => ['normalized_value' => '78.46', 'scale_value' => '4', 'scale_level_code' => 'B', 'scale_level_label' => 'Bom', 'has_coverage_warning' => false] + ($withSelfAssessment ? [] : []),
                'domains' => [
                    ['domain_id' => 10, 'normalized_value' => '81.25', 'scale_level_code' => 'B', 'scale_level_label' => 'Bom', 'decided_scale_level_code' => 'MB', 'decided_scale_level_label' => 'Muito Bom', 'has_coverage_warning' => false],
                    ['domain_id' => 11, 'normalized_value' => '70.0', 'scale_level_code' => 'S', 'scale_level_label' => 'Suficiente', 'has_coverage_warning' => true],
                ],
                'classification' => ['status' => 'confirmed', 'proposed_scale_level_code' => 'B', 'proposed_scale_level_label' => 'Bom', 'proposed_value' => '4', 'final_scale_level_code' => 'MB', 'final_scale_level_label' => 'Muito Bom', 'final_value' => '5', 'final_value_is_percentage' => false],
            ],
            // Sem número, sem classificação, sem o segundo domínio, sem valores.
            [
                'enrollment_id' => 2, 'class_number' => null, 'name' => 'Aluno Dois',
                'overall' => ['normalized_value' => null, 'scale_value' => null, 'scale_level_code' => null, 'scale_level_label' => null, 'has_coverage_warning' => true],
                'domains' => [
                    ['domain_id' => 10, 'normalized_value' => null, 'scale_level_code' => null, 'scale_level_label' => null, 'has_coverage_warning' => false],
                ],
                'classification' => null,
            ],
            // Só proposta: ninguém decidiu.
            [
                'enrollment_id' => 3, 'class_number' => 3, 'name' => 'Aluno Três',
                'overall' => ['normalized_value' => '55', 'scale_value' => '3', 'scale_level_code' => null, 'scale_level_label' => 'Suficiente', 'has_coverage_warning' => false],
                'domains' => [
                    ['domain_id' => 10, 'normalized_value' => '55', 'scale_level_code' => null, 'scale_level_label' => 'Suficiente', 'has_coverage_warning' => false],
                    ['domain_id' => 11, 'normalized_value' => '55', 'scale_level_code' => null, 'scale_level_label' => 'Suficiente', 'decided_scale_level_label' => 'Bom', 'has_coverage_warning' => false],
                ],
                'classification' => ['status' => 'proposed', 'proposed_scale_level_code' => null, 'proposed_scale_level_label' => null, 'proposed_value' => '3', 'final_scale_level_code' => null, 'final_scale_level_label' => null, 'final_value' => null],
            ],
            // Decisão numa escala de percentagem: 65 é 65 %, nunca o nível 65.
            [
                'enrollment_id' => 4, 'class_number' => 4, 'name' => 'Aluno Quatro',
                'overall' => ['normalized_value' => '64.9', 'scale_value' => null, 'scale_level_code' => null, 'scale_level_label' => null, 'has_coverage_warning' => false],
                'domains' => [],
                'classification' => ['status' => 'published', 'proposed_scale_level_code' => null, 'proposed_scale_level_label' => null, 'proposed_value' => '64', 'final_scale_level_code' => null, 'final_scale_level_label' => null, 'final_value' => '65', 'final_value_is_percentage' => true],
            ],
            // Uma fotografia antiga: sem as chaves de decisão por domínio nem de autoavaliação.
            [
                'enrollment_id' => 5, 'class_number' => 5, 'name' => 'Aluno Cinco',
                'overall' => ['normalized_value' => '40', 'scale_level_code' => 'I', 'scale_level_label' => 'Insuficiente'],
                'domains' => [
                    ['domain_id' => 10, 'normalized_value' => '40', 'scale_level_code' => 'I', 'scale_level_label' => 'Insuficiente'],
                    ['domain_id' => 11, 'normalized_value' => '40', 'scale_level_code' => 'I', 'scale_level_label' => 'Insuficiente'],
                ],
                'classification' => ['status' => 'proposed', 'proposed_scale_level_code' => null, 'proposed_scale_level_label' => null, 'proposed_value' => null, 'final_scale_level_code' => null, 'final_scale_level_label' => null, 'final_value' => null],
            ],
        ];

        if ($withSelfAssessment) {
            $students[0]['self_assessment'] = $reading('B');
            $students[0]['domains'][0]['self_assessment'] = $reading('MB', previous: true);
            $students[0]['domains'][1]['self_assessment'] = $reading('S', earlier: true);
            $students[2]['domains'][0]['self_assessment'] = $reading('S', previous: true, earlier: true);
        }

        return ['domains' => $domains, 'students' => $students];
    }
}
