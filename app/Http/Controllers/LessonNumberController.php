<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\SetLessonNumber;
use App\Http\Requests\Lessons\LessonNumberPreviewRequest;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * O número da lição indicado pelo professor (0.169.0) — a PRÉ-VISUALIZAÇÃO.
 *
 * A gravação não tem rota própria: o número viaja com o sumário (PUT/PATCH de
 * LessonController), na mesma transação. Aqui o ecrã pergunta, antes de gravar,
 * o que a alteração faria: os conflitos que a bloqueiam, as outras aulas cujo
 * número mudaria e os avisos — e recebe a confirmação (hash) que a gravação
 * exige quando alguma dessas aulas já está registada.
 *
 * SÓ LÊ: nenhuma escrita, nenhuma auditoria, nenhum evento. É calculada pelo
 * mesmo `SetLessonNumber::plan()` que a execução usa.
 */
class LessonNumberController extends Controller implements HasMiddleware
{
    public function __construct(protected SetLessonNumber $setLessonNumber) {}

    /** @return list<string> */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function preview(LessonNumberPreviewRequest $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('update', $lesson);

        return response()->json($this->setLessonNumber->plan($lesson, $request->numberChange()));
    }
}
