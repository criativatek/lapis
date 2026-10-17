<?php

namespace App\Models;

/**
 * O estado do registo de UMA sessão de uma «outra atividade». Os valores são
 * estáveis (estão na CHECK da tabela e no backup); o rótulo é o que o professor
 * lê. «Preparada» não é um valor guardado: é a apresentação de `planned` quando
 * já há texto — ver `presentation()`.
 */
enum TimetableActivitySessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case NotHeld = 'not_held';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Por realizar',
            self::Done => 'Realizada',
            self::NotHeld => 'Não realizada',
        };
    }

    /**
     * O estado tal como se mostra: `planned` com texto lê-se «Preparada».
     * Devolve a chave de apresentação (também a do tom visual).
     */
    public function presentation(bool $hasSummary): string
    {
        return $this === self::Planned && $hasSummary ? 'prepared' : $this->value;
    }

    public function presentationLabel(bool $hasSummary): string
    {
        return $this === self::Planned && $hasSummary ? 'Preparada' : $this->label();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
