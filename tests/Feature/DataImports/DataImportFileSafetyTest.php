<?php

namespace Tests\Feature\DataImports;

use App\Services\Import\Backup\ReadBackupUpload;
use App\Services\Import\Backup\ValidateBackupPayload;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Import\Backup\BackupValidationException;
use App\Support\Import\Backup\SecretScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Every gate a hostile or merely broken file has to pass before a single
 * byte of it is written to the database (§6, §17, §18 of the import
 * brief). Exercised mostly at the service level — ReadBackupUpload,
 * ValidateBackupPayload, SecretScanner directly — because a corrupt or
 * oversized ZIP is far cheaper and more precise to construct in-process
 * than to fight upload MIME-sniffing for. DataImportFileSafetyHttpTest
 * covers the same gates through the real /data-imports endpoint.
 */
class DataImportFileSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): ReadBackupUpload
    {
        return app(ReadBackupUpload::class);
    }

    private function validator(): ValidateBackupPayload
    {
        return app(ValidateBackupPayload::class);
    }

    /**
     * @param  array<int, array{name: string, content: string}>  $entries
     */
    private function zipWith(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-test-zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry) {
            $zip->addFromString($entry['name'], $entry['content']);
        }

        $zip->close();

        return $path;
    }

    private function minimalPayload(): array
    {
        return [
            'schema_version' => 3,
            'app_version' => '0.43.0',
            'generated_at' => now()->toIso8601String(),
            'organization' => ['ulid' => (string) Str::ulid(), 'name' => 'Escola Exemplo', 'type' => 'personal'],
            'classes' => [],
            'students' => [],
            'enrollments' => [],
            'instruments' => [],
            'classifications' => [],
        ];
    }

    #[Test]
    public function a_corrupt_zip_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-corrupt');
        file_put_contents($path, 'PK'.random_bytes(64));

        $this->expectException(BackupValidationException::class);
        $this->reader()->readZip($path);
    }

    #[Test]
    public function a_zip_without_the_backup_entry_is_rejected(): void
    {
        $path = $this->zipWith([['name' => 'readme.txt', 'content' => 'not the backup']]);

        try {
            $this->reader()->readZip($path);
            $this->fail('Devia ter rejeitado o ZIP sem backup-lapis.json.');
        } catch (BackupValidationException $exception) {
            $this->assertStringContainsString('backup-lapis.json', $exception->getMessage());
        }
    }

    /**
     * Zip Slip: an entry name that would escape whatever directory a naive
     * extractTo() used. This importer never calls extractTo() at all — the
     * check exists as defense in depth regardless, and this proves it
     * actually runs instead of assuming the missing extractTo() is enough.
     */
    #[Test]
    #[DataProvider('unsafeEntryNames')]
    public function a_zip_entry_with_a_path_traversal_name_is_rejected(string $unsafeName): void
    {
        $path = $this->zipWith([['name' => $unsafeName, 'content' => 'x']]);

        try {
            $this->reader()->readZip($path);
            $this->fail("Devia ter rejeitado a entrada insegura: {$unsafeName}");
        } catch (BackupValidationException $exception) {
            $this->assertSame(__('O backup está danificado.'), $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeEntryNames(): array
    {
        return [
            'parent traversal' => ['../../../../etc/passwd'],
            'absolute unix path' => ['/etc/passwd'],
            'absolute windows path' => ['\\Windows\\System32\\config'],
            'traversal inside a nested path' => ['assets/../../secrets.json'],
        ];
    }

    #[Test]
    public function a_zip_entry_larger_than_the_per_entry_limit_is_rejected(): void
    {
        // Highly compressible on purpose — the check reads the UNCOMPRESSED
        // stat size, so this stays a small file on disk while still
        // reporting >20MB once inflated.
        $oversized = str_repeat('a', 21 * 1024 * 1024);
        $path = $this->zipWith([['name' => 'backup-lapis.json', 'content' => $oversized]]);

        $this->expectException(BackupValidationException::class);
        $this->reader()->readZip($path);
    }

    #[Test]
    public function zip_entries_whose_combined_size_exceeds_the_total_limit_are_rejected(): void
    {
        $chunk = str_repeat('b', 11 * 1024 * 1024);
        $path = $this->zipWith([
            ['name' => 'a.json', 'content' => $chunk],
            ['name' => 'b.json', 'content' => $chunk],
            ['name' => 'c.json', 'content' => $chunk],
            ['name' => 'd.json', 'content' => $chunk],
            ['name' => 'backup-lapis.json', 'content' => $chunk],
        ]);

        $this->expectException(BackupValidationException::class);
        $this->reader()->readZip($path);
    }

    #[Test]
    public function a_zip_with_too_many_entries_is_rejected(): void
    {
        $entries = [];

        for ($i = 0; $i <= 500; $i++) {
            $entries[] = ['name' => "file-{$i}.txt", 'content' => 'x'];
        }

        $path = $this->zipWith($entries);

        $this->expectException(BackupValidationException::class);
        $this->reader()->readZip($path);
    }

    #[Test]
    public function malformed_json_text_is_rejected(): void
    {
        $this->expectException(BackupValidationException::class);
        $this->validator()->validate('{not valid json');
    }

    #[Test]
    public function valid_json_that_is_not_an_object_or_array_is_rejected(): void
    {
        $this->expectException(BackupValidationException::class);
        $this->validator()->validate('"just a string"');
    }

    #[Test]
    public function a_missing_schema_version_is_rejected(): void
    {
        $payload = $this->minimalPayload();
        unset($payload['schema_version']);

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    #[Test]
    public function a_schema_version_older_than_the_minimum_supported_is_rejected(): void
    {
        $payload = $this->minimalPayload();
        $payload['schema_version'] = 1;

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    #[Test]
    public function a_schema_version_newer_than_this_app_understands_is_rejected(): void
    {
        $payload = $this->minimalPayload();
        $payload['schema_version'] = BackupSchemaCompatibility::CURRENT + 1;

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    #[Test]
    public function a_missing_organization_block_is_rejected(): void
    {
        $payload = $this->minimalPayload();
        unset($payload['organization']);

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    #[Test]
    public function a_malformed_organization_block_is_rejected(): void
    {
        $payload = $this->minimalPayload();
        $payload['organization'] = ['ulid' => 'not-a-ulid', 'name' => 'X', 'type' => 'personal'];

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    /**
     * The legacy-compatible minimum, schema_version 2, still validates as a
     * whole file — only the rows that genuinely need what v2 lacks
     * (enrolled_on) are handled downstream by BuildImportPlan, not rejected
     * here.
     */
    #[Test]
    public function the_oldest_still_supported_schema_version_is_accepted(): void
    {
        $payload = $this->minimalPayload();
        $payload['schema_version'] = BackupSchemaCompatibility::MINIMUM_SUPPORTED;

        $result = $this->validator()->validate((string) json_encode($payload));

        $this->assertSame(BackupSchemaCompatibility::LegacyCompatible, $result['schemaCompatibility']);
    }

    /**
     * A single malformed row degrades that row, not the whole file (§6,
     * §52) — one bad enrollment must never block fifty good classes.
     */
    #[Test]
    public function a_malformed_row_is_dropped_and_recorded_without_failing_the_whole_backup(): void
    {
        $payload = $this->minimalPayload();
        $payload['classes'] = [
            ['ulid' => (string) Str::ulid(), 'label' => '', 'status' => 'preparation', 'academic_year' => '2026/2027', 'subject' => null],
        ];

        $result = $this->validator()->validate((string) json_encode($payload));

        $this->assertSame([], $result['canonical']['classes']);
        $this->assertCount(1, $result['rowIssues']);
        $this->assertSame('classes', $result['rowIssues'][0]['domain']);
    }

    #[Test]
    #[DataProvider('secretShapedKeys')]
    public function a_payload_carrying_a_secret_shaped_key_at_any_depth_is_rejected(string $key): void
    {
        $payload = $this->minimalPayload();
        $payload['classes'] = [['nested' => [$key => 'value']]];

        $this->expectException(BackupValidationException::class);
        $this->validator()->validate((string) json_encode($payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secretShapedKeys(): array
    {
        return [
            'password' => ['password'],
            'password_hash' => ['password_hash'],
            'remember_token' => ['remember_token'],
            'two_factor_secret' => ['two_factor_secret'],
            'two_factor_recovery_codes' => ['two_factor_recovery_codes'],
            'passkey' => ['passkey_public_key'],
            'session id' => ['session_id'],
            'api key' => ['api_key'],
            'smtp password' => ['smtp_password'],
            'invitation token' => ['invitation_token'],
            'token hash' => ['token_hash'],
            'csrf token' => ['csrf_token'],
            'encryption key' => ['encryption_key'],
            'app key' => ['app_key'],
            'generic secret' => ['client_secret'],
        ];
    }

    #[Test]
    public function the_secret_scanner_finds_forbidden_keys_nested_arbitrarily_deep(): void
    {
        $found = app(SecretScanner::class)->scan([
            'classes' => [
                ['label' => '7.º A', 'meta' => ['auth' => ['password' => 'x']]],
            ],
        ]);

        $this->assertNotEmpty($found);
        $this->assertStringContainsString('password', $found[0]);
    }

    #[Test]
    public function the_secret_scanner_finds_nothing_in_a_genuine_backup_shape(): void
    {
        $found = app(SecretScanner::class)->scan($this->minimalPayload());

        $this->assertSame([], $found);
    }

    #[Test]
    #[DataProvider('schemaCompatibilityCases')]
    public function schema_compatibility_classifies_every_boundary_correctly(mixed $version, BackupSchemaCompatibility $expected): void
    {
        $this->assertSame($expected, BackupSchemaCompatibility::for($version));
    }

    /**
     * @return array<string, array{0: mixed, 1: BackupSchemaCompatibility}>
     */
    public static function schemaCompatibilityCases(): array
    {
        return [
            'current' => [BackupSchemaCompatibility::CURRENT, BackupSchemaCompatibility::Supported],
            'legacy minimum' => [BackupSchemaCompatibility::MINIMUM_SUPPORTED, BackupSchemaCompatibility::LegacyCompatible],
            'legacy between minimum and current' => [BackupSchemaCompatibility::CURRENT - 1, BackupSchemaCompatibility::LegacyCompatible],
            'below minimum' => [BackupSchemaCompatibility::MINIMUM_SUPPORTED - 1, BackupSchemaCompatibility::Invalid],
            'zero' => [0, BackupSchemaCompatibility::Invalid],
            'negative' => [-1, BackupSchemaCompatibility::Invalid],
            'newer than current' => [BackupSchemaCompatibility::CURRENT + 1, BackupSchemaCompatibility::UnsupportedNewer],
            'non-integer string' => ['3', BackupSchemaCompatibility::Invalid],
            'non-integer null' => [null, BackupSchemaCompatibility::Invalid],
        ];
    }
}
