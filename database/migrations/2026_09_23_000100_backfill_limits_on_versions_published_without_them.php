<?php

use App\Models\PlanVersion;
use App\Support\Limits\LimitKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the version-1 rows that `2026_09_11_000100` published with no
 * `limits` at all.
 *
 * WHAT WENT WRONG. That migration froze each plan's composition into version 1
 * by copying `plans.limits` verbatim — correct, except that in an installation
 * where the column was still `NULL` (limits reached `EntitlementsSeeder` after
 * the version-1 backfill had already run) it froze a version that carries no
 * caps whatsoever. `Limits::parse()` treats a missing key as a configuration
 * error and throws, deliberately, rather than inventing a cap; so every
 * organization whose subscription is pinned to such a version gets a 500 from
 * every write that counts towards a limit — creating a class, enrolling a
 * student, and everything downstream of them. An account created before the
 * limits release simply stopped working, with an exception nobody outside the
 * log could read.
 *
 * WHY FILL THE LIMITS RATHER THAN MOVE THE SUBSCRIPTION. ADR-0008 makes a
 * published version immutable, and moving a subscription to the newest version
 * of its plan would honour that letter while breaking its purpose: it would
 * silently hand the organization the module composition of a version it never
 * contracted. Filling an ABSENT cap changes no access — there were no terms to
 * preserve, only a hole where the caps had not been invented yet. That reading
 * is what makes this migration legitimate, and it is why it refuses to touch a
 * version that already states a cap.
 *
 * WHERE THE VALUES COME FROM. The earliest version OF THE SAME PLAN that does
 * state the key — the caps as first published for that plan, not today's. No
 * constant is duplicated from the seeder, so this cannot drift away from it,
 * and a plan whose every version lacks the key is left exactly as it is: that
 * is a seeding problem, and `Limits` should go on saying so loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->orderBy('id')->pluck('id') as $planId) {
            $this->repairPlan((int) $planId);
        }
    }

    /**
     * Irreversible on purpose. `down()` would have to know which keys were
     * absent before, and putting a version back into a state that throws on
     * every read is not a state worth being able to return to.
     */
    public function down(): void {}

    protected function repairPlan(int $planId): void
    {
        $versions = DB::table('plan_versions')
            ->where('plan_id', $planId)
            ->orderBy('version')
            ->get();

        foreach ($versions as $version) {
            /** @var array<string, mixed> $limits */
            $limits = $version->limits === null
                ? []
                : (array) json_decode((string) $version->limits, true);

            $filled = $limits;

            foreach (LimitKey::cases() as $key) {
                if (array_key_exists($key->value, $filled)) {
                    continue;
                }

                $inherited = $this->firstStatedValue($versions, $key);

                if ($inherited === null) {
                    continue;
                }

                $filled[$key->value] = $inherited;
            }

            if ($filled === $limits) {
                continue;
            }

            DB::table('plan_versions')->where('id', $version->id)->update([
                'limits' => json_encode($filled),
                // The stored hash has to keep telling the truth about the row,
                // or the seeder's «has the composition changed?» comparison
                // publishes a spurious new version on its next run.
                'composition_hash' => PlanVersion::compositionHash(
                    $this->moduleKeysOf((int) $version->id),
                    $filled,
                ),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The value this plan first published for $key, or null when no version of
     * it ever stated one.
     *
     * @param  Collection<int, stdClass>  $versions  ordered by version
     */
    protected function firstStatedValue(Collection $versions, LimitKey $key): string|int|null
    {
        foreach ($versions as $version) {
            if ($version->limits === null) {
                continue;
            }

            /** @var array<string, mixed> $limits */
            $limits = (array) json_decode((string) $version->limits, true);

            if (array_key_exists($key->value, $limits)) {
                $value = $limits[$key->value];

                return is_int($value) || is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function moduleKeysOf(int $planVersionId): array
    {
        /** @var list<string> $keys */
        $keys = DB::table('module_plan_version')
            ->join('modules', 'modules.id', '=', 'module_plan_version.module_id')
            ->where('module_plan_version.plan_version_id', $planVersionId)
            ->orderBy('modules.id')
            ->pluck('modules.key')
            ->all();

        return $keys;
    }
};
