<?php

namespace App\Support\Limits;

/**
 * Catalogue of quantitative limit keys — the `Limits` analogue of a module
 * key in `App\Support\Entitlements` (the same native-enum idiom the project
 * already uses for `AccessState`). Extensible by adding a case here and a
 * matching key in every plan's `limits` JSON (§Lote 3) — no other mechanism
 * is needed.
 */
enum LimitKey: string
{
    case ActiveClasses = 'active_classes';
    case ActiveStudents = 'active_students';
}
