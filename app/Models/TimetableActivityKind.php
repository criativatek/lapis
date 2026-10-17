<?php

namespace App\Models;

/**
 * O que é uma atividade não letiva do horário. Os valores são estáveis (estão
 * na CHECK da tabela e no backup); o rótulo é o que o professor lê.
 */
enum TimetableActivityKind: string
{
    case ClassDirection = 'class_direction';
    case StudentSupport = 'student_support';
    case Meeting = 'meeting';
    case Coordination = 'coordination';
    case Club = 'club';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClassDirection => 'Direção de turma',
            self::StudentSupport => 'Apoio a alunos',
            self::Meeting => 'Reunião',
            self::Coordination => 'Coordenação / cargo',
            self::Club => 'Clube',
            self::Other => 'Outra',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
