<?php

namespace Tests\Unit\Import;

use App\Services\Import\Backup\ValidateBackupPayload;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema 16: a self-assessment answer written on a scale without levels carries
 * its number as `numeric_value`. A backup before that has no such field, and
 * every one of its answers keeps meaning what it meant.
 */
class BackupSelfAssessmentNumericValueTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $response
     */
    private function validated(array $response, int $schemaVersion = BackupSchemaCompatibility::CURRENT): array
    {
        return app(ValidateBackupPayload::class)->validate((string) json_encode([
            'schema_version' => $schemaVersion,
            'app_version' => '0.167.0',
            'generated_at' => '2026-11-21T00:00:00+00:00',
            'organization' => ['ulid' => '01M1SWTDACW2M4QKC49C9EH1FN', 'name' => 'Escola de Teste', 'type' => 'institutional'],
            'self_assessment_responses' => [array_merge([
                'self_assessment_ulid' => '01M1SWTDACW2M4QKC49C9EH1FK',
                'question_role' => 'global',
                'question_sequence' => 1,
                'scale_level' => null,
                'text_value' => null,
                'boolean_value' => null,
            ], $response)],
        ]));
    }

    #[Test]
    public function the_current_version_is_seventeen(): void
    {
        $this->assertSame(17, BackupSchemaCompatibility::CURRENT);
        $this->assertSame(BackupSchemaCompatibility::LegacyCompatible, BackupSchemaCompatibility::for(16));
        $this->assertSame(BackupSchemaCompatibility::LegacyCompatible, BackupSchemaCompatibility::for(15));
    }

    #[Test]
    public function a_decimal_string_is_kept_as_it_is(): void
    {
        foreach (['16.500', '16', '-3.5', '0.000', '100'] as $value) {
            $result = $this->validated(['numeric_value' => $value]);

            $this->assertSame([], $result['rowIssues'], $value);
            $this->assertSame($value, $result['canonical']['self_assessment_responses'][0]['numeric_value']);
        }
    }

    #[Test]
    public function a_backup_before_version_sixteen_reads_as_no_number(): void
    {
        $result = $this->validated([], 15);

        $this->assertSame([], $result['rowIssues']);
        $this->assertNull($result['canonical']['self_assessment_responses'][0]['numeric_value']);

        $explicitNull = $this->validated(['numeric_value' => null]);
        $this->assertNull($explicitNull['canonical']['self_assessment_responses'][0]['numeric_value']);
    }

    #[Test]
    public function something_that_is_not_a_number_the_column_can_keep_is_a_row_that_cannot_be_restored(): void
    {
        foreach (['abc', '1e3', '12.3456', '1000', '', '--1', ['16'], true] as $invalid) {
            $result = $this->validated(['numeric_value' => $invalid]);

            $this->assertSame([], $result['canonical']['self_assessment_responses'], json_encode($invalid));
            $this->assertSame('self_assessment_responses', $result['rowIssues'][0]['domain']);
        }
    }
}
