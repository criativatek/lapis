<?php

namespace App\Models;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case TransferredOut = 'transferred_out';
    case Left = 'left';
    case Concluded = 'concluded';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Inscrito'),
            self::TransferredOut => __('Transferido'),
            self::Left => __('Saiu'),
            self::Concluded => __('Concluído'),
        };
    }

    /**
     * Whether the student is part of the class AS IT STANDS TODAY.
     *
     * STATED POSITIVELY, and that is the whole point. «not left» would have
     * quietly counted a transfer, a conclusion and every state added after it
     * as current; only `active` is current, and a new case has to opt in
     * rather than being included by having been forgotten (§10, §11).
     */
    public function isCurrent(): bool
    {
        return $this === self::Active;
    }
}
