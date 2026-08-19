<?php

namespace App\Domain\Reporting;

use App\Models\ReportType;
use App\Services\Reporting\ReportCapabilities;

/**
 * WHICH SECTIONS A REPORT HAS, IN WHAT ORDER, AND WHICH OF THEM PRINT.
 *
 * The one shape that both defaults and templates produce, so that
 * CreateReport has a single thing to build rows from. Before this existed the
 * order was implicitly the catalogue's, which is fine until a template wants a
 * different one.
 *
 * IT IS KEYED, NEVER INDEXED (§8). A plan is a list of section KEYS with their
 * positions; nothing anywhere depends on the position of an entry in an array.
 * A key the catalogue no longer has is dropped when the plan is read, and a key
 * the catalogue has gained is appended — so a template written last September
 * still produces a coherent report today, with the new section at the end
 * rather than missing.
 *
 * THE PLAN NEVER GRANTS ANYTHING (§23). It is filtered through
 * ReportCapabilities on the way in: a template listing a Pro section produces a
 * plan without it on a Base organization. A template is a preference, not a
 * permission.
 */
readonly class SectionPlan
{
    /**
     * @param  list<array{key: SectionKey, included: bool}>  $entries  In order.
     */
    public function __construct(public array $entries) {}

    /**
     * The plan a report of this type starts with when nobody chose anything:
     * catalogue order, catalogue defaults.
     */
    public static function defaultFor(ReportType $type, ReportCapabilities $capabilities): self
    {
        $entries = [];

        foreach ($capabilities->sectionsFor($type) as $definition) {
            $entries[] = ['key' => $definition->key, 'included' => $definition->defaultIncluded];
        }

        return new self($entries);
    }

    /**
     * A plan from an explicit list of keys to include — the shape the creation
     * form posts. Order stays the catalogue's; the teacher reorders afterwards,
     * in the editor, where they can see what they are moving.
     *
     * @param  list<string>  $wanted
     */
    public static function fromChosenKeys(ReportType $type, ReportCapabilities $capabilities, array $wanted): self
    {
        $entries = [];

        foreach ($capabilities->sectionsFor($type) as $definition) {
            $entries[] = [
                'key' => $definition->key,
                'included' => in_array($definition->key->value, $wanted, strict: true),
            ];
        }

        return new self($entries);
    }

    /**
     * A plan from a template's stored settings.
     *
     * THREE THINGS HAPPEN HERE and each one is a decision:
     *
     *  - a key the template lists that this type or this plan does not have is
     *    DROPPED, because a template cannot grant a section (§23);
     *  - a key the catalogue has and the template does not is APPENDED, off by
     *    default unless the catalogue says otherwise — a template written
     *    before a section existed should not silently suppress it;
     *  - order comes from the template's `position`, and ties fall back to
     *    catalogue order so the result is deterministic.
     *
     * @param  array<mixed>  $sections  Straight out of a JSON column, so shaped
     *                                  by whoever wrote it last.
     */
    public static function fromTemplate(ReportType $type, ReportCapabilities $capabilities, array $sections): self
    {
        $allowed = [];

        foreach ($capabilities->sectionsFor($type) as $order => $definition) {
            $allowed[$definition->key->value] = ['definition' => $definition, 'catalogue' => $order];
        }

        $planned = [];

        foreach ($sections as $section) {
            $key = is_array($section) && is_string($section['key'] ?? null) ? $section['key'] : null;

            if ($key === null || ! isset($allowed[$key])) {
                continue;
            }

            $planned[$key] = [
                'key' => $allowed[$key]['definition']->key,
                'included' => (bool) ($section['included'] ?? true),
                'position' => (int) ($section['position'] ?? 0),
                'catalogue' => $allowed[$key]['catalogue'],
            ];
        }

        // Sections the catalogue has gained since the template was written.
        foreach ($allowed as $key => $entry) {
            if (isset($planned[$key])) {
                continue;
            }

            $planned[$key] = [
                'key' => $entry['definition']->key,
                'included' => $entry['definition']->defaultIncluded,
                // After everything the template ordered.
                'position' => PHP_INT_MAX,
                'catalogue' => $entry['catalogue'],
            ];
        }

        $rows = array_values($planned);

        usort($rows, fn (array $a, array $b) => [$a['position'], $a['catalogue']] <=> [$b['position'], $b['catalogue']]);

        return new self(array_map(
            fn (array $row): array => ['key' => $row['key'], 'included' => $row['included']],
            $rows,
        ));
    }

    /**
     * The plan a draft currently has, for saving it back as a template (§18).
     *
     * @param  list<array{key: string, included: bool}>  $sections  In order.
     */
    public static function fromSections(ReportType $type, ReportCapabilities $capabilities, array $sections): self
    {
        return self::fromTemplate($type, $capabilities, array_map(
            fn (array $section, int $index): array => [
                'key' => $section['key'],
                'included' => $section['included'],
                'position' => ($index + 1) * 10,
            ],
            $sections,
            array_keys($sections),
        ));
    }

    /**
     * The same order, with inclusion decided by an explicit list.
     *
     * WHERE A TEMPLATE AND A CHECKLIST MEET. The creation screen always posts
     * which sections are ticked, and a template always brings an order; taking
     * the whole plan from either one would throw the other away. The template
     * arranges, the teacher chooses — which is what each of them is for.
     *
     * @param  list<string>  $included
     */
    public function withInclusion(array $included): self
    {
        return new self(array_map(
            fn (array $entry): array => [
                'key' => $entry['key'],
                'included' => in_array($entry['key']->value, $included, strict: true),
            ],
            $this->entries,
        ));
    }

    /**
     * The storable shape: keys, inclusion and explicit positions.
     *
     * Positions are rewritten in tens on the way out, so a plan that came from
     * a hand-edited template or from a reordered draft is stored normalised.
     *
     * @return list<array{key: string, included: bool, position: int}>
     */
    public function toArray(): array
    {
        $position = 0;

        return array_map(function (array $entry) use (&$position): array {
            $position += 10;

            return [
                'key' => $entry['key']->value,
                'included' => $entry['included'],
                'position' => $position,
            ];
        }, $this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
