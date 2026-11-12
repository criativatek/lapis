<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Lessons\LessonOutcomeDetails;
use Illuminate\Support\Facades\Gate;

class LessonPolicy
{
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        return $this->teaches($user, $schoolClass);
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    /**
     * Registar o resultado de uma aula (ausência, atividade, não realizada).
     * Um observador acompanha a turma mas não declara o que aconteceu numa
     * aula: a pertença não chega, como em SubjectParticipationPolicy::manage.
     */
    public function recordOutcome(User $user, Lesson $lesson): bool
    {
        return $lesson->schoolClass->teachers()
            ->whereKey($user->getKey())
            ->wherePivot('role', '!=', 'observer')
            ->exists();
    }

    /**
     * Corrigir o resultado já registado de uma aula (RectifyLessonOutcome). Mais estrito do que registar:
     * a correção mostra e muda dado reservado, por isso só a faz quem o pode ler — o titular do registo
     * (LessonOutcomeDetails::mayRead) — e só quem não é observador. Uma aula lecionada não se corrige
     * por aqui (tem sumário, faltas e número). Desligada com a funcionalidade `lesson_not_held_enabled`.
     */
    public function rectifyOutcome(User $user, Lesson $lesson): bool
    {
        return (bool) config('lapis.lesson_not_held_enabled', false)
            && $lesson->outcome !== null
            && $lesson->outcome !== LessonOutcome::Taught
            && $this->recordOutcome($user, $lesson)
            && LessonOutcomeDetails::mayReadReason($lesson, $user);
    }

    /**
     * CORREÇÃO ADMINISTRATIVA EXCECIONAL da causa de uma aula não realizada cujo titular já não pertence à
     * turma. A competência é a que o modelo atual já reconhece à direção da instituição para as retificações
     * (`SchoolClassPolicy::reviewRectifications`: dono de uma organização institucional, leia-se da turma),
     * e não se cria hierarquia nova. Estritamente:
     *
     *  - só `not_held`, e só com o titular fora da turma (`outcome_recorded_by` conhecido e que deixou de a
     *    lecionar). NUNCA ao único professor restante — esse continua sem poder (não é o titular);
     *  - é SEPARADA de poder ler: esta habilidade não torna a direção titular (`LessonOutcomeDetails::mayRead`
     *    não muda), nem lhe dá acesso à aula, à especificação, à descrição ou ao histórico.
     */
    public function rectifyOutcomeAsAdministrator(User $user, Lesson $lesson): bool
    {
        if (! config('lapis.lesson_not_held_enabled', false)
            || $lesson->outcome !== LessonOutcome::NotHeld
            || $lesson->outcome_recorded_by === null) {
            return false;
        }

        $class = $lesson->schoolClass;

        return Gate::forUser($user)->allows('reviewRectifications', $class)
            && ! $class->teachers()->whereKey($lesson->outcome_recorded_by)->exists();
    }

    /**
     * ANULAR UM «LECIONADA» (0.194.0): a aula não se realizou. Quem fechou a aula (o titular, pela regra
     * de LessonOutcomeDetails::mayReadReason — e nunca um observador), ou a direção da instituição que
     * detém a turma, pela competência que já tem em «Retificações». Desligada com a funcionalidade.
     */
    public function annulTaught(User $user, Lesson $lesson): bool
    {
        if (! config('lapis.lesson_not_held_enabled', false) || ! $lesson->isTaught()) {
            return false;
        }

        $ownClosing = $this->recordOutcome($user, $lesson) && LessonOutcomeDetails::mayReadReason($lesson, $user);

        return $ownClosing || Gate::forUser($user)->allows('reviewRectifications', $lesson->schoolClass);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    protected function teaches(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->teachers()->whereKey($user->getKey())->exists();
    }
}
