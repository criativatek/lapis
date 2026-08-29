<?php

namespace App\Support\Entitlements;

/**
 * Which capabilities a downgrade leaves CONSULTABLE instead of taking away.
 *
 * §19 of the Matriz Mestre describes Pro → Base as four separate promises, not
 * one: the Base half keeps working, the interpretive half locks, the AI stops,
 * and «dados legitimamente criados não são apagados». §15 then writes the
 * mechanism down as an example with the literal states it expects:
 *
 *     student_tracking   = allowed        reports    = allowed
 *     student_analytics  = locked         report_ai  = locked
 *     teacher_timetable  = read_only      lessons_workspace = read_only
 *
 * Locking everything the new plan does not sell satisfies the first three and
 * breaks the fourth. A teacher who spent a year writing sumários has not lost
 * them when the subscription changes — but until this class existed they could
 * no longer open a single one, which is the same thing from where they are
 * standing. `AccessState::ReadOnly` is the state the architecture already had
 * for exactly this, and `RequireModule` already serves safe methods for it, so
 * nothing new had to be invented: only the question of WHICH keys deserve it.
 *
 * THE TEST IS «DID THE TEACHER PUT SOMETHING IN THERE?», not «is it useful».
 * A capability earns a place on this list when losing it would hide records a
 * person authored, under a plan that no longer sells the workspace they were
 * authored in. It does NOT earn one for being pleasant to keep:
 *
 *   - `lessons` — aulas, sumários, planeamento, sequências and the horário
 *     they hang off. This is §15's own example, twice over, and it is the
 *     only key in the current catalogue that holds a year of the teacher's
 *     own writing behind a Pro entitlement.
 *
 * Deliberately NOT retained, each for a stated reason:
 *
 *   - `advanced_analytics`, `report_pedagogical_analysis` — the interpretive
 *     layer §19 names as the thing that locks. Nothing is stored there: every
 *     reading is recomputed on each request, so «preserving» it would mean
 *     CONTINUING TO PRODUCE it, which is precisely what §19 forbids («não
 *     continuar a gerar novos insights Pro»). A Pro reading that WAS emitted
 *     and frozen — a finalized report — stays readable through `reports`,
 *     which is Base, and that is §19's «síntese anteriormente emitida ...
 *     pode continuar consultável, sem permitir regeneração».
 *   - every `ai_*` key and `ai_assistance` — «IA deixa de executar» (§19).
 *     A read-only AI capability is a contradiction: there is nothing to read,
 *     only a call to make.
 *   - `calendar_import`, `correction_grid_import`, `inovar_export`,
 *     `template_sharing`, `self_assessment_links`, `data_backup_restore` —
 *     ways of getting data in or out, not places data lives. What each one
 *     produced (the calendar, the results, the self-assessments, the export
 *     file) is reached through a Base capability and is untouched by losing
 *     the door it came through.
 *   - `institution_*` and `audit_log` — the institutional block is governed
 *     as its own slice; nothing here changes what an Institucional → Pro
 *     transition does today.
 *
 * A key absent from this list keeps exactly the behaviour it has always had:
 * `Locked`, the moment the plan stops granting it.
 */
final class RetainedOnDowngrade
{
    /**
     * @var list<string>
     */
    public const KEYS = [
        'lessons',
    ];

    public static function includes(string $moduleKey): bool
    {
        return in_array($moduleKey, self::KEYS, strict: true);
    }
}
