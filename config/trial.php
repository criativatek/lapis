<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Voluntary Pro trial
    |--------------------------------------------------------------------------
    |
    | How many days a Personal organization's self-service Pro trial lasts.
    | See App\Support\Trial\TrialPolicy (the typed reader over this file) and
    | App\Services\Organizations\ChangeOrganizationPlan::startProTrial().
    |
    */

    'pro_days' => (int) env('TRIAL_PRO_DAYS', 30),

];
