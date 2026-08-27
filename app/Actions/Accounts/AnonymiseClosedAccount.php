<?php

namespace App\Actions\Accounts;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Retention\ClosureRetention;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carries out an account closure whose recovery window has run out.
 *
 * WHY THIS ANONYMISES INSTEAD OF DELETING — the schema decided it, not this
 * class. There are 34 `ON DELETE RESTRICT` foreign keys into `users` (every
 * `created_by`, `confirmed_by`, `reviewed_by`, `activated_by`…) and 40 into
 * `organizations`. Most decisively, `audit_events.causer_id` is RESTRICT across
 * every organization — and `RequestPersonalAccountClosure` itself records
 * `account.closure_requested` with that person as the causer. **Asking to close
 * an account writes the very row that makes hard-deleting it impossible.**
 *
 * That is not an accident to work around. `AdminAccountController::destroy`
 * already refuses to delete an account that has data and says why, and its
 * docblock states the principle plainly: deleting history to make a delete
 * succeed «is not on the table (§22.4, §31)». `DeleteUserAccount` therefore
 * only ever worked on an account that never became anything, which is why it is
 * not the executor here.
 *
 * SO THE IDENTITY GOES AND THE ROWS STAY. The schema already put the personal
 * data in a few named places — `users` for the teacher, `student_identities`
 * (encrypted `display_name` and `school_number`) for the student, with
 * `students` holding only a pseudonym. Emptying those is what erasure means
 * here: a classification, a record or a report survives, and no longer relates
 * to an identifiable person.
 *
 * WHAT IT NEVER TOUCHES: an institutional organization. Closure is refused
 * upfront to anyone who owns one (`RequestPersonalAccountClosure`), so at this
 * point the person can only be a member — and a member's departure must not
 * take a school's classes, students or assessments with it. Their membership is
 * left in place on purpose: the account can no longer authenticate, and
 * removing the row would orphan `class_teachers` (also RESTRICT) for teaching
 * that really happened.
 *
 * ORDER MATTERS, AND IT IS: files first (their paths live on the rows about to
 * go), then the database in one transaction. A crash between the two leaves
 * orphaned rows pointing at deleted files, which the next run finishes off —
 * the reverse would leave files nobody can find again.
 */
class AnonymiseClosedAccount
{
    /** Where a student photo's path is rooted. Same disk StudentPhotoService writes to. */
    protected const PHOTO_DISK = 'local';

    public function __construct(
        protected ClosureRetention $retention,
        protected CurrentOrganization $currentOrganization,
        protected AuditLog $audit,
    ) {}

    /**
     * True when this account is closed, out of its window, and not yet done.
     *
     * The window question is delegated to `ClosureRetention`, which is the same
     * authority `DeletionEligibility` and the banners already use. A second
     * opinion here — comparing `scheduled_deletion_at` directly — would be a
     * second definition of «recoverable», and the two would disagree the first
     * time somebody changed the policy.
     */
    public function isDue(User $user, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($user->closure_requested_at === null || $user->anonymized_at !== null) {
            return false;
        }

        return ! $this->retention->isPersonalAccountRecoverable($user->closure_requested_at, $now);
    }

    /**
     * @return array<string, int> what was removed, for the audit trail — counts only
     */
    public function execute(User $user): array
    {
        $personal = $user->ownedOrganizations()
            ->where('type', OrganizationType::Personal)
            ->first();

        $removed = [
            'student_identities' => 0,
            'organization_identities' => 0,
            'photos' => 0,
            'passkeys' => 0,
            'sessions' => 0,
        ];

        // ---- Files first: their paths are attributes of rows about to go. ----
        if ($personal !== null) {
            $removed['photos'] = $this->deleteStudentPhotos($personal);
        }

        DB::transaction(function () use ($user, $personal, &$removed): void {
            if ($personal !== null) {
                // EXPLICITLY SCOPED, and it has to be. StudentIdentity does NOT
                // use BelongsToOrganization — it carries an `organization_id`
                // column but no global scope, because it is always reached
                // through the student it belongs to. So `runFor()` around
                // `StudentIdentity::query()->delete()` scopes nothing at all and
                // empties the table for every organization on the platform.
                // That is not a hypothetical: the isolation test in
                // ExecuteAccountClosuresTest caught exactly this, one closure
                // taking a stranger's students with it.
                $removed['student_identities'] = StudentIdentity::query()
                    ->where('organization_id', $personal->getKey())
                    ->delete();

                $removed['organization_identities'] = DB::table('organization_identities')
                    ->where('organization_id', $personal->getKey())
                    ->delete();

                // The workspace keeps existing (40 RESTRICT keys point at it),
                // but stops naming anybody.
                $personal->forceFill(['name' => __('Organização encerrada')])->save();
            }

            $removed['passkeys'] = DB::table('passkeys')->where('user_id', $user->getKey())->delete();
            $removed['sessions'] = DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $this->stripIdentity($user);
        });

        // After the writes, so a failure above leaves no event claiming success.
        // No name, no email, no student data — counts and an id, which is what
        // an audit trail needs to show this ran without keeping what it removed.
        $this->recordExecution($user, $personal, $removed);

        return $removed;
    }

    /**
     * Everything on `users` that identifies a person, plus everything that
     * could still authenticate as one.
     *
     * An anonymised account that can still log in is not anonymised, it is
     * disguised. The password is overwritten with a value that is not a valid
     * hash, so `Hash::check()` cannot match it for any input rather than merely
     * being unlikely to; `deactivated_at` then makes `EnsureUserIsActive` refuse
     * the session even if some future flow skipped the password entirely.
     */
    protected function stripIdentity(User $user): void
    {
        $user->forceFill([
            'name' => __('Conta encerrada'),
            // Unique index, so it needs a value that cannot collide and cannot
            // be walked back to the original.
            'email' => 'anonimizado-'.Str::lower(Str::ulid()).'@invalido.local',
            'email_verified_at' => null,
            'password' => 'anonymised-no-login-'.Str::random(32),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'deactivated_at' => now(),
            'anonymized_at' => now(),
        ])->save();
    }

    /**
     * The photo files of a personal organization's students.
     *
     * Scoped by `organization_id` in the query itself, for the same reason the
     * delete above is: this model has no tenant scope of its own, so nothing
     * else would narrow it.
     */
    protected function deleteStudentPhotos(Organization $personal): int
    {
        $paths = StudentIdentity::query()
            ->where('organization_id', $personal->getKey())
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->all();

        $deleted = 0;

        foreach ($paths as $path) {
            // A file already gone is not a failure — this must be safe to
            // re-run. A file that refuses to go is logged and does not abort
            // the closure: leaving the account identifiable because one photo
            // was locked would be the worse outcome.
            try {
                if (Storage::disk(self::PHOTO_DISK)->delete($path)) {
                    $deleted++;
                }
            } catch (\Throwable $exception) {
                Log::warning('Foto de aluno não pôde ser removida no encerramento da conta.', [
                    'path' => $path,
                    'organization_id' => $personal->getKey(),
                ]);
            }
        }

        return $deleted;
    }

    /**
     * @param  array<string, int>  $removed
     */
    protected function recordExecution(User $user, ?Organization $personal, array $removed): void
    {
        $write = fn () => $this->audit->record(
            event: 'account.closure_executed',
            subject: $user,
            // No causer: nobody did this, a schedule did. Attributing it to the
            // person being anonymised would be both false and a way of keeping
            // their id in one more place.
            causer: null,
            summary: __('Encerramento executado: identidade removida e credenciais revogadas.'),
            properties: $removed,
        );

        if ($personal !== null) {
            $this->currentOrganization->runFor($personal, $write);

            return;
        }

        $write();
    }
}
