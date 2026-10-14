<?php

namespace App\Http\Controllers;

use App\Models\AssessmentProfileVersion;
use App\Models\ProfileVersionStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\MigrateClassProfile;
use App\Support\Assessment\ProfileMigrationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The auditable profile migration (§10.2, A4): preview the impact per student,
 * then confirm with a reason. Reached when a class that already has results is
 * pointed at a different profile version — the bare swap is not allowed there.
 */
class ClassProfileMigrationController extends Controller
{
    public function __construct(protected MigrateClassProfile $migrator) {}

    public function create(Request $request, SchoolClass $class): Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $toVersion = AssessmentProfileVersion::query()
            ->where('ulid', (string) $request->query('to'))->first();

        if ($toVersion === null || $toVersion->status !== ProfileVersionStatus::Active) {
            return redirect()->route('classes.show', $class)
                ->withErrors(['assessment_profile_version_id' => __('Só um perfil ativo pode ser associado a uma turma.')]);
        }

        return Inertia::render('classes/ProfileMigration', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'toVersionUlid' => $toVersion->ulid,
            'preview' => $this->migrator->preview($class, $toVersion),
        ]);
    }

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'to_version' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        // Resolved by ULID under the tenant scope, so a version from another
        // organization simply is not found — never coerced to a primary key.
        $toVersion = AssessmentProfileVersion::where('ulid', $data['to_version'])->first();

        if ($toVersion === null) {
            return back()->withErrors(['reason' => __('Versão de perfil inválida.')]);
        }

        // Acesso técnico (ADR-0014): quem confirma é o administrador, em nome do
        // professor que a sessão representa. Decide-se aqui, na borda HTTP — o
        // serviço não conhece sessões.
        $representedTeacher = $this->user();
        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = $impersonatorId === null || (int) $impersonatorId === (int) $representedTeacher->getKey()
            ? null
            : User::query()->whereKey($impersonatorId)->first();

        try {
            $migration = $this->migrator->migrate(
                $class,
                $toVersion,
                $data['reason'],
                $impersonator ?? $representedTeacher,
                $impersonator === null ? null : $representedTeacher,
            );
        } catch (ProfileMigrationException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        $toVersion->loadMissing(['profile', 'scale']);
        $affected = $migration->affected_enrollment_count;

        Inertia::flash('toast', ['type' => 'success', 'message' => __(
            'Turma atualizada para «:profile», versão :version (:scale): :affected :students com a proposta alterada.',
            [
                'profile' => $toVersion->profile->name,
                'version' => $toVersion->version_number,
                'scale' => $toVersion->scale->name,
                'affected' => $affected,
                'students' => $affected === 1 ? __('aluno') : __('alunos'),
            ],
        )]);

        return redirect()->route('classes.show', $class);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
