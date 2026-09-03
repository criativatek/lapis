<?php

namespace App\Enums;

enum SupportAccessReasonCategory: string
{
    case AssistenciaTecnica = 'technical_assistance';
    case Diagnostico = 'diagnosis';
    case Manutencao = 'maintenance';
    case Seguranca = 'security';
    case Incidente = 'incident';
    case VerificacaoTecnica = 'technical_verification';
    case Outro = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AssistenciaTecnica => __('Assistência técnica'),
            self::Diagnostico => __('Diagnóstico'),
            self::Manutencao => __('Manutenção'),
            self::Seguranca => __('Segurança'),
            self::Incidente => __('Incidente'),
            self::VerificacaoTecnica => __('Verificação técnica'),
            self::Outro => __('Outro'),
        };
    }
}
