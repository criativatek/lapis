<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\ItemDomainAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives the questions of a grid the domain they are missing, one domain per
 * section — the step that turns a grid full of marks into results.
 *
 * The engine only reads a question through its domain allocation
 * (CalculationEngine): a question with none is skipped in silence, and a grid
 * where every question is like that shows «Por classificar» on every row with
 * every mark filled in. An imported grid can arrive exactly like that — the
 * Intuitivo wizard asks for no domain in the question-by-question mode — and
 * what the teacher then needs is not to type seventeen marks again but to say,
 * once, «GRUPO II is Leitura».
 *
 * Nothing here touches a mark, a cotação or an allocation that already exists:
 * only questions WITHOUT any allocation receive one, at 100 %, in a domain of
 * the class's active profile. Everything downstream (official classification,
 * Resultados, the class follow-up) is computed live and simply starts seeing
 * the questions.
 */
class AssignGroupDomains
{
    public function __construct(protected InstrumentBuilder $builder) {}

    /**
     * What the grid needs to say about its missing domains, and what it can
     * offer to fix them.
     *
     * @return array{
     *     needed: bool,
     *     can_assign: bool,
     *     items_without_domain: int,
     *     groups: list<array{ulid: string, label: string|null, items: int, items_without_domain: int, suggested_domain_id: int|null}>,
     *     domains: list<array{id: int, name: string}>
     * }
     */
    public function state(Instrument $instrument): array
    {
        $instrument->loadMissing(['items.domainAllocations', 'groups', 'schoolClass.profileVersion']);

        $domains = array_values($this->builder->domainsFor($instrument->schoolClass)->all());
        $groups = [];
        $without = 0;

        foreach ($instrument->groups->sortBy('sequence')->values() as $group) {
            $items = $instrument->items->where('instrument_group_id', $group->id);
            $missing = $items->filter(fn (InstrumentItem $item): bool => $item->domainAllocations->isEmpty())->count();
            $without += $missing;

            $groups[] = [
                'ulid' => $group->ulid,
                'label' => $group->label,
                'items' => $items->count(),
                'items_without_domain' => $missing,
                'suggested_domain_id' => $this->suggest($group->label, $domains),
            ];
        }

        return [
            'needed' => $without > 0,
            'can_assign' => $domains !== [],
            'items_without_domain' => $without,
            'groups' => $groups,
            'domains' => array_map(fn (Domain $domain): array => ['id' => (int) $domain->getKey(), 'name' => $domain->name], $domains),
        ];
    }

    /**
     * Allocates, at 100 %, every question of each given section that has no
     * allocation yet. Questions that already have one are left exactly as they
     * are. Returns how many questions were allocated.
     *
     * @param  array<string, int>  $domainByGroupUlid  section ulid => domain id
     *
     * @throws ValidationException when a section is not this grid's or a domain is not in the class's profile
     */
    public function assign(Instrument $instrument, array $domainByGroupUlid): int
    {
        $instrument->loadMissing(['items.domainAllocations', 'groups', 'schoolClass.profileVersion']);

        $allowed = $this->builder->domainsFor($instrument->schoolClass)->pluck('id')->map(intval(...))->all();

        if ($allowed === []) {
            throw ValidationException::withMessages(['assignments' => 'Esta turma não tem um perfil de avaliação ativo — não há domínios a que associar as perguntas.']);
        }

        $groupsByUlid = $instrument->groups->keyBy('ulid');

        foreach ($domainByGroupUlid as $ulid => $domainId) {
            if (! $groupsByUlid->has($ulid)) {
                throw ValidationException::withMessages(['assignments' => 'O grupo selecionado não pertence a esta grelha de correção.']);
            }

            if (! in_array($domainId, $allowed, true)) {
                throw ValidationException::withMessages(['assignments' => 'O domínio selecionado não pertence ao perfil ativo da turma.']);
            }
        }

        return DB::transaction(function () use ($instrument, $domainByGroupUlid, $groupsByUlid): int {
            $created = 0;

            foreach ($domainByGroupUlid as $ulid => $domainId) {
                /** @var InstrumentGroup $group */
                $group = $groupsByUlid->get($ulid);

                foreach ($instrument->items->where('instrument_group_id', $group->id) as $item) {
                    if ($item->domainAllocations->isNotEmpty()) {
                        continue;
                    }

                    ItemDomainAllocation::create([
                        'instrument_item_id' => $item->getKey(),
                        'domain_id' => $domainId,
                        'allocation_percent' => '100',
                    ]);
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * The profile domain a section's own name points to, when it does —
     * «GRUPO II - Leitura» suggests Leitura. A suggestion the teacher confirms,
     * never a decision: a name that matches no domain, or more than one with
     * the same length, suggests nothing.
     *
     * @param  list<Domain>  $domains
     */
    protected function suggest(?string $label, array $domains): ?int
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        $haystack = $this->normalise($label);
        $best = null;
        $bestLength = 0;
        $tie = false;

        foreach ($domains as $domain) {
            $needle = $this->normalise($domain->name);

            if ($needle === '' || ! str_contains($haystack, $needle)) {
                continue;
            }

            $length = mb_strlen($needle);

            if ($length > $bestLength) {
                $best = (int) $domain->getKey();
                $bestLength = $length;
                $tie = false;
            } elseif ($length === $bestLength) {
                $tie = true;
            }
        }

        return $tie ? null : $best;
    }

    protected function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        if ($transliterated !== false) {
            $text = $transliterated;
        }

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $text)));
    }
}
