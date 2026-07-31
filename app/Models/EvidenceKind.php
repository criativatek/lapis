<?php

namespace App\Models;

/**
 * The kinds of logbook entry (§14). Behaviour and academic classification stay
 * separate (§14.3) — none of these ever enters the calculation.
 */
enum EvidenceKind: string
{
    case Homework = 'homework';
    case Incident = 'incident';
    case PositiveBehaviour = 'positive_behaviour';
    case Participation = 'participation';
    case Progress = 'progress';
    case Difficulty = 'difficulty';
    case Support = 'support';
    case Contact = 'contact';
    case Activity = 'activity';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Homework => __('Trabalho de casa'),
            self::Incident => __('Ocorrência disciplinar'),
            self::PositiveBehaviour => __('Comportamento meritório (G1)'),
            self::Participation => __('Participação'),
            self::Progress => __('Progresso'),
            self::Difficulty => __('Dificuldade'),
            self::Support => __('Apoio'),
            self::Contact => __('Contacto'),
            self::Activity => __('Atividade'),
            self::Note => __('Observação'),
        };
    }
}
