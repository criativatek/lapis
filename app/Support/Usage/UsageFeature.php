<?php

namespace App\Support\Usage;

/**
 * As funcionalidades em que a utilização é medida. Lista fechada: é o que
 * `usage_events.feature` pode conter, e o parâmetro aceite pelo middleware
 * `usage:<feature>`.
 */
enum UsageFeature: string
{
    case Classes = 'classes';
    case Lessons = 'lessons';
    case Assessment = 'assessment';
    case Records = 'records';
    case Interventions = 'interventions';
    case Characterisation = 'characterisation';
    case ClassNotebook = 'class_notebook';
    case Reports = 'reports';
    case ImportExport = 'import_export';
    case Calendar = 'calendar';

    public function label(): string
    {
        return match ($this) {
            self::Classes => 'Turmas e alunos',
            self::Lessons => 'Aulas e sumários',
            self::Assessment => 'Avaliação',
            self::Records => 'Registos',
            self::Interventions => 'Estratégias e medidas',
            self::Characterisation => 'Caracterização pedagógica',
            self::ClassNotebook => 'Caderno da turma',
            self::Reports => 'Relatórios',
            self::ImportExport => 'Importação e exportação',
            self::Calendar => 'Calendário',
        };
    }
}
