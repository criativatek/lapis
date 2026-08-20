<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention policy — Fatia 4
    |--------------------------------------------------------------------------
    |
    | These are the target retention windows for the different kinds of data
    | LÁPIS holds. This file is the single source of truth for the numbers;
    | App\Support\Retention\RetentionPolicy is the typed reader over it, and
    | App\Support\Retention\AcademicYearRetentionClassifier /
    | App\Support\Retention\ClosureRetention are the pure classification
    | helpers built on top. See docs/data-lifecycle.md for the full policy
    | write-up, including what this fatia does and does not enforce yet.
    |
    */

    // Pedagogical data (grades, evidence, classifications, reports): the
    // current academic year plus this many PREVIOUS years remain identifiable.
    // This is a count of ACADEMIC YEARS, never a raw timestamp comparison —
    // see App\Support\Retention\AcademicYearRetentionClassifier.
    'pedagogical_previous_years_retained' => 3,

    // Closed personal account: days from closure request until eligible for
    // deletion. The account and its data remain fully recoverable/exportable
    // until this elapses.
    'personal_account_closure_days' => 60,

    // Closed institutional organization: same concept, longer window because
    // more people depend on the data.
    'institutional_closure_days' => 90,

    // Technical application logs (storage/logs) — operational retention, not
    // a product/export concern.
    'technical_log_days' => 90,

    // Security/institutional audit trail (audit_events) — kept longer than
    // technical logs because it's the record of who-did-what.
    'security_audit_years' => 3,

    // Technical database backups — disaster-recovery rotation window, NOT the
    // same thing as a user-requested data export. Expressed as a range
    // because the exact figure is an infrastructure/ops decision, not a
    // per-request one; document both bounds.
    'technical_backup_rotation_days_min' => 30,
    'technical_backup_rotation_days_max' => 60,

    // How long a user-generated data export ZIP stays downloadable before
    // automatic cleanup.
    'data_export_availability_hours' => 24,

];
