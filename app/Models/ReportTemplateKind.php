<?php

namespace App\Models;

/**
 * Where a report template came from (§2).
 *
 * THREE ORIGINS, AND THEY DIFFER IN WHO MAY CHANGE THEM rather than in what
 * they can express. A system template and an institutional one hold the same
 * kind of configuration; what separates them is that one is the Lapispro default
 * and the other is a school's own standard.
 *
 * No `shared` and no `draft`. Neither has a meaning in this application yet,
 * and inventing states is inventing workflow.
 */
enum ReportTemplateKind: string
{
    /** Shipped with Lapispro. Visible to everyone, editable by nobody. */
    case System = 'system';

    /** One teacher's own. Visible only to them. */
    case Personal = 'personal';

    /** A school's standard. Visible to its members, editable by its owner. */
    case Institutional = 'institutional';

    public function label(): string
    {
        return match ($this) {
            self::System => __('Lapispro'),
            self::Personal => __('Pessoal'),
            self::Institutional => __('Escola'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System => __('Modelo fornecido pelo Lapispro.'),
            self::Personal => __('Um modelo seu, visível apenas para si.'),
            self::Institutional => __('Modelo da escola, disponível para todos os seus professores.'),
        };
    }

    /**
     * The capability a school needs before it can CREATE templates of this
     * kind. Reading is governed separately — every plan reads the system ones.
     */
    public function moduleToCreate(): ?string
    {
        return match ($this) {
            // Nobody creates a system template through the application.
            self::System => null,
            self::Personal => 'template_sharing',
            self::Institutional => 'institution_library',
        };
    }

    public function isSystem(): bool
    {
        return $this === self::System;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
