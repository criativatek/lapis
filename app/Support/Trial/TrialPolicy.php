<?php

namespace App\Support\Trial;

/**
 * A thin, stateless reader over config/trial.php. No logic beyond reading
 * config, so it is trivially fakeable in tests via config()->set(...) —
 * exactly the shape of App\Support\Retention\RetentionPolicy.
 */
final class TrialPolicy
{
    public function proDays(): int
    {
        return (int) config('trial.pro_days');
    }
}
