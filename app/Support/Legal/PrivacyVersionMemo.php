<?php

namespace App\Support\Legal;

use Carbon\CarbonImmutable;

/**
 * A memória de PrivacyVersion para um pedido ou um job. Registada como
 * `scoped` (AppServiceProvider): o contentor esquece-a entre jobs de um worker,
 * e cada pedido começa com uma nova.
 */
final class PrivacyVersionMemo
{
    public bool $resolved = false;

    public ?CarbonImmutable $activatedAt = null;
}
