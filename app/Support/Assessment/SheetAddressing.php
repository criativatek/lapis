<?php

namespace App\Support\Assessment;

use App\Models\ClassificationStatus;
use App\Models\Domain;
use App\Models\SchoolClass;

/**
 * The address a decision is written to, added to a live Pauta de Avaliação.
 *
 * DELIBERATELY NOT PART OF THE READ MODEL. `BuildEvaluationSheet` describes
 * what was assessed; an enrolment ulid is not that — it is where a form posts
 * to. Putting it in the builder would freeze it into every snapshot
 * `CaptureEvaluationSheet` takes, and a kept pauta is a document about what was
 * decided, not a set of addresses to go on writing to. The same reasoning that
 * keeps colour out of the builder and in `DomainColorPalette` keeps this here.
 *
 * WHETHER THE ROW MAY STILL BE WRITTEN TO IS ANSWERED HERE TOO, and by the
 * enum that owns the question rather than by a browser re-deriving it from a
 * status string. It belongs beside the address for the same reason: both are
 * statements about the LIVE row, and neither is true of a photograph. A
 * snapshot that claimed a decision was still changeable would be claiming
 * something about today, which is precisely what a snapshot must never do.
 *
 * ONE FLAT QUERY. The rows are matched by the internal id the read model
 * already carries, so a class of thirty students still costs one lookup and
 * never one per row (§24).
 */
final class SheetAddressing
{
    /**
     * The address a DOMAIN's appreciation is written to.
     *
     * Here for the same reason the enrolment's is: `domain_id` is the internal
     * identity the read model already carries, and a ulid is what a URL may
     * name (§11.2). A snapshot carries neither — a photograph is not somewhere
     * to go on writing to.
     *
     * @param  list<array<string, mixed>>  $domains
     * @return list<array<string, mixed>>
     */
    public static function decorateDomains(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        /** @var array<int, string> $ulids */
        $ulids = Domain::query()
            ->whereIn('id', array_column($domains, 'domain_id'))
            ->pluck('ulid', 'id')
            ->all();

        return array_map(
            static fn (array $domain): array => [
                ...$domain,
                'domain_ulid' => $ulids[(int) $domain['domain_id']] ?? null,
            ],
            $domains,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $students, SchoolClass $class): array
    {
        if ($students === []) {
            return [];
        }

        /** @var array<int, string> $ulids */
        $ulids = $class->enrollments()
            ->whereIn('id', array_map(static fn (array $student): int => (int) $student['enrollment_id'], $students))
            ->pluck('ulid', 'id')
            ->all();

        return array_map(
            static function (array $student) use ($ulids): array {
                // Null rather than absent when the enrolment cannot be reached
                // — a row that cannot be addressed shows no action, and the
                // screen says so instead of posting somewhere it invented.
                $student['enrollment_ulid'] = $ulids[(int) $student['enrollment_id']] ?? null;

                // NO ROW IS NOT NO PERMISSION. A period whose proposals were
                // never generated has nothing stored yet, and the teacher may
                // still have a classification to assign — OpenClassification
                // opens the row when they do. What closes the door is
                // publication, and only that (§7.1).
                $canDecide = true;

                // «Usar proposta» needs a proposal to adopt, and needs the row
                // to be a proposal still: a confirmed decision is revised, not
                // re-confirmed.
                $canUseProposal = false;

                /** @var array<string, mixed>|null $classification */
                $classification = $student['classification'] ?? null;

                if ($classification !== null) {
                    $status = ClassificationStatus::tryFrom((string) $classification['status']);

                    // A status this application does not recognise is not one to
                    // write over. Refusing is the truthful answer (§10.4).
                    $canDecide = $status?->allowsDecision() ?? false;
                    $canUseProposal = $status === ClassificationStatus::Proposed
                        && ($classification['proposed_scale_level_id'] !== null || $classification['proposed_value'] !== null);
                }

                $student['can_decide'] = $canDecide;
                $student['can_use_proposal'] = $canUseProposal;

                return $student;
            },
            $students,
        );
    }
}
