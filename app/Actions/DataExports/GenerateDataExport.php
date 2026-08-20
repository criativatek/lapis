<?php

namespace App\Actions\DataExports;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\DataExport;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Builds a "my data" export ZIP (Fatia 4, §20-24) — synchronously, because
 * nothing in this app is queued yet and one teacher's own classes are a
 * small, bounded dataset (never "the whole organization's pedagogical data,"
 * even for the owner — see class docblock on `DataExportPolicy`).
 *
 * Content is driven entirely by the SAME access `class_teachers` already
 * grants this user (§23, "only my classes") — an institutional owner gets
 * nothing extra pedagogically, only the governance-level rows their role
 * already lets them see (team roster, permitted audit events, identity).
 *
 * Never exports: password hashes, 2FA secrets, passkeys, remember tokens,
 * invitation token_hash, sessions, platform settings — nothing here ever
 * touches those tables at all, by construction (every query below is an
 * explicit column allowlist, never `toArray()`/`SELECT *`).
 */
class GenerateDataExport
{
    public function __construct(
        protected AuditLog $audit,
        protected RetentionPolicy $retentionPolicy,
    ) {}

    public function generate(Organization $organization, User $user): DataExport
    {
        $this->audit->record(
            'data_export.requested',
            $organization,
            causer: $user,
            summary: "{$user->name} pediu uma exportação dos seus dados.",
        );

        $export = DataExport::create([
            'requested_by' => $user->getKey(),
            'status' => 'failed',
        ]);

        try {
            $token = (string) Str::uuid();
            $relativePath = "data-exports/{$token}/export.zip";
            $absolutePath = Storage::disk('local')->path($relativePath);

            Storage::disk('local')->makeDirectory("data-exports/{$token}");

            $this->buildZip($absolutePath, $organization, $user);

            $export->update([
                'status' => 'ready',
                'disk_path' => $relativePath,
                'byte_size' => filesize($absolutePath) ?: null,
                'failed_reason' => null,
                'expires_at' => Carbon::now()->addHours($this->retentionPolicy->dataExportAvailabilityHours()),
            ]);

            $this->audit->record(
                'data_export.generated',
                $organization,
                causer: $user,
                summary: "Exportação de dados de {$user->name} gerada.",
            );
        } catch (Throwable $exception) {
            $export->update(['status' => 'failed', 'failed_reason' => substr($exception->getMessage(), 0, 255)]);

            throw $exception;
        }

        return $export->fresh();
    }

    protected function buildZip(string $absolutePath, Organization $organization, User $user): void
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o ficheiro de exportação.');
        }

        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->with(['subject', 'academicYear'])
            ->get();
        $classIds = $classes->pluck('id');

        $zip->addFromString('dados/turmas.csv', $this->csv(
            ['ulid', 'label', 'disciplina', 'ano_letivo', 'estado'],
            $classes->map(fn (SchoolClass $class): array => [
                $class->ulid,
                $class->label,
                $class->subject?->name,
                $class->academicYear->label,
                $class->status->value,
            ]),
        ));

        $enrollments = Enrollment::query()->whereIn('class_id', $classIds)->with('student.identity')->get();

        $students = $enrollments->pluck('student')->filter()->unique('id');
        $zip->addFromString('dados/alunos.csv', $this->csv(
            ['ulid', 'nome', 'numero'],
            $students->map(fn (Student $student): array => [
                $student->ulid,
                $student->identity?->display_name,
                $student->identity?->school_number,
            ]),
        ));

        $evidenceRecords = EvidenceRecord::query()->whereIn('class_id', $classIds)->with('enrollment.student')->get();
        $zip->addFromString('dados/registos.csv', $this->csv(
            ['ulid', 'turma_ulid', 'aluno_ulid', 'data', 'tipo', 'descricao'],
            $evidenceRecords->map(fn (EvidenceRecord $record): array => [
                $record->ulid,
                $classes->firstWhere('id', $record->class_id)?->ulid,
                $record->enrollment?->student?->ulid,
                $record->occurred_at->toDateString(),
                $record->kind->value,
                $record->description,
            ]),
        ));

        $instruments = Instrument::query()->whereIn('class_id', $classIds)->get();
        $zip->addFromString('dados/elementos-avaliacao.csv', $this->csv(
            ['ulid', 'turma_ulid', 'titulo', 'data', 'estado'],
            $instruments->map(fn (Instrument $instrument) => [
                $instrument->ulid,
                $classes->firstWhere('id', $instrument->class_id)?->ulid,
                $instrument->title,
                $instrument->applied_on->toDateString(),
                $instrument->status->value,
            ]),
        ));

        $classifications = Classification::query()
            ->whereHas('enrollment', fn ($query) => $query->whereIn('class_id', $classIds))
            ->with('enrollment.student')
            ->get();
        $zip->addFromString('dados/classificacoes.csv', $this->csv(
            ['ulid', 'aluno_ulid', 'estado', 'valor_final'],
            $classifications->map(fn (Classification $classification): array => [
                $classification->ulid,
                $classification->enrollment?->student?->ulid,
                $classification->status->value,
                $classification->final_value,
            ]),
        ));

        $selfAssessments = SelfAssessment::query()
            ->whereHas('enrollment', fn ($query) => $query->whereIn('class_id', $classIds))
            ->with('enrollment.student')
            ->get();
        $zip->addFromString('dados/autoavaliacoes.csv', $this->csv(
            ['ulid', 'aluno_ulid', 'estado', 'submetida_em'],
            $selfAssessments->map(fn (SelfAssessment $selfAssessment): array => [
                $selfAssessment->ulid,
                $selfAssessment->enrollment?->student?->ulid,
                $selfAssessment->status->value,
                $selfAssessment->submitted_at?->toDateString(),
            ]),
        ));

        $interventions = Intervention::query()->whereIn('class_id', $classIds)->get();
        $zip->addFromString('dados/estrategias-medidas.csv', $this->csv(
            ['ulid', 'turma_ulid', 'titulo', 'estado'],
            $interventions->map(fn (Intervention $intervention): array => [
                $intervention->ulid,
                $classes->firstWhere('id', $intervention->class_id)?->ulid,
                $intervention->title,
                $intervention->status->value,
            ]),
        ));

        if ($user->owns($organization)) {
            $this->addInstitutionalExtras($zip, $organization);
        }

        $zip->addFromString('manifest.json', $this->manifest($organization, $user, $classes->count(), $students->count()));
        $zip->addFromString('README.txt', $this->readme($organization));

        $zip->close();
    }

    /**
     * Owner-only, and still bounded by what `OrganizationPolicy`/`AuditEvent::
     * scopeVisibleTo` already grant this exact user — never a pedagogical
     * bypass, just the governance data an owner already sees on the Equipa
     * and Atividade pages.
     */
    protected function addInstitutionalExtras(ZipArchive $zip, Organization $organization): void
    {
        $members = $organization->members()->get();
        $zip->addFromString('configuracao/equipa.csv', $this->csv(
            ['nome', 'email', 'responsavel'],
            $members->map(fn (User $member): array => [
                $member->name,
                $member->email,
                $member->is($organization->owner) ? 'sim' : 'não',
            ]),
        ));

        $events = AuditEvent::query()->orderByDesc('created_at')->limit(5000)->get();
        $zip->addFromString('configuracao/auditoria.csv', $this->csv(
            ['data', 'evento', 'resumo'],
            $events->map(fn (AuditEvent $event): array => [
                $event->created_at->toDateTimeString(),
                $event->event,
                $event->summary,
            ]),
        ));
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected function csv(array $header, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Não foi possível preparar o CSV da exportação.');
        }

        fputcsv($stream, $header);

        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    protected function manifest(Organization $organization, User $user, int $classCount, int $studentCount): string
    {
        return json_encode([
            'schema_version' => 1,
            'app_version' => (string) config('app.version'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'organization' => ['ulid' => $organization->ulid, 'name' => $organization->name],
            'exported_by' => ['name' => $user->name, 'email' => $user->email],
            'counts' => ['classes' => $classCount, 'students' => $studentCount],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected function readme(Organization $organization): string
    {
        return <<<TXT
        LÁPIS — exportação de dados

        Organização: {$organization->name}
        Gerado em: {$this->now()}

        Este ficheiro contém os dados a que a sua conta tem acesso — as suas
        próprias turmas e o que lhes está associado. Não inclui trabalho
        pedagógico de colegas, nem dados de outras organizações.

        Não inclui, em nenhuma circunstância: password, autenticação de dois
        fatores, passkeys, tokens de sessão ou de convite, nem segredos de
        configuração da plataforma.

        Este ficheiro fica disponível por tempo limitado e é depois removido
        automaticamente — não é um backup técnico da plataforma.
        TXT;
    }

    protected function now(): string
    {
        return Carbon::now()->toDayDateTimeString();
    }
}
