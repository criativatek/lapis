export type DraftCell = {
    state: string;
    points: number | null;
    reason: string | null;
};

export type GridDraftScope = {
    organizationUlid: string;
    userId: number;
    instrumentUlid: string;
};

type StructuralItem = {
    id: number;
    points_possible: number | null;
    is_bonus: boolean;
    domains: Array<{
        domain_id: number;
        percent: number;
    }>;
};

type GridDraft = {
    cells: Record<string, DraftCell>;
    savedAt: string;
    structuralFingerprint: string;
};

const WRITE_DELAY_MS = 400;

export function computeStructuralFingerprint(items: StructuralItem[]): string {
    return JSON.stringify(
        items
            .map((item) => ({
                id: item.id,
                points_possible: item.points_possible,
                is_bonus: item.is_bonus,
                domains: item.domains
                    .map((domain) => ({
                        domain_id: domain.domain_id,
                        percent: domain.percent,
                    }))
                    .sort(
                        (first, second) => first.domain_id - second.domain_id,
                    ),
            }))
            .sort((first, second) => first.id - second.id),
    );
}

export function useGridDraft(
    scope: GridDraftScope,
    currentFingerprint: string,
) {
    const storageKey = [
        'lapis:grid-draft',
        scope.organizationUlid,
        scope.userId,
        scope.instrumentUlid,
    ].join(':');

    let pendingCells: Record<string, DraftCell> | null = null;
    let writeTimer: ReturnType<typeof setTimeout> | null = null;

    function removeStoredDraft(): void {
        try {
            localStorage.removeItem(storageKey);
        } catch {
            // Browser storage is optional and may be unavailable or blocked.
        }
    }

    function isDraftCell(value: unknown): value is DraftCell {
        if (typeof value !== 'object' || value === null) {
            return false;
        }

        const cell = value as Partial<DraftCell>;

        return (
            typeof cell.state === 'string' &&
            (typeof cell.points === 'number' || cell.points === null) &&
            (typeof cell.reason === 'string' || cell.reason === null)
        );
    }

    function isGridDraft(value: unknown): value is GridDraft {
        if (typeof value !== 'object' || value === null) {
            return false;
        }

        const draft = value as Partial<GridDraft>;

        return (
            typeof draft.savedAt === 'string' &&
            typeof draft.structuralFingerprint === 'string' &&
            typeof draft.cells === 'object' &&
            draft.cells !== null &&
            Object.values(draft.cells).every(isDraftCell)
        );
    }

    function persistPendingDraft(): void {
        if (pendingCells === null) {
            return;
        }

        const draft: GridDraft = {
            cells: pendingCells,
            savedAt: new Date().toISOString(),
            structuralFingerprint: currentFingerprint,
        };

        pendingCells = null;

        try {
            localStorage.setItem(storageKey, JSON.stringify(draft));
        } catch {
            // A local draft must never prevent the grading grid from working.
        }
    }

    function writeDraft(cells: Record<string, DraftCell>): void {
        pendingCells = Object.fromEntries(
            Object.entries(cells).map(([key, cell]) => [
                key,
                {
                    state: cell.state,
                    points: cell.points,
                    reason: cell.reason,
                },
            ]),
        );

        if (writeTimer !== null) {
            clearTimeout(writeTimer);
        }

        writeTimer = setTimeout(() => {
            writeTimer = null;
            persistPendingDraft();
        }, WRITE_DELAY_MS);
    }

    function flush(): void {
        if (writeTimer !== null) {
            clearTimeout(writeTimer);
            writeTimer = null;
        }

        persistPendingDraft();
    }

    function readDraft(): GridDraft | null {
        try {
            const storedDraft = localStorage.getItem(storageKey);

            if (storedDraft === null) {
                return null;
            }

            const parsedDraft: unknown = JSON.parse(storedDraft);

            if (!isGridDraft(parsedDraft)) {
                removeStoredDraft();

                return null;
            }

            return parsedDraft;
        } catch {
            removeStoredDraft();

            return null;
        }
    }

    function isCompatible(): boolean {
        return readDraft()?.structuralFingerprint === currentFingerprint;
    }

    function reconcileSaved(savedKeys: string[]): void {
        flush();

        const draft = readDraft();

        if (draft === null) {
            return;
        }

        for (const savedKey of savedKeys) {
            delete draft.cells[savedKey];
        }

        if (Object.keys(draft.cells).length === 0) {
            removeStoredDraft();

            return;
        }

        try {
            localStorage.setItem(storageKey, JSON.stringify(draft));
        } catch {
            // A failed reconciliation must not interrupt a successful server save.
        }
    }

    function clearDraft(): void {
        if (writeTimer !== null) {
            clearTimeout(writeTimer);
            writeTimer = null;
        }

        pendingCells = null;
        removeStoredDraft();
    }

    return {
        writeDraft,
        flush,
        readDraft,
        isCompatible,
        reconcileSaved,
        clearDraft,
    };
}
