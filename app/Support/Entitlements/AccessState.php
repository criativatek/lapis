<?php

namespace App\Support\Entitlements;

/**
 * How much of a module an organization may use right now.
 *
 * A boolean answers "may this organization use this module?" but cannot
 * distinguish two very different reasons for "no": a module the plan never
 * sold at all, and a module the organization used to have and is only
 * temporarily without (a suspended subscription, §Lote 2). `Allowed` and
 * `Locked` alone are the old boolean, unchanged; `ReadOnly` is the new middle
 * state — the organization may still SEE what is there, but may not create or
 * change anything, while the suspension lasts.
 */
enum AccessState: string
{
    case Allowed = 'allowed';
    case ReadOnly = 'read_only';
    case Locked = 'locked';

    /** May the organization see this module's data at all? True for Allowed and ReadOnly. */
    public function permitsRead(): bool
    {
        return $this !== self::Locked;
    }

    /** May the organization create or change this module's data? True only for Allowed. */
    public function permitsWrite(): bool
    {
        return $this === self::Allowed;
    }
}
