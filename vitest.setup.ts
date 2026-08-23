// Node 22+ ships its own native `localStorage` global, gated behind
// `--localstorage-file` (unconfigured here, so every access just warns and
// returns nothing usable) — and it shadows jsdom's own implementation on
// this Node/jsdom/Vitest combination, since `globalThis` and the jsdom
// `window` are the same object in this environment (aliasing `window.*`
// back onto itself only recurses). Replace it outright with a small,
// deterministic, spec-shaped in-memory Storage — this is test
// infrastructure only, real browser code always gets the real thing.
class MemoryStorage implements Storage {
    private store = new Map<string, string>();

    get length(): number {
        return this.store.size;
    }

    clear(): void {
        this.store.clear();
    }

    getItem(key: string): string | null {
        return this.store.has(key) ? this.store.get(key)! : null;
    }

    key(index: number): string | null {
        return [...this.store.keys()][index] ?? null;
    }

    removeItem(key: string): void {
        this.store.delete(key);
    }

    setItem(key: string, value: string): void {
        this.store.set(key, String(value));
    }
}

// Exposed as the global `Storage` class too, and not just instantiated
// privately: a test that does `vi.spyOn(Storage.prototype, 'setItem')` to
// simulate a quota/private-mode failure needs that prototype to be the one
// these instances actually use, or the spy silently mocks nothing.
(globalThis as { Storage: typeof MemoryStorage }).Storage = MemoryStorage;

Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: new MemoryStorage(),
});
Object.defineProperty(globalThis, 'sessionStorage', {
    configurable: true,
    value: new MemoryStorage(),
});
