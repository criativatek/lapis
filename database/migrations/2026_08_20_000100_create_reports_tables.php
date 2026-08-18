<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Relatórios module: a report is an object of the application, not a
 * download (§53).
 *
 * THREE TABLES, AND WHY EACH ONE EXISTS.
 *
 * `reports` is the envelope: what kind of report, about whom, over which
 * stretch of time, in what state, derived from which earlier report. Everything
 * on it is either a scalar somebody filters or sorts by, or a document read
 * whole.
 *
 * `report_sections` is the content WHILE IT IS A DRAFT. Sections are rows
 * rather than a JSON blob because a teacher edits, reorders, excludes and
 * regenerates them one at a time (§44), and each one has to remember two texts
 * at once — what the system wrote and what the teacher made of it — so that
 * «restaurar texto automático» has something to restore.
 *
 * `report_library_entries` is the pedagogical library (§16): difficulties, the
 * strategies that answer them and the objective each strategy serves. Shaped
 * like `scales` — organization_id NULL is a shared system entry, a row with one
 * belongs to a school — because that is the precedent this codebase already
 * has for "mostly reference data, sometimes yours".
 *
 * WHY THE FINALIZED DOCUMENT IS JSON ON `reports` AND NOT MORE ROWS. Once
 * finalized, a report is read whole and never filtered into: it is reprinted,
 * exported and compared, and nothing ever asks "which reports contain the word
 * X in section 4". Same reasoning as `interim_assessments` and
 * `calculation_snapshots`, and the same consequence — `document_version` lets an
 * old document keep the shape it was written in, so a change to what a report
 * records never needs a migration ACROSS HISTORY (§37, §38).
 *
 * ADDITIVE ONLY. Nothing existing is touched: the pauta at `reports.show` and
 * its two evidence-setting flags keep working exactly as they do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // What it is and where it stands. Both are read constantly by the
            // listing, so both are columns rather than document fields.
            $table->string('type', 32);
            $table->string('status', 16)->default('draft');
            $table->string('title', 200);
            $table->string('tone', 24)->default('objective');

            // WHOM IT IS ABOUT. Every one of these is nullable because the four
            // report types answer different questions: a class report has a
            // class, an individual one also has an enrollment, a school one has
            // neither. A CHECK below refuses the combinations that make no
            // sense, so "nullable" never becomes "anything goes".
            $table->foreignId('class_id')->nullable()->constrained('classes')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete();
            // A report built ON a photograph rather than on live data (§30).
            $table->foreignId('interim_assessment_id')->nullable()->constrained()->restrictOnDelete();

            // THE TEMPORAL SCOPE, ALWAYS EXPLICIT (§29). `scope_kind` is what
            // the reader must be told; `scope_label` is how it is said in
            // pt-PT, resolved once when the report is created so that the
            // sentence on page one cannot drift from the data under it.
            $table->string('scope_kind', 24);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('scope_label', 200);

            // Choices made when creating: which sections, which filters, whether
            // students may be named. Read whole, never filtered on.
            $table->json('options')->nullable();

            // §8–§14: what the system cannot know and the teacher states —
            // behaviour, attitude, indicators, planning, validated difficulties
            // and the strategies chosen for them. Versioned for the same reason
            // the snapshot is: its shape will grow.
            $table->json('teacher_input')->nullable();
            $table->unsignedSmallInteger('teacher_input_version')->default(1);

            // §31–§35. nullOnDelete, not restrict: deleting an old draft must
            // not be blocked by a newer report that merely started from it, and
            // the newer one loses a link, not its content.
            $table->foreignId('based_on_report_id')->nullable()->references('id')->on('reports')->nullOnDelete();
            $table->string('template_key', 64)->nullable();

            // §37–§39. Null while a draft; written once at finalization and
            // never rewritten. The hash makes a silent edit detectable rather
            // than merely forbidden, exactly as interim_assessments does.
            $table->json('document')->nullable();
            $table->unsignedSmallInteger('document_version')->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status'], 'reports_org_type_status_idx');
            $table->index(['organization_id', 'class_id'], 'reports_org_class_idx');
            $table->index(['organization_id', 'enrollment_id'], 'reports_org_enrollment_idx');
            $table->index('based_on_report_id');
        });

        Schema::create('report_sections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // cascade: a draft's sections have no meaning without it, and a
            // finalized report keeps its content in `document` regardless.
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();

            // The stable identifier of what this section IS — `domain_results`,
            // `behaviour_attitude`. Headings get reworded; keys do not, so
            // regenerating one section finds the right one.
            $table->string('key', 64);
            $table->string('heading', 200);
            $table->unsignedSmallInteger('position');
            $table->boolean('included')->default(true);

            // TWO TEXTS, DELIBERATELY. `body` is what will be printed;
            // `generated_body` is what the system last wrote. Keeping both is
            // the whole of «restaurar texto automático», and it also lets a
            // reviewer see that a teacher rewrote a sentence rather than
            // accepting it (§44).
            $table->longText('body')->nullable();
            $table->longText('generated_body')->nullable();
            $table->boolean('edited')->default(false);

            // §40: where this section's content came from — `statistics`,
            // `teacher_input`, `records`… Traceability is a property of the
            // content, so it is stored with the content.
            $table->json('sources')->nullable();
            // Tables and figures belonging to this section, already shaped for
            // the renderer. Never recomputed at print time.
            $table->json('data')->nullable();

            $table->timestamps();

            $table->unique(['report_id', 'key']);
            $table->index(['report_id', 'position']);
        });

        Schema::create('report_library_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            // NULL = a shared system entry, as `scales` does. A school's own
            // entries (Pro) and an institution's approved ones (Institucional)
            // carry an organization_id.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            // `difficulty`, `strategy`, `objective` or `phrase`.
            $table->string('kind', 24);
            // Stable across rewordings, and what a strategy points at. Null for
            // free-text entries a teacher typed.
            $table->string('code', 64)->nullable();
            $table->string('label', 200);
            // The goal a strategy serves (§14). Kept as its own column rather
            // than folded into the description, because the relation
            // difficulty → strategy → objective is the point of the table.
            $table->string('objective', 300)->nullable();
            $table->text('body')->nullable();
            // Which difficulty this strategy answers. A code, not a foreign
            // key: a school's own strategy may answer a system difficulty, and
            // an FK across the system/organization split would forbid that.
            $table->string('related_code', 64)->nullable();

            // Optional narrowing. A strategy for Escrita is not a strategy for
            // Cálculo, but most are neither.
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tags')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'kind', 'active'], 'report_library_org_kind_idx');
            $table->index(['kind', 'related_code']);
            $table->unique(['organization_id', 'kind', 'code'], 'report_library_org_kind_code_unique');
        });

        // A FINALIZED REPORT HAS A DOCUMENT; A DRAFT DOES NOT. Stated in the
        // schema so that no code path can produce a report that claims to be
        // finalized while holding nothing to reprint (§37).
        $this->addCheck(
            'reports',
            'reports_finalized_has_document_check',
            "(status <> 'finalized') OR (document IS NOT NULL AND document_hash IS NOT NULL AND finalized_at IS NOT NULL)",
        );

        // The scope each type needs. Without this, "nullable everywhere" would
        // let a class report exist with no class.
        $this->addCheck(
            'reports',
            'reports_scope_matches_type_check',
            "(type = 'class' AND class_id IS NOT NULL)"
            ." OR (type = 'student' AND class_id IS NOT NULL AND enrollment_id IS NOT NULL)"
            ." OR (type = 'records' AND academic_year_id IS NOT NULL)"
            ." OR (type = 'school' AND academic_year_id IS NOT NULL)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('report_library_entries');
        Schema::dropIfExists('report_sections');
        Schema::dropIfExists('reports');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
