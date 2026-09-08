<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\EnrollmentHistory;
use App\Services\StudentEnrollmentService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class EnrollmentController extends Controller
{
    public function __construct(protected StudentEnrollmentService $service) {}

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        // Enrolling a student is editing the class's roster.
        Gate::authorize('update', $class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'enrolled_on' => ['nullable', 'date'],
            'school_number' => ['nullable', 'string', 'max:64'],
        ]);

        $this->service->enrollNew($class, $data);

        return back();
    }

    /**
     * The class's N.os de processo, saved together.
     *
     * The school's own identifiers for its students, which arrive with a
     * Relação de Turma when there is one and otherwise have to be typed. Whole
     * class at once because that is how a teacher has them: a column, in roll
     * order, in front of them.
     *
     * NEVER VALIDATED AS A NUMBER. Leading zeros identify people, and plenty of
     * schools use letters — «max:64» and «string» is the whole of it. An empty
     * field means the student has none recorded, which is a real state and the
     * one every hand-typed class starts in.
     */
    public function updateProcessNumbers(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'numbers' => ['array'],
            'numbers.*.enrollment_ulid' => ['required', 'string'],
            'numbers.*.process_number' => ['nullable', 'string', 'max:64'],
        ]);

        $saved = 0;

        foreach ($data['numbers'] ?? [] as $row) {
            // Resolved THROUGH this class: an ulid from another class — or
            // another organization, which the tenant scope already hides —
            // finds nothing and is skipped, never written.
            $enrollment = $class->enrollments()->where('ulid', $row['enrollment_ulid'])->first();

            if ($enrollment === null) {
                continue;
            }

            $this->service->setProcessNumber($enrollment, $row['process_number'] ?? null);
            $saved++;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "N.º de processo guardado para {$saved} aluno(s)."]);

        return back();
    }

    /**
     * Corrects a student's basic data in place. Same authorization as enrolling
     * one — editing the roster — and the same field rules, so a name accepted on
     * creation stays acceptable on correction.
     */
    public function update(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        // The enrollment resolves within the tenant already (global scope), so
        // this is what stops a valid ulid from another of my own classes.
        abort_unless($enrollment->class_id === $class->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            // Mirrors enrollments_class_student_date_unique — the composite
            // (class_id, student_id, enrolled_on), not a bare unique on the
            // date: classmates share an entry date all the time. Only the same
            // student's OTHER enrollment in the SAME class clashes, which is the
            // re-entry case the date is in the key for.
            //
            // whereDate, not a plain where: enrolled_on is a date column but
            // Eloquent stores it as "Y-m-d 00:00:00", so comparing it against
            // the request's "Y-m-d" string never matches and the clash reaches
            // the database as a 500 instead of a validation message.
            'enrolled_on' => [
                'bail',
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail) use ($class, $enrollment): void {
                    $clashes = Enrollment::where('class_id', $class->id)
                        ->where('student_id', $enrollment->student_id)
                        ->whereKeyNot($enrollment->getKey())
                        ->whereDate('enrolled_on', $value)
                        ->exists();

                    if ($clashes) {
                        $fail('Este aluno já tem uma inscrição nesta turma com esta data de entrada.');
                    }
                },
            ],
        ]);

        $this->service->updateExisting($enrollment, $data);

        return back();
    }

    /**
     * Remove uma inscrição enganada — e SÓ uma inscrição enganada.
     *
     * A pergunta é feita ANTES do DELETE, e não apanhada depois. Todas as
     * chaves estrangeiras que chegam a `enrollments` são RESTRICT de propósito
     * (§13.3): a base de dados recusava, a recusa subia como QueryException e o
     * professor via um erro genérico — em produção, três vezes seguidas, porque
     * nada lhe dizia porquê. Uma restrição relacional legítima é uma condição
     * de negócio conhecida, não uma avaria, e `EnrollmentHistory` sabe
     * antecipá-la; apanhar a QueryException a jusante trataria as duas como a
     * mesma coisa e continuaria a esconder a razão.
     *
     * O QUE EXISTE FICA. Nada é apagado em cascata e nada é reescrito: a
     * inscrição bloqueada continua exatamente como estava, com a sua história
     * inteira, e o professor recebe uma frase que diz o que lá está.
     */
    public function destroy(SchoolClass $class, Enrollment $enrollment, EnrollmentHistory $history): RedirectResponse
    {
        Gate::authorize('update', $class);

        abort_unless($enrollment->class_id === $class->id, 404);

        $blocking = $history->blocking($enrollment);

        if ($blocking !== []) {
            // Um redirecionamento normal, como qualquer outra recusa desta
            // aplicação — NÃO um código de estado escolhido a dedo. O 409 seria
            // a semântica HTTP certa e é exatamente por isso que não se usa: o
            // Inertia reserva-o para dizer ao cliente que a versão dos assets
            // mudou, e devolvê-lo aqui faria a página recarregar por inteiro em
            // vez de mostrar a mensagem. O professor fica onde estava, com o
            // aluno ainda na pauta.
            Inertia::flash('toast', [
                'type' => 'error',
                // «este aluno» quando não há identidade — e não o marcador
                // «(sem identidade)» do ecrã, que numa frase não se leria.
                'message' => $history->explain($enrollment->student->identity->display_name ?? 'este aluno', $blocking),
            ]);

            return back();
        }

        $enrollment->delete();

        return back();
    }
}
