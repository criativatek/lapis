<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation lifetime
    |--------------------------------------------------------------------------
    |
    | How long an organization invitation stays acceptable. No approved product
    | convention exists yet for this (Fatia 3 audit found none), so this picks
    | the shorter, more conservative end of common SaaS practice (7–14 days) —
    | consistent with this project's overall posture on data belonging to
    | schools and minors: a stale, unclaimed invite sitting in an inbox is a
    | standing capability, and standing capabilities are worth keeping short.
    |
    */

    'expires_in_days' => 7,

];
