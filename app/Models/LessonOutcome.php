<?php

namespace App\Models;

/**
 * Como uma ocorrência de aula fechou (0.146.0). NULL em `lessons.outcome`
 * significa «ainda não fechada» — nunca um destes valores por defeito.
 */
enum LessonOutcome: string
{
    case Taught = 'taught';
    case TeacherAbsent = 'teacher_absent';
    case ClassExternalActivity = 'class_external_activity';
    /** Aula prevista e não realizada (greve, escola sem condições, avaria…), com causa estruturada. */
    case NotHeld = 'not_held';

    public function label(): string
    {
        return match ($this) {
            self::Taught => __('Lecionada'),
            self::TeacherAbsent => __('Professor ausente'),
            self::ClassExternalActivity => __('Turma em outras atividades letivas'),
            self::NotHeld => __('Aula não realizada'),
        };
    }

    /**
     * Recebe número de lição. UM SÓ SÍTIO: tudo o que numera, renumera ou
     * materializa pergunta aqui (em PHP) ou a `unnumberedValues()` (em SQL) —
     * nunca compara com `TeacherAbsent` à mão, senão uma ocorrência nova
     * receberia número.
     */
    public function isNumbered(): bool
    {
        return $this !== self::TeacherAbsent && $this !== self::NotHeld;
    }

    /**
     * Os valores gravados que NÃO entram na sequência de lições — o equivalente
     * de `! isNumbered()` para consultas.
     *
     * @return list<string>
     */
    public static function unnumberedValues(): array
    {
        return array_values(array_map(
            fn (self $outcome): string => $outcome->value,
            array_filter(self::cases(), fn (self $outcome): bool => ! $outcome->isNumbered()),
        ));
    }

    /** Conta como aula lecionada para o serviço docente. Uma não realizada não conta. */
    public function countsAsTaught(): bool
    {
        return $this !== self::TeacherAbsent && $this !== self::NotHeld;
    }

    /** Conta como desenvolvimento efetivo da disciplina. */
    public function developsSubject(): bool
    {
        return $this === self::Taught;
    }

    /** A assiduidade dos alunos aplica-se. */
    public function takesAttendance(): bool
    {
        return $this === self::Taught;
    }

    /** O planeamento desta ocorrência passa para a seguinte. */
    public function shiftsPlanning(): bool
    {
        return $this !== self::Taught;
    }
}
