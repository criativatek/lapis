/**
 * Regista QUANDO uma página foi reposta do histórico do browser.
 *
 * O Inertia 3 trata o botão «Atrás» sem perguntar ao servidor: o `popstate`
 * repõe as props guardadas em `history.state`. O bfcache repõe a página inteira
 * (`pageshow` com `persisted`). Em ambos os casos os dados podem estar
 * desatualizados por muito pouco tempo (uma tarefa concluída há 1 s), pelo que a
 * idade das props não serve de critério: o critério certo é «foi reposta».
 *
 * O registo arranca no ponto de entrada da aplicação (`app.ts`), antes de
 * qualquer página: o chunk de uma página pode só ser carregado depois do
 * `popstate` (F5 numa tarefa e «Atrás» para o painel). Quem monta consome o
 * carimbo (`consumeRestoration`), que só vale UMA vez por reposição.
 */

/**
 * Janela por omissão entre o `popstate` e a montagem da página. Tem de cobrir o
 * descarregamento do chunk da página em redes lentas. Um falso positivo custa um
 * pedido parcial; um falso negativo mostra números antigos: erra-se para o lado
 * barato.
 */
export const RESTORATION_WINDOW_MS = 4_000;

let restoredAt: number | null = null;
let started = false;
const persistedListeners = new Set<() => void>();

function now(): number {
    return typeof performance !== 'undefined' ? performance.now() : Date.now();
}

function mark(): void {
    restoredAt = now();
}

function onPageShow(event: PageTransitionEvent): void {
    if (!event.persisted) {
        return;
    }

    mark();
    persistedListeners.forEach((listener) => listener());
}

/** Liga os ouvintes (idempotente). Sem `window` (SSR, testes de nó) não faz nada. */
export function startHistoryRestorationTracking(): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    window.addEventListener('popstate', mark);
    window.addEventListener('pageshow', onPageShow);
}

/** Houve uma reposição há menos de `windowMs`? Não consome o carimbo. */
export function restoredRecently(windowMs: number = RESTORATION_WINDOW_MS): boolean {
    return restoredAt !== null && now() - restoredAt <= windowMs;
}

/** Como `restoredRecently`, mas gasta o carimbo: cada reposição vale uma só vez. */
export function consumeRestoration(windowMs: number = RESTORATION_WINDOW_MS): boolean {
    const recent = restoredRecently(windowMs);
    restoredAt = null;

    return recent;
}

/**
 * Avisa quando o bfcache repõe a página JÁ montada (nada volta a montar; sem
 * isto o painel só se actualizaria pela regra da idade). Devolve a função que
 * cancela a subscrição. NÃO avisa em `popstate`: nesse momento a página ainda
 * é a antiga e quem a substitui monta logo a seguir.
 */
export function onPersistedRestore(listener: () => void): () => void {
    persistedListeners.add(listener);

    return () => {
        persistedListeners.delete(listener);
    };
}

/** Só para testes: volta ao estado inicial e retira os ouvintes. */
export function resetHistoryRestorationForTests(): void {
    if (started && typeof window !== 'undefined') {
        window.removeEventListener('popstate', mark);
        window.removeEventListener('pageshow', onPageShow);
    }

    started = false;
    restoredAt = null;
    persistedListeners.clear();
}
