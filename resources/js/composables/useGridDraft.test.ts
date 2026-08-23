import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

import { computeStructuralFingerprint, useGridDraft } from './useGridDraft';
import type { DraftCell, GridDraftScope } from './useGridDraft';

const scope: GridDraftScope = {
    organizationUlid: '01ORG',
    userId: 17,
    instrumentUlid: '01INSTRUMENT',
};

const fingerprintItems = [
    {
        id: 2,
        points_possible: 10,
        is_bonus: false,
        domains: [
            { domain_id: 20, percent: 40 },
            { domain_id: 10, percent: 60 },
        ],
    },
    {
        id: 1,
        points_possible: 5,
        is_bonus: true,
        domains: [{ domain_id: 10, percent: 100 }],
    },
];

const currentFingerprint = computeStructuralFingerprint(fingerprintItems);
const pendingCell: DraftCell = {
    state: 'pending',
    points: null,
    reason: null,
};
const assessedZeroCell: DraftCell = {
    state: 'assessed',
    points: 0,
    reason: 'Sem respostas corretas',
};

describe('useGridDraft', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('writes a draft to localStorage after the debounce interval', () => {
        const draft = useGridDraft(scope, currentFingerprint);

        draft.writeDraft({ '11:2': assessedZeroCell });

        expect(localStorage.length).toBe(0);

        vi.advanceTimersByTime(400);

        expect(localStorage.length).toBe(1);
    });

    it('reads exactly the cells from an existing draft', () => {
        const writer = useGridDraft(scope, currentFingerprint);
        const cells = {
            '11:1': pendingCell,
            '11:2': assessedZeroCell,
        };

        writer.writeDraft(cells);
        writer.flush();

        expect(
            useGridDraft(scope, currentFingerprint).readDraft()?.cells,
        ).toEqual(cells);
    });

    it('accepts reactive Vue cells without persisting their proxy', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        const cells = reactive({ '11:1': pendingCell });

        draft.writeDraft(cells);
        draft.flush();

        expect(draft.readDraft()?.cells).toEqual({ '11:1': pendingCell });
    });

    it('does not read a draft from another organization', () => {
        const writer = useGridDraft(scope, currentFingerprint);
        writer.writeDraft({ '11:1': pendingCell });
        writer.flush();

        expect(
            useGridDraft(
                { ...scope, organizationUlid: '01OTHERORG' },
                currentFingerprint,
            ).readDraft(),
        ).toBeNull();
    });

    it('does not read a draft from another user', () => {
        const writer = useGridDraft(scope, currentFingerprint);
        writer.writeDraft({ '11:1': pendingCell });
        writer.flush();

        expect(
            useGridDraft(
                { ...scope, userId: 99 },
                currentFingerprint,
            ).readDraft(),
        ).toBeNull();
    });

    it('does not read a draft from another instrument', () => {
        const writer = useGridDraft(scope, currentFingerprint);
        writer.writeDraft({ '11:1': pendingCell });
        writer.flush();

        expect(
            useGridDraft(
                { ...scope, instrumentUlid: '01OTHERINSTRUMENT' },
                currentFingerprint,
            ).readDraft(),
        ).toBeNull();
    });

    it('is compatible when the structural fingerprint matches', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({ '11:1': pendingCell });
        draft.flush();

        expect(draft.isCompatible()).toBe(true);
    });

    it.each([
        {
            change: 'the item points',
            items: fingerprintItems.map((item) =>
                item.id === 2 ? { ...item, points_possible: 20 } : item,
            ),
        },
        {
            change: 'a domain allocation',
            items: fingerprintItems.map((item) =>
                item.id === 2
                    ? {
                          ...item,
                          domains: item.domains.map((domain) =>
                              domain.domain_id === 20
                                  ? { ...domain, percent: 50 }
                                  : domain,
                          ),
                      }
                    : item,
            ),
        },
        {
            change: 'the number of items',
            items: fingerprintItems.slice(0, 1),
        },
    ])(
        'is incompatible when $change changes',
        ({ items }: { items: typeof fingerprintItems }) => {
            const writer = useGridDraft(scope, currentFingerprint);
            writer.writeDraft({ '11:1': pendingCell });
            writer.flush();

            expect(
                useGridDraft(
                    scope,
                    computeStructuralFingerprint(items),
                ).isCompatible(),
            ).toBe(false);
        },
    );

    it('computes the same fingerprint regardless of item order', () => {
        expect(computeStructuralFingerprint(fingerprintItems)).toBe(
            computeStructuralFingerprint([...fingerprintItems].reverse()),
        );
    });

    it('computes the same fingerprint regardless of domain order', () => {
        const reorderedDomains = fingerprintItems.map((item) => ({
            ...item,
            domains: [...item.domains].reverse(),
        }));

        expect(computeStructuralFingerprint(fingerprintItems)).toBe(
            computeStructuralFingerprint(reorderedDomains),
        );
    });

    it('reconciles only the saved cell keys', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({
            '11:1': pendingCell,
            '11:2': assessedZeroCell,
        });
        draft.flush();

        draft.reconcileSaved(['11:1']);

        expect(draft.readDraft()?.cells).toEqual({ '11:2': assessedZeroCell });
    });

    it('removes the whole draft when the last cell is reconciled', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({ '11:1': pendingCell });
        draft.flush();

        draft.reconcileSaved(['11:1']);

        expect(draft.readDraft()).toBeNull();
        expect(localStorage.length).toBe(0);
    });

    it('clears a draft unconditionally, including a pending write', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({ '11:1': pendingCell });

        draft.clearDraft();
        vi.advanceTimersByTime(400);

        expect(draft.readDraft()).toBeNull();
        expect(localStorage.length).toBe(0);
    });

    it('preserves pending as points null after writing and reading', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({ '11:1': pendingCell });
        draft.flush();

        expect(draft.readDraft()?.cells['11:1']).toEqual(pendingCell);
        expect(draft.readDraft()?.cells['11:1'].points).toBeNull();
    });

    it('preserves an assessed zero as zero after writing and reading', () => {
        const draft = useGridDraft(scope, currentFingerprint);
        draft.writeDraft({ '11:2': assessedZeroCell });
        draft.flush();

        expect(draft.readDraft()?.cells['11:2']).toEqual(assessedZeroCell);
        expect(draft.readDraft()?.cells['11:2'].points).toBe(0);
    });

    it('returns null without throwing and removes corrupted JSON', () => {
        localStorage.setItem(
            'lapis:grid-draft:01ORG:17:01INSTRUMENT',
            '{not-json',
        );
        const draft = useGridDraft(scope, currentFingerprint);

        expect(() => draft.readDraft()).not.toThrow();
        expect(draft.readDraft()).toBeNull();
        expect(localStorage.length).toBe(0);
    });

    it('does not throw when localStorage rejects a write', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('Quota exceeded', 'QuotaExceededError');
        });
        const draft = useGridDraft(scope, currentFingerprint);

        draft.writeDraft({ '11:1': pendingCell });

        expect(() => draft.flush()).not.toThrow();
    });
});
