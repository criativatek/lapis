<?php

use App\Models\InterimAssessment;
use App\Models\Report;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-hash the rows written before hashing became canonical (0.117.0).
 *
 * WHAT WAS WRONG. `InterimAssessment` and `Report` used to hash a naive
 * `json_encode` of the payload as it was written. MySQL's JSON column type
 * reorders object keys on storage, so on the engine production runs the
 * reread payload never re-encoded to the stored hash: every untouched row
 * reported itself as tampered with. 0.117.0 fixed the ALGORITHM
 * (`CanonicalPayload`) but left the stored hashes as they were — new rows
 * verify, old rows keep failing forever. A false integrity alarm is the worst
 * kind: it teaches whoever reads it to ignore the true one.
 *
 * WHAT THIS DOES. Recomputes each stored hash canonically from the payload
 * the row holds NOW, in chunks, bypassing the tenancy scope the way data
 * repairs do (a migration runs for every organization at once, and the global
 * scope would rightly throw).
 *
 * THE TRUST DECISION, STATED PLAINLY: the old hash could not tell a reordered
 * key from real tampering — on MySQL it failed for BOTH. This migration
 * declares the currently stored payload as the new baseline. That is the only
 * honest option available: there is no second copy to compare against, and
 * keeping the alarm ringing for every row protects nobody.
 *
 * `calculation_snapshots` is deliberately NOT touched: its hashing was
 * canonical from the start (0.117.0 merely moved that code), so its stored
 * hashes already verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('interim_assessments')
            ->select(['id', 'snapshot'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $snapshot = json_decode((string) $row->snapshot, true);

                    if (! is_array($snapshot)) {
                        continue;
                    }

                    DB::table('interim_assessments')
                        ->where('id', $row->id)
                        ->update(['snapshot_hash' => InterimAssessment::hashFor($snapshot)]);
                }
            });

        DB::table('reports')
            ->select(['id', 'document'])
            ->whereNotNull('document_hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $document = json_decode((string) $row->document, true);

                    if (! is_array($document)) {
                        continue;
                    }

                    DB::table('reports')
                        ->where('id', $row->id)
                        ->update(['document_hash' => Report::hashFor($document)]);
                }
            });
    }

    /**
     * Intencionalmente vazio: os hashes antigos eram inverificáveis no motor
     * de produção — repô-los seria repor o alarme falso, não um estado válido.
     */
    public function down(): void
    {
        //
    }
};
