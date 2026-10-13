<?php

namespace App\Services\Evidence\Followup;

/**
 * Fixed sentences the follow-up shows in more than one place.
 */
final class RecordFollowupNotices
{
    /**
     * Wording chosen by the product owner (2026-10-12) for a year without
     * periods, or an occurrence outside them.
     */
    public static function periodsMissing(): string
    {
        return __('Para receber alertas de acumulação, configure os períodos de avaliação e confirme as respetivas datas.');
    }
}
