# Importação de Lista de Turma (Excel + Word) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher upload an Excel roster (and, optionally, a Word photo sheet) exported from the school's "Intuitivo" system, review a matched preview, and confirm it to enroll every student in one go — reusing the existing `StudentEnrollmentService` so encryption, pseudonym generation, and the blind index all stay correct.

**Architecture:** Two format-specific parsers (`RosterFileParser` for the Excel roster, `PhotoFileParser` for the Word photo sheet) produce plain readonly DTOs. A `RosterImportPreviewBuilder` merges them by name into preview rows — no persistence yet. `RosterImportController::store()` renders the preview as an Inertia page; `RosterImportController::confirm()` receives the (possibly edited) rows back from the browser and creates each enrollment through the existing service. Uploaded photos live in a token-named temp folder on the `local` (private) disk until confirmation, then move to permanent per-student storage; the temp folder is deleted right after. No new database table for import history — matches the approved spec.

**Tech Stack:** Laravel 13, `phpoffice/phpspreadsheet` (new dependency, for reading `.xls`/`.xlsx`), raw `ZipArchive` + `DOMDocument`/XPath for the Word file (no new dependency — `ext-zip` and `ext-dom` are already required by PHP itself), Vue 3 + Inertia for the frontend.

## Global Constraints

- English in code/DB, Portuguese (pt-PT) in the UI (project-wide rule).
- `DECIMAL`, never `float`, for anything that becomes a grade — not applicable to this feature (no numbers feed a calculation), but never let `class_number` or ids pass through as floats either.
- Mass assignment is opt-in per model via `#[Fillable]` — every new fillable field must be added explicitly to that attribute, never left to a bare `$fillable` array.
- ULID in exposed URLs, never sequential ids — the `token` used for the preview/confirm round trip is a UUID string, not a database id.
- Never a bare `exists:` validation rule on tenant-owned data — this feature only touches `SchoolClass` (already resolved via route-model binding + Policy) and creates new `Student`/`StudentIdentity`/`Enrollment` rows, so no new `exists:` rule is needed; if one becomes necessary, it must use `App\Rules\BelongsToCurrentOrganization`.
- **No student name, birth date, or photo from the real sample files (`Ficheiros avulsos/`, gitignored) may appear in code, tests, fixtures, commits, or comments.** Every fixture built in this plan uses invented names (e.g. "Maria Teste", "João Exemplo") and a synthetically generated tiny JPEG.
- NEE is never read from the roster file, in any task, under any circumstance — this is a standing architectural exclusion (`docs/domain-model.md` line 186), not a decision this feature revisits.
- `composer ci:check` (pint, larastan, `npm run lint:check`/`format:check`/`types:check`, `php artisan test`) must stay green after every task's commit.

---

## File Structure

**New files:**
- `database/migrations/2026_07_28_000100_add_photo_path_to_student_identities.php`
- `database/migrations/2026_07_28_000200_add_import_note_to_enrollments.php`
- `app/Domain/Import/RosterRow.php` — readonly DTO, one row of the parsed Excel sheet
- `app/Domain/Import/PhotoMatch.php` — readonly DTO, one (name, image bytes) pair extracted from the Word file
- `app/Services/Import/RosterFileParser.php` — Excel → `list<RosterRow>`
- `app/Services/Import/RosterFileParseException.php` — thrown on an unreadable/wrong-shape file
- `app/Services/Import/PhotoFileParser.php` — Word → `list<PhotoMatch>`
- `app/Services/Import/RosterImportPreviewBuilder.php` — merges roster rows + photo matches into plain preview-row arrays, flags duplicates
- `app/Support/Import/RosterImportTempStorage.php` — token-scoped temp-folder helper over `Storage::disk('local')`
- `app/Http/Controllers/RosterImportController.php` — `store()`, `previewPhoto()`, `confirm()`
- `app/Http/Controllers/StudentPhotoController.php` — `show()`, the permanent authenticated photo stream
- `app/Policies/StudentPolicy.php` — `viewPhoto()`
- `resources/js/pages/roster-imports/Preview.vue` — the review table
- `tests/Support/DocxFixtureBuilder.php` — test-only helper that assembles a minimal Word-with-altChunk-and-VML-image zip
- `tests/Unit/Import/RosterFileParserTest.php`
- `tests/Unit/Import/PhotoFileParserTest.php`
- `tests/Unit/Import/RosterImportPreviewBuilderTest.php`
- `tests/Feature/Classes/RosterImportTest.php`

**Modified files:**
- `app/Models/StudentIdentity.php` — add `photo_path` to `#[Fillable]`
- `app/Models/Enrollment.php` — add `import_note` to `#[Fillable]`
- `app/Services/StudentEnrollmentService.php` — `enrollNew()` accepts `birth_date`, `photo_path`, `import_note` (all optional)
- `app/Http/Controllers/ClassController.php` — `show()` adds a `photo_url` per student
- `resources/js/pages/classes/Show.vue` — "Importar lista" button + upload dialog
- `routes/web.php` — 5 new routes inside the existing `module:classes` group
- `composer.json` — add `phpoffice/phpspreadsheet`

---

### Task 1: Migrations — `photo_path` and `import_note`

**Files:**
- Create: `database/migrations/2026_07_28_000100_add_photo_path_to_student_identities.php`
- Create: `database/migrations/2026_07_28_000200_add_import_note_to_enrollments.php`

**Interfaces:**
- Produces: `student_identities.photo_path` (nullable string), `enrollments.import_note` (nullable string) — every later task that touches these models relies on these columns existing.

- [ ] **Step 1: Write the first migration**

```php
<?php
// database/migrations/2026_07_28_000100_add_photo_path_to_student_identities.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pointer to the student's photo file on the private disk — never a public
 * URL, never the bytes themselves. Nullable: most students still have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_identities', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('student_identities', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
```

- [ ] **Step 2: Write the second migration**

```php
<?php
// database/migrations/2026_07_28_000200_add_import_note_to_enrollments.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text carried over from a roster import (Repetente/ASE/PLNM, as they
 * appear in the source file). Never read by any calculation or rule — display
 * only. NEE is deliberately never written here (docs/domain-model.md §11.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('import_note', 255)->nullable()->after('late_entry_note');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('import_note');
        });
    }
};
```

- [ ] **Step 3: Run the migrations**

Run: `php artisan migrate`
Expected: both migrations listed as `Ran`.

- [ ] **Step 4: Verify columns exist**

Run: `php artisan tinker --execute="echo Schema::hasColumn('student_identities','photo_path') ? 'yes' : 'no'; echo Schema::hasColumn('enrollments','import_note') ? 'yes' : 'no';"`
Expected: `yesyes`

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_28_000100_add_photo_path_to_student_identities.php database/migrations/2026_07_28_000200_add_import_note_to_enrollments.php
git commit -m "feat(students): add photo_path and import_note columns"
```

---

### Task 2: Extend the enrollment service with the new optional fields

**Files:**
- Modify: `app/Models/StudentIdentity.php`
- Modify: `app/Models/Enrollment.php`
- Modify: `app/Services/StudentEnrollmentService.php`
- Test: `tests/Feature/Classes/ClassTest.php`

**Interfaces:**
- Consumes: the two new columns from Task 1.
- Produces: `StudentEnrollmentService::enrollNew(SchoolClass $class, array{name: string, class_number?: int|null, enrolled_on?: string|null, school_number?: string|null, birth_date?: string|null, photo_path?: string|null, import_note?: string|null} $data): Enrollment` — every later task that creates an enrollment calls this exact method with this exact shape.

- [ ] **Step 1: Write the failing test — manual enrollment still works, and the new optional fields persist when supplied**

Add to `tests/Feature/Classes/ClassTest.php` (inside the `ClassTest` class, alongside the existing enrollment tests):

```php
    #[Test]
    public function enrolling_a_student_accepts_optional_birth_date_and_note(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        app(\App\Support\Tenancy\CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => app(\App\Services\StudentEnrollmentService::class)->enrollNew($class, [
                'name' => 'Maria Teste',
                'enrolled_on' => '2026-09-14',
                'birth_date' => '2013-05-04',
                'import_note' => 'Repetente · ASE: B',
            ]),
        );

        $identity = StudentIdentity::firstOrFail();
        $this->assertSame('2013-05-04', $identity->birth_date->toDateString());
        $this->assertSame('Repetente · ASE: B', Enrollment::withoutGlobalScope('organization')->firstOrFail()->import_note);
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=enrolling_a_student_accepts_optional_birth_date_and_note`
Expected: FAIL — `enrollNew()` does not accept `birth_date`/`import_note` yet, or the columns are ignored.

- [ ] **Step 3: Add `photo_path` to `StudentIdentity`'s fillable list**

In `app/Models/StudentIdentity.php`, change:

```php
#[Fillable(['student_id', 'organization_id', 'display_name', 'school_number', 'birth_date'])]
```

to:

```php
#[Fillable(['student_id', 'organization_id', 'display_name', 'school_number', 'birth_date', 'photo_path'])]
```

Also add `@property string|null $photo_path` to the class docblock, next to the existing `@property Carbon|null $birth_date` line.

- [ ] **Step 4: Add `import_note` to `Enrollment`'s fillable list**

In `app/Models/Enrollment.php`, change:

```php
#[Fillable(['class_id', 'student_id', 'class_number', 'enrolled_on', 'left_on', 'status', 'is_late_entry', 'late_entry_note'])]
```

to:

```php
#[Fillable(['class_id', 'student_id', 'class_number', 'enrolled_on', 'left_on', 'status', 'is_late_entry', 'late_entry_note', 'import_note'])]
```

Also add `@property string|null $import_note` to the docblock, next to `@property string|null $late_entry_note`.

- [ ] **Step 5: Extend `StudentEnrollmentService::enrollNew()`**

Replace the full method body in `app/Services/StudentEnrollmentService.php`:

```php
    /**
     * @param  array{name: string, class_number?: int|null, enrolled_on?: string|null, school_number?: string|null, birth_date?: string|null, photo_path?: string|null, import_note?: string|null}  $data
     */
    public function enrollNew(SchoolClass $class, array $data): Enrollment
    {
        return DB::transaction(function () use ($class, $data): Enrollment {
            $student = Student::create(['pseudonym_code' => $this->uniquePseudonym()]);

            $student->identity()->create([
                'organization_id' => $this->currentOrganization->id(),
                'display_name' => $data['name'],
                'school_number' => $data['school_number'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
            ]);

            $enrolledOn = $data['enrolled_on'] ?? $class->academicYear->starts_on->toDateString();

            // Late entry is a UI marker; the engine uses enrolled_on. We flag it
            // when the entry date is after the class's academic year began.
            $isLate = Carbon::parse($enrolledOn)->greaterThan($class->academicYear->starts_on);

            return $class->enrollments()->create([
                'student_id' => $student->id,
                'class_number' => $data['class_number'] ?? null,
                'enrolled_on' => $enrolledOn,
                'status' => 'active',
                'is_late_entry' => $isLate,
                'import_note' => $data['import_note'] ?? null,
            ]);
        });
    }
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `php artisan test --filter=enrolling_a_student_accepts_optional_birth_date_and_note`
Expected: PASS

- [ ] **Step 7: Run the full existing Classes test suite to confirm nothing broke**

Run: `php artisan test --filter=ClassTest`
Expected: all tests PASS (the manual "Adicionar aluno" flow never sends these new keys, so `?? null` keeps it working exactly as before).

- [ ] **Step 8: Commit**

```bash
git add app/Models/StudentIdentity.php app/Models/Enrollment.php app/Services/StudentEnrollmentService.php tests/Feature/Classes/ClassTest.php
git commit -m "feat(students): accept birth date, photo path and import note on enrollment"
```

---

### Task 3: Roster Excel parser

**Files:**
- Create: `app/Domain/Import/RosterRow.php`
- Create: `app/Services/Import/RosterFileParseException.php`
- Create: `app/Services/Import/RosterFileParser.php`
- Test: `tests/Unit/Import/RosterFileParserTest.php`
- Modify: `composer.json`

**Interfaces:**
- Produces: `RosterRow` (readonly: `name: string`, `classNumber: ?int`, `birthDate: ?string` in `Y-m-d`, `situationCode: string`, `processNumber: ?string`, `note: ?string`) and `RosterFileParser::parse(string $path): array` returning `list<RosterRow>`. Task 5 (`RosterImportPreviewBuilder`) and Task 8 (`RosterImportController::store()`) both consume this exact signature.

- [ ] **Step 1: Add the PhpSpreadsheet dependency**

Run: `composer require phpoffice/phpspreadsheet`
Expected: `composer.json`/`composer.lock` updated, no errors.

- [ ] **Step 2: Write the DTO**

```php
<?php
// app/Domain/Import/RosterRow.php

namespace App\Domain\Import;

/**
 * One student, as read from the roster spreadsheet, before any matching or
 * persistence. Plain data — never touches Eloquent.
 */
final readonly class RosterRow
{
    public function __construct(
        public string $name,
        public ?int $classNumber,
        public ?string $birthDate,
        public string $situationCode,
        public ?string $processNumber,
        public ?string $note,
    ) {}
}
```

- [ ] **Step 3: Write the exception class**

```php
<?php
// app/Services/Import/RosterFileParseException.php

namespace App\Services\Import;

class RosterFileParseException extends \RuntimeException {}
```

- [ ] **Step 4: Write the failing unit test**

```php
<?php
// tests/Unit/Import/RosterFileParserTest.php

namespace Tests\Unit\Import;

use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterFileParserTest extends TestCase
{
    protected string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    /**
     * Builds a fixture spreadsheet with the same shape as a real Intuitivo
     * export: a report header above the data, a header row identified by its
     * own labels (not a fixed row number), then the student rows, then a
     * trailing "Total Alunos" line the parser must stop at.
     */
    protected function buildFixture(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A4', 'Agrupamento de Escolas Exemplo');
        $sheet->setCellValue('A9', '3º CICLO');
        $sheet->setCellValue('H9', 'RELAÇÃO DE TURMA');

        $sheet->setCellValue('A14', 'N.º MATR.');
        $sheet->setCellValue('C14', 'NOME');
        $sheet->setCellValue('I14', 'IDADE');
        $sheet->setCellValue('K14', 'DATA NASC.');
        $sheet->setCellValue('M14', 'SIT.');
        $sheet->setCellValue('N14', 'REPET.');
        $sheet->setCellValue('P14', 'ASE');
        $sheet->setCellValue('Q14', 'NEE');
        $sheet->setCellValue('S14', 'EMR');
        $sheet->setCellValue('T14', 'PLNM');
        $sheet->setCellValue('U14', 'N.º PROC.');

        $sheet->setCellValue('A15', 1);
        $sheet->setCellValue('C15', 'Maria Teste');
        $sheet->setCellValue('I15', 12);
        $sheet->setCellValueExplicit('K15', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2013-05-04')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M15', 'X');
        $sheet->setCellValue('P15', 'B');
        $sheet->setCellValue('Q15', 'X');
        $sheet->setCellValue('U15', '1001');

        $sheet->setCellValue('A16', 2);
        $sheet->setCellValue('C16', 'João Exemplo');
        $sheet->setCellValue('I16', 13);
        $sheet->setCellValueExplicit('K16', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2012-11-20')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M16', 'TR');
        $sheet->setCellValue('N16', 'X');
        $sheet->setCellValue('U16', '1002');

        $sheet->setCellValue('C19', 'Total Alunos - 2');

        $path = tempnam(sys_get_temp_dir(), 'roster_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    #[Test]
    public function it_reads_rows_below_the_header_it_finds_by_content(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser())->parse($this->tempPath);

        $this->assertCount(2, $rows);
        $this->assertSame('Maria Teste', $rows[0]->name);
        $this->assertSame(1, $rows[0]->classNumber);
        $this->assertSame('2013-05-04', $rows[0]->birthDate);
        $this->assertSame('X', $rows[0]->situationCode);
        $this->assertSame('1001', $rows[0]->processNumber);
        $this->assertSame('ASE: B', $rows[0]->note);
    }

    #[Test]
    public function repetente_and_ase_and_plnm_are_combined_into_one_note(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser())->parse($this->tempPath);

        $this->assertSame('Repetente', $rows[1]->note);
    }

    #[Test]
    public function it_never_reads_the_nee_column(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser())->parse($this->tempPath);

        // Row 1's fixture NEE cell is "X" — if the parser ever read it, it
        // would leak into the note or a new property. Neither may happen.
        $this->assertStringNotContainsString('NEE', (string) $rows[0]->note);
        $this->assertSame('ASE: B', $rows[0]->note);
    }

    #[Test]
    public function it_stops_at_the_total_alunos_line(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser())->parse($this->tempPath);

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function a_file_without_the_expected_header_throws_a_clear_error(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Ficheiro qualquer, sem nada a ver');
        $path = tempnam(sys_get_temp_dir(), 'bad_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempPath = $path;

        $this->expectException(RosterFileParseException::class);

        (new RosterFileParser())->parse($this->tempPath);
    }
}
```

- [ ] **Step 5: Run the tests to confirm they fail**

Run: `php artisan test --filter=RosterFileParserTest`
Expected: FAIL — `RosterFileParser` class does not exist yet.

- [ ] **Step 6: Write the parser**

```php
<?php
// app/Services/Import/RosterFileParser.php

namespace App\Services\Import;

use App\Domain\Import\RosterRow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the "Relação de Turma" roster export (Excel, .xls or .xlsx). The
 * report embeds a title block above the real table, so the header row is
 * located by its own cell content — never a fixed row number, and never
 * assumed to survive a slightly different export. NEE is never read.
 */
class RosterFileParser
{
    protected const HEADER_MATRICULA = 'N.º MATR.';

    protected const HEADER_NAME = 'NOME';

    protected const HEADER_AGE = 'IDADE';

    protected const HEADER_BIRTH_DATE = 'DATA NASC.';

    protected const HEADER_SITUATION = 'SIT.';

    protected const HEADER_REPEATER = 'REPET.';

    protected const HEADER_SOCIAL_SUPPORT = 'ASE';

    protected const HEADER_NON_NATIVE_PORTUGUESE = 'PLNM';

    protected const HEADER_PROCESS_NUMBER = 'N.º PROC.';

    protected const STOP_MARKER = 'Total Alunos';

    /**
     * @return list<RosterRow>
     */
    public function parse(string $path): array
    {
        // @ suppresses the legacy .xls reader's harmless "uninitialized string
        // offset" warnings from an embedded logo image the reader doesn't fully
        // understand — extraction still succeeds correctly around it.
        $sheet = @IOFactory::load($path)->getActiveSheet();

        $columns = $this->locateHeaderColumns($sheet);

        $rows = [];
        $row = $columns['header_row'] + 1;

        while ($row <= $sheet->getHighestRow()) {
            $nameCell = $sheet->getCell($columns[self::HEADER_NAME].$row)->getValue();

            if ($nameCell === null || str_contains((string) $nameCell, self::STOP_MARKER)) {
                break;
            }

            if (trim((string) $nameCell) === '') {
                $row++;

                continue;
            }

            $rows[] = $this->rowAt($sheet, $columns, $row);
            $row++;
        }

        return $rows;
    }

    /**
     * @return array<string, string|int>
     */
    protected function locateHeaderColumns(Worksheet $sheet): array
    {
        $needles = [
            self::HEADER_MATRICULA, self::HEADER_NAME, self::HEADER_BIRTH_DATE,
            self::HEADER_SITUATION, self::HEADER_PROCESS_NUMBER,
        ];

        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $found = [];

            foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
                $value = trim((string) $cell->getValue());

                if ($value !== '') {
                    $found[$value] = $cell->getColumn();
                }
            }

            $hasAllNeedles = count(array_intersect($needles, array_keys($found))) === count($needles);

            if ($hasAllNeedles) {
                $found['header_row'] = $row;

                return $found;
            }
        }

        throw new RosterFileParseException(
            'Não foi possível encontrar as colunas esperadas (N.º MATR., NOME, DATA NASC., SIT., N.º PROC.) neste ficheiro.',
        );
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function rowAt(Worksheet $sheet, array $columns, int $row): RosterRow
    {
        $matriculaRaw = $sheet->getCell($columns[self::HEADER_MATRICULA].$row)->getValue();

        return new RosterRow(
            name: trim((string) $sheet->getCell($columns[self::HEADER_NAME].$row)->getValue()),
            classNumber: is_numeric($matriculaRaw) ? (int) $matriculaRaw : null,
            birthDate: $this->readDate($sheet, $columns, $row),
            situationCode: trim((string) $sheet->getCell($columns[self::HEADER_SITUATION].$row)->getValue()),
            processNumber: $this->nullableString($sheet, $columns, self::HEADER_PROCESS_NUMBER, $row),
            note: $this->buildNote($sheet, $columns, $row),
        );
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function readDate(Worksheet $sheet, array $columns, int $row): ?string
    {
        $raw = $sheet->getCell($columns[self::HEADER_BIRTH_DATE].$row)->getValue();

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
        }

        $parsed = \DateTime::createFromFormat('d-m-Y', (string) $raw) ?: \DateTime::createFromFormat('Y-m-d', (string) $raw);

        return $parsed?->format('Y-m-d');
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function nullableString(Worksheet $sheet, array $columns, string $header, int $row): ?string
    {
        if (! isset($columns[$header])) {
            return null;
        }

        $value = trim((string) $sheet->getCell($columns[$header].$row)->getValue());

        return $value === '' ? null : $value;
    }

    /**
     * Repetente + ASE + PLNM, exactly as they appear in the file. NEE is
     * intentionally absent from this list — it is never read, matching the
     * standing architectural exclusion (docs/domain-model.md §11.3).
     *
     * @param  array<string, string|int>  $columns
     */
    protected function buildNote(Worksheet $sheet, array $columns, int $row): ?string
    {
        $parts = [];

        if ($this->nullableString($sheet, $columns, self::HEADER_REPEATER, $row) !== null) {
            $parts[] = 'Repetente';
        }

        if (($ase = $this->nullableString($sheet, $columns, self::HEADER_SOCIAL_SUPPORT, $row)) !== null) {
            $parts[] = "ASE: {$ase}";
        }

        if ($this->nullableString($sheet, $columns, self::HEADER_NON_NATIVE_PORTUGUESE, $row) !== null) {
            $parts[] = 'PLNM';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
```

- [ ] **Step 7: Run the tests to confirm they pass**

Run: `php artisan test --filter=RosterFileParserTest`
Expected: all 5 PASS.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock app/Domain/Import/RosterRow.php app/Services/Import/RosterFileParseException.php app/Services/Import/RosterFileParser.php tests/Unit/Import/RosterFileParserTest.php
git commit -m "feat(import): parse the Intuitivo roster Excel export"
```

---

### Task 4: Photo Word-file parser

**Files:**
- Create: `app/Domain/Import/PhotoMatch.php`
- Create: `app/Services/Import/PhotoFileParser.php`
- Create: `tests/Support/DocxFixtureBuilder.php`
- Test: `tests/Unit/Import/PhotoFileParserTest.php`

**Interfaces:**
- Produces: `PhotoMatch` (readonly: `name: string`, `imageBytes: string`, `extension: string`) and `PhotoFileParser::parse(string $path): array` returning `list<PhotoMatch>`. Task 5 consumes this exact signature.
- `tests/Support/DocxFixtureBuilder` produces a temp `.docx`-shaped zip file path given a list of `['name' => string, 'imageBytes' => string]` pairs — used by both this task's unit test and Task 8's feature test.

- [ ] **Step 1: Write the DTO**

```php
<?php
// app/Domain/Import/PhotoMatch.php

namespace App\Domain\Import;

/**
 * One (name, photo) pair extracted from the Word photo sheet, before matching
 * against the roster. The name is the raw caption text — matching against a
 * RosterRow happens in RosterImportPreviewBuilder, not here.
 */
final readonly class PhotoMatch
{
    public function __construct(
        public string $name,
        public string $imageBytes,
        public string $extension,
    ) {}
}
```

- [ ] **Step 2: Write the fixture builder**

```php
<?php
// tests/Support/DocxFixtureBuilder.php

namespace Tests\Support;

/**
 * Builds a minimal fixture reproducing the exact structure the real
 * "Intuitivo" photo export uses: each photo is a VML image inside a table
 * cell (`<v:imagedata r:pict="...">`), and its caption is a separate HTML
 * chunk embedded via `<w:altChunk r:id="...">` — not a normal picture-with-
 * caption. PhotoFileParser is written against this exact shape; a generic
 * python-docx/PHPWord-generated file would NOT reproduce it.
 */
class DocxFixtureBuilder
{
    /**
     * @param  list<array{name: string, imageBytes: string}>  $entries
     */
    public static function build(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_fixture_').'.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $relationships = '';
        $tableRows = '';

        foreach ($entries as $index => $entry) {
            $n = $index + 1;
            $imageRid = "rImg{$n}";
            $chunkRid = "rChunk{$n}";

            $zip->addFromString("media/image{$n}.jpg", $entry['imageBytes']);
            $zip->addFromString("word/afchunk{$n}.htm", self::captionHtml($entry['name']));

            $relationships .= '<Relationship Id="'.$imageRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="/media/image'.$n.'.jpg"/>';
            $relationships .= '<Relationship Id="'.$chunkRid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" Target="/word/afchunk'.$n.'.htm"/>';

            $tableRows .= '<w:tr><w:tc><w:p><w:r><w:pict><v:shape><v:imagedata r:pict="'.$imageRid.'"/></v:shape></w:pict></w:r></w:p></w:tc>';
            $tableRows .= '<w:tc><w:p><w:r><w:altChunk r:id="'.$chunkRid.'"/></w:r></w:p></w:tc></w:tr>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:v="urn:schemas-microsoft-com:vml" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:tbl>'.$tableRows.'</w:tbl></w:body></w:document>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Default Extension="htm" ContentType="text/html"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
        $zip->close();

        return $path;
    }

    protected static function captionHtml(string $name): string
    {
        return '<html><head><meta charset="utf-8"/></head><body><div>'.htmlspecialchars($name).' </div></body></html>';
    }

    /**
     * A tiny valid 1x1 JPEG, generated at call time — never a real photo.
     */
    public static function tinyJpeg(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return (string) $bytes;
    }
}
```

- [ ] **Step 3: Write the failing unit test**

```php
<?php
// tests/Unit/Import/PhotoFileParserTest.php

namespace Tests\Unit\Import;

use App\Services\Import\PhotoFileParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\TestCase;

class PhotoFileParserTest extends TestCase
{
    protected string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_extracts_each_name_and_photo_pair_in_document_order(): void
    {
        $jpeg = DocxFixtureBuilder::tinyJpeg();
        $this->tempPath = DocxFixtureBuilder::build([
            ['name' => 'Maria Teste', 'imageBytes' => $jpeg],
            ['name' => 'João Exemplo', 'imageBytes' => $jpeg],
        ]);

        $matches = (new PhotoFileParser())->parse($this->tempPath);

        $this->assertCount(2, $matches);
        $this->assertSame('Maria Teste', $matches[0]->name);
        $this->assertSame($jpeg, $matches[0]->imageBytes);
        $this->assertSame('jpg', $matches[0]->extension);
        $this->assertSame('João Exemplo', $matches[1]->name);
    }

    #[Test]
    public function it_strips_html_from_the_caption(): void
    {
        $jpeg = DocxFixtureBuilder::tinyJpeg();
        $this->tempPath = DocxFixtureBuilder::build([
            ['name' => 'Ana Simão', 'imageBytes' => $jpeg],
        ]);

        $matches = (new PhotoFileParser())->parse($this->tempPath);

        $this->assertSame('Ana Simão', $matches[0]->name);
    }

    #[Test]
    public function an_empty_document_yields_no_matches(): void
    {
        $this->tempPath = DocxFixtureBuilder::build([]);

        $matches = (new PhotoFileParser())->parse($this->tempPath);

        $this->assertSame([], $matches);
    }
}
```

- [ ] **Step 4: Run the tests to confirm they fail**

Run: `php artisan test --filter=PhotoFileParserTest`
Expected: FAIL — `PhotoFileParser` does not exist yet.

- [ ] **Step 5: Write the parser**

```php
<?php
// app/Services/Import/PhotoFileParser.php

namespace App\Services\Import;

use App\Domain\Import\PhotoMatch;

/**
 * Reads the "Intuitivo" photo export. Despite its .doc extension, the file is
 * a zip (OOXML): each photo is a VML image (<v:imagedata r:pict="...">), and
 * its caption is a separate HTML chunk embedded via <w:altChunk r:id="...">
 * ("Alternative Format Import Part") — not a picture-with-caption pair a
 * generic document reader would recognize.
 *
 * Pairing is done by XML DOCUMENT ORDER (a real structural guarantee, unlike
 * zip-entry byte order): every <v:imagedata> and <w:altChunk> node is walked
 * in the order they appear in document.xml, and each image is paired with
 * the next altChunk that follows it.
 */
class PhotoFileParser
{
    protected const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected const NS_V = 'urn:schemas-microsoft-com:vml';

    protected const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    protected const NS_PACKAGE_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return list<PhotoMatch>
     */
    public function parse(string $path): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RosterFileParseException('Não foi possível abrir o ficheiro de fotos.');
        }

        $relationships = $this->readRelationships($zip);
        $documentXml = $zip->getFromName('word/document.xml');

        if ($documentXml === false) {
            $zip->close();

            return [];
        }

        $matches = $this->extractMatches($documentXml, $relationships, $zip);
        $zip->close();

        return $matches;
    }

    /**
     * @return array<string, string> relationship id => target path (without leading slash)
     */
    protected function readRelationships(\ZipArchive $zip): array
    {
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');

        if ($relsXml === false) {
            return [];
        }

        $dom = new \DOMDocument();
        $dom->loadXML($relsXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rel', self::NS_PACKAGE_RELS);

        $map = [];

        foreach ($xpath->query('//rel:Relationship') as $node) {
            /** @var \DOMElement $node */
            $id = $node->getAttribute('Id');
            $target = ltrim($node->getAttribute('Target'), '/');
            $map[$id] = $target;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $relationships
     * @return list<PhotoMatch>
     */
    protected function extractMatches(string $documentXml, array $relationships, \ZipArchive $zip): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($documentXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('v', self::NS_V);
        $xpath->registerNamespace('r', self::NS_R);

        // Both node kinds, in one query, preserve document order.
        $nodes = $xpath->query('//v:imagedata | //w:altChunk');

        $matches = [];
        $pendingImageTarget = null;

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            if ($node->localName === 'imagedata') {
                $rid = $node->getAttributeNS(self::NS_R, 'pict');
                $pendingImageTarget = $relationships[$rid] ?? null;

                continue;
            }

            // altChunk
            if ($pendingImageTarget === null) {
                continue; // A caption with no preceding image — nothing to pair.
            }

            $rid = $node->getAttributeNS(self::NS_R, 'id');
            $chunkTarget = $relationships[$rid] ?? null;

            if ($chunkTarget === null) {
                $pendingImageTarget = null;

                continue;
            }

            $imageBytes = $zip->getFromName($pendingImageTarget);
            $captionHtml = $zip->getFromName($chunkTarget);

            if ($imageBytes !== false && $captionHtml !== false) {
                $matches[] = new PhotoMatch(
                    name: $this->extractName($captionHtml),
                    imageBytes: $imageBytes,
                    extension: strtolower(pathinfo($pendingImageTarget, PATHINFO_EXTENSION)) ?: 'jpg',
                );
            }

            $pendingImageTarget = null;
        }

        return $matches;
    }

    protected function extractName(string $captionHtml): string
    {
        $text = strip_tags($captionHtml);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Drop a trailing status marker like "(MT)" — the situation already
        // comes from the roster's own SIT. column; only the plain name matters
        // for matching here.
        $text = preg_replace('/\s*\([^)]*\)\s*$/u', '', $text);

        return trim($text);
    }
}
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --filter=PhotoFileParserTest`
Expected: all 3 PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Import/PhotoMatch.php app/Services/Import/PhotoFileParser.php tests/Support/DocxFixtureBuilder.php tests/Unit/Import/PhotoFileParserTest.php
git commit -m "feat(import): parse the Intuitivo photo Word export"
```

---

### Task 5: Merge roster rows and photo matches into preview rows

**Files:**
- Create: `app/Services/Import/RosterImportPreviewBuilder.php`
- Test: `tests/Unit/Import/RosterImportPreviewBuilderTest.php`

**Interfaces:**
- Consumes: `list<RosterRow>` (Task 3), `list<PhotoMatch>` (Task 4).
- Produces: `RosterImportPreviewBuilder::build(array $rosterRows, array $photoMatches, \Closure $isAlreadyEnrolled): array` returning a `list<array{name: string, class_number: ?int, birth_date: ?string, situation_code: string, situation_recognized: bool, process_number: ?string, note: ?string, photo_index: ?int, duplicate_in_file: bool, already_enrolled: bool, include: bool}>`. `photo_index` is the position of the matched entry in the ORIGINAL `$photoMatches` array the caller passed in — Task 8 uses that index to know which temp-stored image file belongs to each row. `$isAlreadyEnrolled` is a callback `fn(string $name): bool`, injected so this class never touches the database directly (keeps it a plain, fast-testable unit). Task 8 consumes this exact return shape to build the Inertia preview page's `rows` prop.

- [ ] **Step 1: Write the failing unit test**

```php
<?php
// tests/Unit/Import/RosterImportPreviewBuilderTest.php

namespace Tests\Unit\Import;

use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterRow;
use App\Services\Import\RosterImportPreviewBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterImportPreviewBuilderTest extends TestCase
{
    #[Test]
    public function it_matches_a_photo_to_its_roster_row_by_name(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, '2013-05-04', 'X', '1001', null)];
        $photos = [new PhotoMatch('Maria Teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder())->build($rows, $photos, fn () => false);

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertSame('jpg', $preview[0]['photo_extension']);
    }

    #[Test]
    public function a_row_with_no_matching_photo_gets_a_null_photo_index(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder())->build($rows, [], fn () => false);

        $this->assertNull($preview[0]['photo_index']);
        $this->assertNull($preview[0]['photo_extension']);
    }

    #[Test]
    public function name_matching_ignores_case_and_extra_spacing(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('maria teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder())->build($rows, $photos, fn () => false);

        $this->assertSame(0, $preview[0]['photo_index']);
    }

    #[Test]
    public function duplicate_names_within_the_file_are_flagged_and_excluded_by_default(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('Maria Teste', 2, null, 'X', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder())->build($rows, [], fn () => false);

        $this->assertTrue($preview[0]['duplicate_in_file']);
        $this->assertTrue($preview[1]['duplicate_in_file']);
        $this->assertFalse($preview[0]['include']);
        $this->assertFalse($preview[1]['include']);
    }

    #[Test]
    public function a_name_already_enrolled_in_the_class_is_flagged_and_excluded_by_default(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder())->build($rows, [], fn (string $name) => $name === 'Maria Teste');

        $this->assertTrue($preview[0]['already_enrolled']);
        $this->assertFalse($preview[0]['include']);
    }

    #[Test]
    public function an_unrecognized_situation_code_is_flagged_but_still_included(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'MT', null, null)];

        $preview = (new RosterImportPreviewBuilder())->build($rows, [], fn () => false);

        $this->assertFalse($preview[0]['situation_recognized']);
        $this->assertTrue($preview[0]['include']);
    }

    #[Test]
    public function a_recognized_situation_code_is_flagged_as_such(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('João Exemplo', 2, null, 'TR', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder())->build($rows, [], fn () => false);

        $this->assertTrue($preview[0]['situation_recognized']);
        $this->assertTrue($preview[1]['situation_recognized']);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=RosterImportPreviewBuilderTest`
Expected: FAIL — class does not exist yet.

- [ ] **Step 3: Write the builder**

```php
<?php
// app/Services/Import/RosterImportPreviewBuilder.php

namespace App\Services\Import;

use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterRow;
use Illuminate\Support\Str;

/**
 * Merges parsed roster rows with parsed photo matches into plain arrays ready
 * to hand to the Inertia preview page. Never touches Eloquent or the
 * database directly — $isAlreadyEnrolled is injected so this stays a fast,
 * pure unit.
 */
class RosterImportPreviewBuilder
{
    protected const RECOGNIZED_SITUATIONS = ['X', 'TR'];

    /**
     * @param  list<RosterRow>  $rosterRows
     * @param  list<PhotoMatch>  $photoMatches
     * @param  \Closure(string): bool  $isAlreadyEnrolled
     * @return list<array{name: string, class_number: ?int, birth_date: ?string, situation_code: string, situation_recognized: bool, process_number: ?string, note: ?string, photo_index: ?int, photo_extension: ?string, duplicate_in_file: bool, already_enrolled: bool, include: bool}>
     */
    public function build(array $rosterRows, array $photoMatches, \Closure $isAlreadyEnrolled): array
    {
        $nameCounts = [];

        foreach ($rosterRows as $row) {
            $key = $this->normalize($row->name);
            $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
        }

        $preview = [];

        foreach ($rosterRows as $row) {
            $key = $this->normalize($row->name);

            $duplicateInFile = $nameCounts[$key] > 1;
            $alreadyEnrolled = $isAlreadyEnrolled($row->name);
            $photoIndex = $this->findPhotoIndex($row->name, $photoMatches);

            $preview[] = [
                'name' => $row->name,
                'class_number' => $row->classNumber,
                'birth_date' => $row->birthDate,
                'situation_code' => $row->situationCode,
                'situation_recognized' => in_array($row->situationCode, self::RECOGNIZED_SITUATIONS, true),
                'process_number' => $row->processNumber,
                'note' => $row->note,
                'photo_index' => $photoIndex,
                'photo_extension' => $photoIndex !== null ? $photoMatches[$photoIndex]->extension : null,
                'duplicate_in_file' => $duplicateInFile,
                'already_enrolled' => $alreadyEnrolled,
                'include' => ! $duplicateInFile && ! $alreadyEnrolled,
            ];
        }

        return $preview;
    }

    /**
     * @param  list<PhotoMatch>  $photoMatches
     */
    protected function findPhotoIndex(string $name, array $photoMatches): ?int
    {
        $target = $this->normalize($name);

        foreach ($photoMatches as $index => $photo) {
            if ($this->normalize($photo->name) === $target) {
                return $index;
            }
        }

        return null;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }
}
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test --filter=RosterImportPreviewBuilderTest`
Expected: all 7 PASS.

- [ ] **Step 5: Run Larastan**

Run: `composer types:check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Import/RosterImportPreviewBuilder.php tests/Unit/Import/RosterImportPreviewBuilderTest.php
git commit -m "feat(import): merge roster rows and photo matches into preview rows"
```

---

### Task 6: Temp storage helper for uploaded photos

**Files:**
- Create: `app/Support/Import/RosterImportTempStorage.php`
- Test: folded into Task 8's feature test (this class has no branching logic worth unit-testing in isolation beyond what a real temp-disk round trip already proves).

**Interfaces:**
- Produces: `RosterImportTempStorage::newToken(): string`, `::storePhoto(string $token, int $index, string $bytes, string $extension): string` (returns the relative path), `::readPhoto(string $relativePath): ?string` (raw bytes or null), `::path(string $token): string`, `::delete(string $token): void`. Task 8 and Task 9 both consume this exact API.

- [ ] **Step 1: Write the helper**

```php
<?php
// app/Support/Import/RosterImportTempStorage.php

namespace App\Support\Import;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Short-lived, per-import scratch space for uploaded photos, on the private
 * `local` disk (never `public` — never web-reachable directly). No database
 * row backs this: the token is just a folder name. Deleted in full once the
 * import is confirmed or abandoned (docs/superpowers/specs/2026-07-28-*:
 * "o ficheiro carregado não é retido indefinidamente").
 */
class RosterImportTempStorage
{
    protected const ROOT = 'roster-imports';

    public function newToken(): string
    {
        return (string) Str::uuid();
    }

    public function storePhoto(string $token, int $index, string $bytes, string $extension): string
    {
        $relativePath = self::ROOT."/{$token}/{$index}.{$extension}";
        Storage::disk('local')->put($relativePath, $bytes);

        return $relativePath;
    }

    public function readPhoto(string $relativePath): ?string
    {
        $contents = Storage::disk('local')->get($relativePath);

        return $contents === null ? null : $contents;
    }

    public function path(string $token): string
    {
        return self::ROOT."/{$token}";
    }

    public function delete(string $token): void
    {
        Storage::disk('local')->deleteDirectory($this->path($token));
    }
}
```

- [ ] **Step 2: Confirm it works with a quick tinker round trip**

Run: `php artisan tinker --execute="$s = new App\Support\Import\RosterImportTempStorage(); $t = $s->newToken(); $p = $s->storePhoto($t, 0, 'hello', 'jpg'); echo $s->readPhoto($p); $s->delete($t); echo Storage::disk('local')->exists($p) ? 'still there' : 'gone';"`
Expected: `hellogone`

- [ ] **Step 3: Commit**

```bash
git add app/Support/Import/RosterImportTempStorage.php
git commit -m "feat(import): temp storage helper for uploaded roster photos"
```

---

### Task 7: Authenticated photo streaming (permanent storage)

**Files:**
- Create: `app/Policies/StudentPolicy.php`
- Create: `app/Http/Controllers/StudentPhotoController.php`
- Modify: `app/Http/Controllers/ClassController.php`
- Modify: `resources/js/pages/classes/Show.vue` (type only — see Task 10 for the rest of that file's changes)
- Modify: `routes/web.php`
- Test: `tests/Feature/Classes/RosterImportTest.php` (created here, extended by Tasks 8–9)

**Interfaces:**
- Produces: `GET /students/{student}/photo` (name: `students.photo`), streaming from `student_identities.photo_path` on the `local` disk. `ClassController::show()`'s `students` prop gains `photo_url: string|null`.

- [ ] **Step 1: Write the failing feature test**

```php
<?php
// tests/Feature/Classes/RosterImportTest.php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    /**
     * @return array{year: int, subject: int}
     */
    protected function context(): array
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);
    }

    protected function createClass(): SchoolClass
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    #[Test]
    public function a_students_photo_streams_for_the_teacher_who_teaches_them(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        Storage::disk('local')->put('student-photos/'.$student->ulid.'.jpg', 'fake-bytes');
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/'.$student->ulid.'.jpg']);

        $response = $this->actingAs($this->user)->get("/students/{$student->ulid}/photo");

        $response->assertOk();
        $this->assertSame('fake-bytes', $response->streamedContent());
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_student_cannot_see_the_photo(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/x.jpg']);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }

    #[Test]
    public function a_student_with_no_photo_returns_not_found(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=RosterImportTest`
Expected: FAIL — route `students.photo` does not exist (404 on all three, including the "should be OK" one).

- [ ] **Step 3: Write `StudentPolicy`**

```php
<?php
// app/Policies/StudentPolicy.php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * A student has no single "owning" class — they may be enrolled in several.
 * Viewing their photo is authorized if the teacher teaches at least one class
 * this student is currently enrolled in.
 */
class StudentPolicy
{
    public function viewPhoto(User $user, Student $student): bool
    {
        return $student->enrollments()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }
}
```

- [ ] **Step 4: Write `StudentPhotoController`**

```php
<?php
// app/Http/Controllers/StudentPhotoController.php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class StudentPhotoController extends Controller
{
    public function show(Student $student): Response
    {
        Gate::authorize('viewPhoto', $student);

        $path = $student->identity->photo_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the existing `Route::middleware('module:classes')->group(function () { ... })` block (the same one holding `classes.students.store`/`classes.students.destroy`), add:

```php
        Route::get('students/{student}/photo', [StudentPhotoController::class, 'show'])->name('students.photo');
```

Add `use App\Http\Controllers\StudentPhotoController;` to the top of `routes/web.php`, alphabetically among the existing controller imports.

- [ ] **Step 6: Add `photo_url` to `ClassController::show()`'s students mapping**

In `app/Http/Controllers/ClassController.php`, change the `students` mapping inside `show()`:

```php
            'students' => $class->enrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    'name' => $enrollment->student->identity->display_name,
                    'pseudonym' => $enrollment->student->pseudonym_code,
                    'class_number' => $enrollment->class_number,
                    'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                    'is_late_entry' => $enrollment->is_late_entry,
                    'status_label' => $enrollment->status->label(),
                    'photo_url' => $enrollment->student->identity->photo_path !== null
                        ? route('students.photo', $enrollment->student->ulid)
                        : null,
                ]),
```

- [ ] **Step 7: Run the tests to confirm they pass**

Run: `php artisan test --filter=RosterImportTest`
Expected: all 3 PASS.

- [ ] **Step 8: Run the full suite to confirm nothing regressed**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Policies/StudentPolicy.php app/Http/Controllers/StudentPhotoController.php app/Http/Controllers/ClassController.php routes/web.php tests/Feature/Classes/RosterImportTest.php
git commit -m "feat(students): authenticated photo streaming route"
```

---

### Task 8: Upload and preview — `RosterImportController::store()`

**Files:**
- Create: `app/Http/Controllers/RosterImportController.php` (this task: `store()` and `previewPhoto()`; Task 9 adds `confirm()` to the same file)
- Modify: `routes/web.php`
- Modify: `tests/Feature/Classes/RosterImportTest.php`

**Interfaces:**
- Produces: `POST /classes/{class}/roster-imports` (name: `classes.roster-imports.store`) → renders Inertia page `roster-imports/Preview` with props `{ schoolClassUlid: string, token: string, rows: <the array shape from Task 5> }`. `GET /classes/{class}/roster-imports/{token}/photos/{index}` (name: `classes.roster-imports.preview-photo`) → streams a temp photo by its `photo_index` for the preview page only.

- [ ] **Step 1: Write the failing feature test — upload without photos**

Add to `tests/Feature/Classes/RosterImportTest.php`:

```php
    #[Test]
    public function uploading_only_the_roster_file_shows_a_preview_with_no_photos(): void
    {
        $class = $this->createClass();
        $excel = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new \Tests\Support\RosterFixture())->build()),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => $excel,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Maria Teste')
            ->where('rows.0.photo_index', null),
        );
    }

    #[Test]
    public function uploading_a_roster_and_a_photo_file_matches_them_in_the_preview(): void
    {
        $class = $this->createClass();
        $excel = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new \Tests\Support\RosterFixture())->build()),
        );
        $photos = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(\Tests\Support\DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => \Tests\Support\DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => $excel,
            'photos' => $photos,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Maria Teste')
            ->where('rows.0.photo_index', 0),
        );
    }

    #[Test]
    public function a_pdf_upload_is_rejected_with_a_clear_error(): void
    {
        $class = $this->createClass();
        $pdf = \Illuminate\Http\UploadedFile::fake()->create('roster.pdf', 10, 'application/pdf');

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports", ['roster' => $pdf])
            ->assertSessionHasErrors('roster');
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_import(): void
    {
        $class = $this->createClass();
        $stranger = User::factory()->create();

        $excel = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new \Tests\Support\RosterFixture())->build()),
        );

        $this->actingAs($stranger)
            ->post("/classes/{$class->ulid}/roster-imports", ['roster' => $excel])
            ->assertNotFound();
    }
```

- [ ] **Step 2: Extract the roster-fixture builder used above into a reusable test support class**

`RosterFileParserTest::buildFixture()` (Task 3) is now needed from two test files. Create `tests/Support/RosterFixture.php`:

```php
<?php
// tests/Support/RosterFixture.php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A two-student roster fixture matching the real Intuitivo export shape: a
 * report header above the data, and a header row the parser must locate by
 * content. Fictional data only.
 */
class RosterFixture
{
    public function build(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A4', 'Agrupamento de Escolas Exemplo');
        $sheet->setCellValue('A14', 'N.º MATR.');
        $sheet->setCellValue('C14', 'NOME');
        $sheet->setCellValue('I14', 'IDADE');
        $sheet->setCellValue('K14', 'DATA NASC.');
        $sheet->setCellValue('M14', 'SIT.');
        $sheet->setCellValue('N14', 'REPET.');
        $sheet->setCellValue('P14', 'ASE');
        $sheet->setCellValue('Q14', 'NEE');
        $sheet->setCellValue('S14', 'EMR');
        $sheet->setCellValue('T14', 'PLNM');
        $sheet->setCellValue('U14', 'N.º PROC.');

        $sheet->setCellValue('A15', 1);
        $sheet->setCellValue('C15', 'Maria Teste');
        $sheet->setCellValueExplicit('K15', ExcelDate::PHPToExcel(new \DateTime('2013-05-04')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M15', 'X');
        $sheet->setCellValue('U15', '1001');

        $sheet->setCellValue('A16', 2);
        $sheet->setCellValue('C16', 'João Exemplo');
        $sheet->setCellValueExplicit('K16', ExcelDate::PHPToExcel(new \DateTime('2012-11-20')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M16', 'TR');
        $sheet->setCellValue('U16', '1002');

        $sheet->setCellValue('C19', 'Total Alunos - 2');

        $path = tempnam(sys_get_temp_dir(), 'roster_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
```

Now update `tests/Unit/Import/RosterFileParserTest.php`'s `buildFixture()` method to just delegate: replace its body with `return (new \Tests\Support\RosterFixture())->build();` — keeps one source of truth for the fixture shape.

- [ ] **Step 3: Run the new tests to confirm they fail**

Run: `php artisan test --filter=RosterImportTest`
Expected: FAIL — route `classes.roster-imports.store` does not exist.

- [ ] **Step 4: Write `RosterImportController` (store + previewPhoto)**

```php
<?php
// app/Http/Controllers/RosterImportController.php

namespace App\Http\Controllers;

use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use App\Services\Import\RosterImportPreviewBuilder;
use App\Support\Import\RosterImportTempStorage;
use App\Models\SchoolClass;
use App\Models\StudentIdentity;
use App\Support\Privacy\BlindIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class RosterImportController extends Controller
{
    public function __construct(
        protected RosterFileParser $rosterParser,
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected RosterImportTempStorage $tempStorage,
    ) {}

    public function store(Request $request, SchoolClass $class): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'roster' => ['required', 'file', 'mimes:xls,xlsx'],
            'photos' => ['nullable', 'file', 'mimes:doc,docx'],
        ]);

        try {
            $rosterRows = $this->rosterParser->parse($data['roster']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['roster' => $exception->getMessage()]);
        }

        if ($rosterRows === []) {
            return back()->withErrors(['roster' => 'Não foi possível encontrar nenhum aluno neste ficheiro.']);
        }

        $photoMatches = isset($data['photos'])
            ? $this->photoParser->parse($data['photos']->getRealPath())
            : [];

        $token = $this->tempStorage->newToken();

        foreach ($photoMatches as $index => $photo) {
            $this->tempStorage->storePhoto($token, $index, $photo->imageBytes, $photo->extension);
        }

        $isAlreadyEnrolled = function (string $name) use ($class): bool {
            $index = BlindIndex::of($name);

            return StudentIdentity::where('display_name_index', $index)
                ->whereHas('student.enrollments', fn ($query) => $query->where('class_id', $class->id))
                ->exists();
        };

        $rows = $this->previewBuilder->build($rosterRows, $photoMatches, $isAlreadyEnrolled);

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
        ]);
    }

    public function previewPhoto(SchoolClass $class, string $token, int $index): Response
    {
        Gate::authorize('update', $class);

        $extension = $this->guessExtension($token, $index);

        abort_if($extension === null, 404);

        $bytes = $this->tempStorage->readPhoto($this->tempStorage->path($token)."/{$index}.{$extension}");

        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => "image/{$extension}"]);
    }

    protected function guessExtension(string $token, int $index): ?string
    {
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            if (Storage::disk('local')->exists($this->tempStorage->path($token)."/{$index}.{$extension}")) {
                return $extension;
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the `module:classes` group, next to `classes.students.store`/`classes.students.destroy`:

```php
        Route::post('classes/{class}/roster-imports', [RosterImportController::class, 'store'])->name('classes.roster-imports.store');
        Route::get('classes/{class}/roster-imports/{token}/photos/{index}', [RosterImportController::class, 'previewPhoto'])->name('classes.roster-imports.preview-photo');
```

Add `use App\Http\Controllers\RosterImportController;` to the top imports, alphabetically.

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --filter=RosterImportTest`
Expected: all PASS (the earlier `StudentPhotoController` tests from Task 7 plus the four new ones here).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/RosterImportController.php routes/web.php tests/Feature/Classes/RosterImportTest.php tests/Support/RosterFixture.php tests/Unit/Import/RosterFileParserTest.php
git commit -m "feat(import): upload roster + optional photos, render a matched preview"
```

---

### Task 9: Confirm — create the enrollments

**Files:**
- Modify: `app/Http/Controllers/RosterImportController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Classes/RosterImportTest.php`

**Interfaces:**
- Consumes: `StudentEnrollmentService::enrollNew()` (Task 2), `RosterImportTempStorage` (Task 6).
- Produces: `POST /classes/{class}/roster-imports/{token}/confirm` (name: `classes.roster-imports.confirm`), redirecting to `classes.show` on success.

- [ ] **Step 1: Write the failing feature test**

Add to `tests/Feature/Classes/RosterImportTest.php`:

```php
    #[Test]
    public function confirming_creates_an_enrollment_per_included_row_with_its_photo(): void
    {
        $class = $this->createClass();

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/some-token/confirm", [
            'rows' => [
                [
                    'name' => 'Maria Teste',
                    'class_number' => 1,
                    'birth_date' => '2013-05-04',
                    'situation_code' => 'X',
                    'note' => 'ASE: B',
                    'process_number' => '1001',
                    'photo_temp_path' => null,
                    'include' => true,
                ],
                [
                    'name' => 'Excluído Este',
                    'class_number' => 2,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'process_number' => null,
                    'photo_temp_path' => null,
                    'include' => false,
                ],
            ],
        ]);

        $response->assertRedirect("/classes/{$class->ulid}");
        $this->assertSame(1, $class->enrollments()->count());

        $identity = \App\Models\StudentIdentity::firstOrFail();
        $this->assertSame('Maria Teste', $identity->display_name);
        $this->assertSame('2013-05-04', $identity->birth_date->toDateString());
        $this->assertSame('1001', $identity->school_number);

        $enrollment = $class->enrollments()->firstOrFail();
        $this->assertSame('ASE: B', $enrollment->import_note);
        $this->assertSame(1, $enrollment->class_number);
    }

    #[Test]
    public function confirming_moves_a_matched_photo_from_temp_to_permanent_storage_and_cleans_up(): void
    {
        $class = $this->createClass();
        $storage = app(\App\Support\Import\RosterImportTempStorage::class);
        $token = $storage->newToken();
        $tempPath = $storage->storePhoto($token, 0, 'fake-photo-bytes', 'jpg');

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_temp_path' => $tempPath,
                'include' => true,
            ]],
        ]);

        $identity = \App\Models\StudentIdentity::firstOrFail();
        $this->assertNotNull($identity->photo_path);
        Storage::disk('local')->assertExists($identity->photo_path);
        $this->assertSame('fake-photo-bytes', Storage::disk('local')->get($identity->photo_path));

        // The whole temp token folder is gone, not just the one file.
        Storage::disk('local')->assertMissing($tempPath);
        Storage::disk('local')->assertDirectoryEmpty("roster-imports/{$token}");
    }

    #[Test]
    public function an_unrecognized_situation_code_still_enrolls_as_active(): void
    {
        $class = $this->createClass();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/some-token/confirm", [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'MT',
                'note' => null,
                'photo_temp_path' => null,
                'include' => true,
            ]],
        ]);

        $enrollment = $class->enrollments()->firstOrFail();
        $this->assertSame(\App\Models\EnrollmentStatus::Active, $enrollment->status);
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=RosterImportTest`
Expected: FAIL — route `classes.roster-imports.confirm` does not exist.

- [ ] **Step 3: Add `confirm()` to `RosterImportController`**

Add to the top of `app/Http/Controllers/RosterImportController.php` (alongside the existing `use` statements):

```php
use App\Models\EnrollmentStatus;
use App\Services\StudentEnrollmentService;
```

Add `protected StudentEnrollmentService $enrollmentService` to the constructor's promoted properties (after `protected RosterImportTempStorage $tempStorage`), then add this method to the class:

```php
    public function confirm(Request $request, SchoolClass $class, string $token): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.birth_date' => ['nullable', 'date'],
            'rows.*.situation_code' => ['required', 'string'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.process_number' => ['nullable', 'string', 'max:64'],
            'rows.*.photo_temp_path' => ['nullable', 'string'],
            'rows.*.include' => ['required', 'boolean'],
        ]);

        $created = 0;

        foreach ($data['rows'] as $row) {
            if (! $row['include']) {
                continue;
            }

            $photoPath = null;

            if (! empty($row['photo_temp_path'])) {
                $photoPath = $this->movePhotoToPermanentStorage($row['photo_temp_path']);
            }

            $this->enrollmentService->enrollNew($class, [
                'name' => $row['name'],
                'class_number' => $row['class_number'] ?? null,
                'birth_date' => $row['birth_date'] ?? null,
                'import_note' => $row['note'] ?? null,
                'school_number' => $row['process_number'] ?? null,
                'photo_path' => $photoPath,
                'status' => $this->mapSituation($row['situation_code']),
            ]);

            $created++;
        }

        $this->tempStorage->delete($token);

        return to_route('classes.show', $class->ulid)
            ->with('status', "{$created} aluno(s) inscrito(s).");
    }

    protected function movePhotoToPermanentStorage(string $tempRelativePath): ?string
    {
        $bytes = $this->tempStorage->readPhoto($tempRelativePath);

        if ($bytes === null) {
            return null;
        }

        $extension = pathinfo($tempRelativePath, PATHINFO_EXTENSION) ?: 'jpg';
        $permanentPath = 'student-photos/'.\Illuminate\Support\Str::uuid().'.'.$extension;
        Storage::disk('local')->put($permanentPath, $bytes);

        return $permanentPath;
    }

    protected function mapSituation(string $code): string
    {
        return match ($code) {
            'TR' => 'transferred_out',
            default => 'active',
        };
    }
```

- [ ] **Step 4: Let `StudentEnrollmentService::enrollNew()` accept an explicit `status`**

The service currently hardcodes `'status' => 'active'` (Task 2's version). Update `app/Services/StudentEnrollmentService.php`'s `enrollNew()`: change the enrollment-creation array's `'status' => 'active',` line to `'status' => $data['status'] ?? 'active',`, and widen the method's docblock param type to add `status?: string`. The manual "Adicionar aluno" flow never sends `status`, so it keeps defaulting to `'active'` exactly as before.

- [ ] **Step 5: Add the confirm route**

In `routes/web.php`, right after the `classes.roster-imports.store` line added in Task 8:

```php
        Route::post('classes/{class}/roster-imports/{token}/confirm', [RosterImportController::class, 'confirm'])->name('classes.roster-imports.confirm');
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --filter=RosterImportTest`
Expected: all PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/RosterImportController.php app/Services/StudentEnrollmentService.php routes/web.php tests/Feature/Classes/RosterImportTest.php
git commit -m "feat(import): confirm the preview and create the enrollments"
```

---

### Task 10: Frontend — upload dialog on the class page

**Files:**
- Modify: `resources/js/pages/classes/Show.vue`

**Interfaces:**
- Consumes: `POST /classes/{ulid}/roster-imports` (Task 8) — Inertia's `useForm().post()` follows the returned `Inertia::render()` response directly, no manual redirect handling needed.
- Also displays: `student.photo_url` (Task 7) next to each enrolled student's name.

- [ ] **Step 1: Add the "Importar lista" button, the dialog, and the photo thumbnail — full replacement of the relevant sections**

In `resources/js/pages/classes/Show.vue`, add these imports at the top of the `<script setup>` block, alongside the existing ones:

```ts
import { ref } from 'vue';
import { FileUp } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
```

Update the `Student` type to add `photo_url`:

```ts
type Student = {
    ulid: string;
    name: string;
    pseudonym: string;
    class_number: number | null;
    enrolled_on: string;
    is_late_entry: boolean;
    status_label: string;
    photo_url: string | null;
};
```

Add this state and these functions, right after the existing `remove()` function:

```ts
const importDialogOpen = ref(false);
const wantsPhotos = ref(false);
const importForm = useForm<{ roster: File | null; photos: File | null }>({
    roster: null,
    photos: null,
});

function openImportDialog(): void {
    importForm.reset();
    importForm.clearErrors();
    wantsPhotos.value = false;
    importDialogOpen.value = true;
}

function onRosterFileChange(event: Event): void {
    importForm.roster = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function onPhotosFileChange(event: Event): void {
    importForm.photos = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitImport(): void {
    importForm.post(`/classes/${props.schoolClass.ulid}/roster-imports`, {
        forceFormData: true,
    });
}
```

In the `<template>`, add the button next to the existing "Adicionar aluno" heading — change:

```html
        <section class="space-y-3">
            <h2 class="text-sm font-semibold">Adicionar aluno</h2>
```

to:

```html
        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Adicionar aluno</h2>
                <Button type="button" variant="outline" size="sm" @click="openImportDialog">
                    <FileUp class="size-4" /> Importar lista
                </Button>
            </div>
```

Add the dialog markup right before the closing `</div>` of the outermost page wrapper (after the students table `</section>`, before the final `</div>`):

```html
        <Dialog v-model:open="importDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitImport">
                    <DialogHeader>
                        <DialogTitle>Importar lista de turma</DialogTitle>
                        <DialogDescription>Ficheiro Excel exportado do Intuitivo.</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="roster-file">Ficheiro Excel</Label>
                            <input id="roster-file" type="file" accept=".xls,.xlsx" class="text-sm" @change="onRosterFileChange" />
                            <InputError :message="importForm.errors.roster" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="wants-photos" v-model="wantsPhotos" type="checkbox" />
                            <Label for="wants-photos">Queres associar fotos?</Label>
                        </div>
                        <div v-if="wantsPhotos" class="grid gap-2">
                            <Label for="photos-file">Ficheiro Word (fotos)</Label>
                            <input id="photos-file" type="file" accept=".doc,.docx" class="text-sm" @change="onPhotosFileChange" />
                            <InputError :message="importForm.errors.photos" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="importForm.processing">Continuar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
```

Finally, show the photo thumbnail in the students table — change the name cell:

```html
                        <td class="px-4 py-3 font-medium">
                            {{ student.name }}
                            <Badge v-if="student.is_late_entry" variant="outline" class="ml-1.5">ingresso tardio</Badge>
                        </td>
```

to:

```html
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="student.photo_url"
                                    :src="student.photo_url"
                                    :alt="student.name"
                                    class="size-6 rounded-full object-cover"
                                />
                                <span>{{ student.name }}</span>
                                <Badge v-if="student.is_late_entry" variant="outline">ingresso tardio</Badge>
                            </div>
                        </td>
```

- [ ] **Step 2: Verify TypeScript and lint pass**

Run: `npm run types:check`
Expected: no errors.

Run: `npx eslint resources/js/pages/classes/Show.vue`
Expected: no errors.

- [ ] **Step 3: Verify Prettier formatting**

Run: `npx prettier --check resources/js/pages/classes/Show.vue`

If it reports formatting issues, run `npx prettier --write resources/js/pages/classes/Show.vue` and re-check.

- [ ] **Step 4: Rebuild and manually verify in the browser**

Run: `npm run build`

Then, with the dev/Herd server running, log in as a teacher, open a class, click "Importar lista", and confirm the dialog opens with the file input, the checkbox, and that the Word file input only appears after checking "Queres associar fotos?".

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/classes/Show.vue
git commit -m "feat(classes): add the roster-import upload dialog"
```

---

### Task 11: Frontend — the preview page

**Files:**
- Create: `resources/js/pages/roster-imports/Preview.vue`

**Interfaces:**
- Consumes: the `schoolClassUlid`, `token`, `rows` props from Task 8's `Inertia::render('roster-imports/Preview', ...)`.
- Posts to: `POST /classes/{schoolClassUlid}/roster-imports/{token}/confirm` (Task 9), with a `rows` array matching the shape validated there.

- [ ] **Step 1: Write the page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PreviewRow = {
    name: string;
    class_number: number | null;
    birth_date: string | null;
    situation_code: string;
    situation_recognized: boolean;
    process_number: string | null;
    note: string | null;
    photo_index: number | null;
    photo_extension: string | null;
    duplicate_in_file: boolean;
    already_enrolled: boolean;
    include: boolean;
};

const props = defineProps<{
    schoolClassUlid: string;
    token: string;
    rows: PreviewRow[];
}>();

type FormRow = PreviewRow & { photo_temp_path: string | null };

const form = useForm<{ rows: FormRow[] }>({
    rows: props.rows.map((row) => ({
        ...row,
        photo_temp_path:
            row.photo_index !== null
                ? `roster-imports/${props.token}/${row.photo_index}.${row.photo_extension}`
                : null,
    })),
});

function photoUrl(index: number | null): string | null {
    if (index === null) {
        return null;
    }

    return `/classes/${props.schoolClassUlid}/roster-imports/${props.token}/photos/${index}`;
}

function submit(): void {
    form.post(`/classes/${props.schoolClassUlid}/roster-imports/${props.token}/confirm`);
}
</script>

<template>
    <Head title="Pré-visualização da importação" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            title="Confirmar importação"
            description="Revê cada aluno antes de inscrever. Desmarca uma linha para a excluir."
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2.5 font-medium">Incluir</th>
                            <th class="px-3 py-2.5 font-medium">Foto</th>
                            <th class="px-3 py-2.5 font-medium">Nome</th>
                            <th class="px-3 py-2.5 font-medium">Nº</th>
                            <th class="px-3 py-2.5 font-medium">Data nasc.</th>
                            <th class="px-3 py-2.5 font-medium">Nota</th>
                            <th class="px-3 py-2.5 font-medium">Avisos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(row, index) in form.rows" :key="index">
                            <td class="px-3 py-2.5">
                                <input v-model="row.include" type="checkbox" />
                            </td>
                            <td class="px-3 py-2.5">
                                <img
                                    v-if="photoUrl(row.photo_index)"
                                    :src="photoUrl(row.photo_index)!"
                                    :alt="row.name"
                                    class="size-8 rounded-full object-cover"
                                />
                                <span v-else class="text-xs text-muted-foreground">sem foto</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <Input v-model="row.name" class="h-8" />
                            </td>
                            <td class="px-3 py-2.5">
                                <Input v-model.number="row.class_number" type="number" class="h-8 w-16" />
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ row.birth_date ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ row.note ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <Badge v-if="row.duplicate_in_file" variant="outline">nome duplicado no ficheiro</Badge>
                                <Badge v-if="row.already_enrolled" variant="outline">já inscrito nesta turma</Badge>
                                <Badge v-if="!row.situation_recognized" variant="outline">
                                    situação "{{ row.situation_code }}" não reconhecida — entra como Inscrito
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Confirmar importação</Button>
                <span class="text-sm text-muted-foreground">
                    {{ form.rows.filter((r) => r.include).length }} de {{ form.rows.length }} serão inscritos.
                </span>
            </div>
        </form>
    </div>
</template>
```

- [ ] **Step 2: Verify TypeScript and lint pass**

Run: `npm run types:check`
Expected: no errors.

Run: `npx eslint resources/js/pages/roster-imports/Preview.vue`
Expected: no errors.

- [ ] **Step 3: Verify Prettier formatting**

Run: `npx prettier --check resources/js/pages/roster-imports/Preview.vue`

If needed: `npx prettier --write resources/js/pages/roster-imports/Preview.vue`.

- [ ] **Step 4: Rebuild and manually verify end to end in the browser**

Run: `npm run build`

Then, as a teacher: open a class, click "Importar lista", upload a small real Excel file with 2-3 fictional students (build one by hand in Excel/LibreOffice with the same column headers used in the fixtures — never the real sample files), submit, confirm the preview table shows the right rows, uncheck one row, click "Confirmar importação", and verify the class page now lists only the included students.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/roster-imports/Preview.vue
git commit -m "feat(import): roster-import preview page"
```

---

## Final Verification

- [ ] Run `composer ci:check` (pint, larastan, eslint/prettier/vue-tsc, full PHPUnit suite) and confirm everything is green.
- [ ] Bump `config/app.php`'s `version` and add a `CHANGELOG.md` entry summarizing the feature (per `docs/workflow.md`'s per-commit discipline), then commit.
- [ ] Update `docs/status.md` and the CLAUDE.md/`domain-model.md` "Q6 Intuitivo import" references to say this is now implemented for the Excel/Word roster+photo case (not the separate `intuitivo_grid` grade-import kind mentioned in §10.4/§12, which remains unbuilt and out of scope).
