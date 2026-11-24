<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentStudentComment;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * O comentário privado do professor sobre UM aluno num elemento de avaliação
 * («Apanhado a copiar na Q2»). Escreve só em `instrument_student_comments`:
 * nenhuma nota, classificação ou perfil é tocado aqui.
 *
 * Uma só rota grava, atualiza e apaga: corpo vazio apaga a linha. O controlo
 * otimista de `lock_version` é o de `InstrumentResultsController::updateNote()`,
 * com a mesma mensagem de conflito. A auditoria regista o que aconteceu e a
 * quem, NUNCA o texto: seria uma segunda cópia, sem limite, do que o professor
 * escreveu sobre uma criança.
 */
class InstrumentStudentCommentController extends Controller
{
    private const CONFLICT = 'O comentário foi alterado noutra janela. Recarregue a página para ver a versão mais recente.';

    public function __construct(protected AuditLog $audit) {}

    public function update(Request $request, Instrument $instrument, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        // A inscrição tem de ser DESTA turma: um ulid de outra turma é um 404 e
        // não um 403 — confirmar que existe noutro sítio diria mais do que quem
        // pergunta tem direito a saber.
        abort_unless($enrollment->class_id === $instrument->class_id, 404);

        abort_if(
            $instrument->status === InstrumentStatus::Cancelled,
            403,
            'Este elemento de avaliação está anulado — reverta a anulação antes de o editar ou lançar notas.',
        );

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.InstrumentStudentComment::BODY_MAX_LENGTH],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));

        $comment = InstrumentStudentComment::query()
            ->where('instrument_id', $instrument->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->first();

        $storedVersion = $comment === null ? 0 : $comment->lock_version;

        if ($storedVersion !== $data['lock_version']) {
            return back()->withErrors(['body' => self::CONFLICT]);
        }

        $user = $this->user();
        $action = null;

        if ($comment === null) {
            if ($body !== '') {
                try {
                    InstrumentStudentComment::create([
                        'instrument_id' => $instrument->getKey(),
                        'enrollment_id' => $enrollment->getKey(),
                        'body' => $body,
                        'lock_version' => 1,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Duas janelas a criar o primeiro comentário ao mesmo tempo:
                    // a leitura não viu linha, mas outro INSERT ganhou. Recusa-se
                    // com a mesma mensagem de uma versão desatualizada.
                    return back()->withErrors(['body' => self::CONFLICT]);
                }

                $action = 'created';
            }
        } elseif ($body === '') {
            $deleted = InstrumentStudentComment::query()
                ->whereKey($comment->getKey())
                ->where('lock_version', $data['lock_version'])
                ->delete();

            if ($deleted === 0) {
                return back()->withErrors(['body' => self::CONFLICT]);
            }

            $action = 'deleted';
        } else {
            // Condicional à versão lida, na mesma instrução: duas janelas a
            // gravar ao mesmo tempo não passam ambas a verificação de cima.
            $written = InstrumentStudentComment::query()
                ->whereKey($comment->getKey())
                ->where('lock_version', $data['lock_version'])
                ->update([
                    'body' => $body,
                    'lock_version' => $data['lock_version'] + 1,
                    'updated_by' => $user->id,
                    'updated_at' => now(),
                ]);

            if ($written === 0) {
                return back()->withErrors(['body' => self::CONFLICT]);
            }

            $action = 'updated';
        }

        if ($action !== null) {
            $this->audit->record(
                'instrument.student_comment_'.$action,
                $instrument,
                $user,
                "Comentário sobre um aluno em «{$instrument->title}» ({$action}) — {$instrument->schoolClass->label}.",
                [
                    'class_id' => $instrument->class_id,
                    'instrument_id' => $instrument->id,
                    'enrollment_id' => $enrollment->id,
                ],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $action === 'deleted' ? 'Comentário apagado.' : 'Comentário guardado.',
        ]);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
