<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\InsertLessonIntoSequence;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\InsertLessonRequest;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * Inserir uma aula no meio de uma sequência já preparada, deslocando as
 * seguintes para as próximas ocorrências válidas do horário.
 *
 * DUAS ROTAS PARA UMA OPERAÇÃO: a pré-visualização e a execução. O professor
 * não deve descobrir que mexeu em quatro aulas depois de ter mexido — e a
 * pré-visualização é calculada pelo mesmo `plan()` que a execução usa, pelo que
 * o que ela promete é literalmente o que vai acontecer.
 */
class LessonInsertionController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(protected InsertLessonIntoSequence $insertLesson) {}

    /** @return list<string> */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function preview(InsertLessonRequest $request): JsonResponse
    {
        $class = $request->schoolClass();
        Gate::authorize('create', [Lesson::class, $class]);

        return response()->json($this->insertLesson->preview(
            $class,
            $request->classGroupId(),
            $this->insertAt($request),
        ));
    }

    public function store(InsertLessonRequest $request): RedirectResponse
    {
        $class = $request->schoolClass();
        Gate::authorize('create', [Lesson::class, $class]);
        $this->refuseDuringImpersonation($request);

        $result = $this->insertLesson->execute(
            $class,
            $request->classGroupId(),
            $this->insertAt($request),
            $this->user($request),
        );

        $moved = $result['moved'];

        return back()->with('success', $moved === 0
            ? 'Aula inserida.'
            : sprintf('Aula inserida. %d aula%s deslocada%s.', $moved, $moved === 1 ? '' : 's', $moved === 1 ? '' : 's'));
    }

    private function insertAt(InsertLessonRequest $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->validated('insert_at'), 'Europe/Lisbon')->startOfDay();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
