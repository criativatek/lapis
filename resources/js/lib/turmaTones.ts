/**
 * O SINAL DE TURMA — a cor que ajuda a distinguir turmas ao correr a semana
 * com os olhos, e que nunca é o que DIZ qual é a turma.
 *
 * O nome da turma está sempre escrito ao lado, em texto, em todos os sítios
 * onde este tom aparece: a página lê-se inteira num ecrã monocromático, e quem
 * não distingue estas cores não perde nada. A cor é reforço, e só.
 *
 * GUARDADA, E NÃO CALCULADA NO ECRÃ (0.158.0). O tom vive em
 * `class_teachers.identity_tone` — por professor e por turma —, é atribuído uma
 * vez quando o professor passa a ter a turma e nunca é recalculado. Acrescentar,
 * arquivar ou filtrar turmas não muda o tom de nenhuma, e a mesma turma tem o
 * mesmo tom em Aulas e Sumários, no Horário do Professor e em qualquer outra
 * página que o leia. Antes, o tom saía do conjunto de turmas visíveis na
 * página, e bastava uma turma nova (ou arquivada) para as outras mudarem de cor.
 *
 * `assignTurmaTones` continua aqui só como RECURSO para uma relação sem tom
 * guardado (uma linha escrita por um caminho que não passa pelo modelo da
 * relação): nesse caso o tom sai do conjunto, como antes, em vez de não haver
 * tom nenhum.
 */

export type TurmaTone =
    'blue' | 'emerald' | 'violet' | 'amber' | 'rose' | 'stone';

/**
 * Seis tons, por esta ordem — que é também a ordem de desempate do servidor
 * (`ClassIdentityTone`). Seis chegam para a semana de um professor sem que a
 * página vire um arco-íris, e param antes dos vermelhos e verdes fortes que,
 * noutras páginas do Lapispro, querem dizer «negativa» e «positiva».
 */
export const TURMA_TONES: readonly TurmaTone[] = [
    'blue',
    'emerald',
    'violet',
    'amber',
    'rose',
    'stone',
];

export function isTurmaTone(value: unknown): value is TurmaTone {
    return (
        typeof value === 'string' &&
        (TURMA_TONES as readonly string[]).includes(value)
    );
}

/**
 * Tons de recurso para turmas sem tom guardado, decididos sobre o conjunto de
 * `ulid`s visíveis (ordenados, para não dependerem da ordem de chegada).
 */
export function assignTurmaTones(
    ulids: readonly string[],
): Map<string, TurmaTone> {
    const assignment = new Map<string, TurmaTone>();

    [...new Set(ulids)].sort().forEach((ulid, index) => {
        assignment.set(
            ulid,
            TURMA_TONES[index % TURMA_TONES.length] as TurmaTone,
        );
    });

    return assignment;
}

/**
 * O tom de cada turma visível: o guardado quando existe; senão, o de recurso.
 */
export function resolveTurmaTones(
    classes: readonly { ulid: string; identity_tone?: string | null }[],
): Map<string, TurmaTone> {
    const fallback = assignTurmaTones(
        classes.map((schoolClass) => schoolClass.ulid),
    );
    const tones = new Map<string, TurmaTone>();

    for (const schoolClass of classes) {
        tones.set(
            schoolClass.ulid,
            isTurmaTone(schoolClass.identity_tone)
                ? schoolClass.identity_tone
                : (fallback.get(schoolClass.ulid) as TurmaTone),
        );
    }

    return tones;
}

/**
 * A cápsula do nome da turma: fundo pálido e texto escuro da mesma família.
 * Fundo 50 (100 no `stone`) e texto 900 (800 no `stone`) dão pelo menos 8:1 em
 * modo claro; em modo escuro o fundo cai para 30–40% e o texto sobe ao 200.
 */
export const TURMA_BADGE: Record<TurmaTone, string> = {
    blue: 'bg-blue-50 text-blue-900 dark:bg-blue-950/30 dark:text-blue-200',
    emerald:
        'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
    violet: 'bg-violet-50 text-violet-900 dark:bg-violet-950/30 dark:text-violet-200',
    amber: 'bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
    rose: 'bg-rose-50 text-rose-900 dark:bg-rose-950/30 dark:text-rose-200',
    stone: 'bg-stone-100 text-stone-800 dark:bg-stone-800/40 dark:text-stone-200',
};

/** O contorno da cápsula, um degrau acima do fundo. */
export const TURMA_BADGE_BORDER: Record<TurmaTone, string> = {
    blue: 'border-blue-200 dark:border-blue-900',
    emerald: 'border-emerald-200 dark:border-emerald-900',
    violet: 'border-violet-200 dark:border-violet-900',
    amber: 'border-amber-200 dark:border-amber-900',
    rose: 'border-rose-200 dark:border-rose-900',
    stone: 'border-stone-300 dark:border-stone-700',
};

/**
 * A barra fina do bloco no Horário do Professor (2px, degrau 200): sinal
 * secundário que dá a ver o desenho da semana sem pintar o cartão.
 */
export const TURMA_BAR: Record<TurmaTone, string> = {
    blue: 'border-l-blue-200 dark:border-l-blue-900/60',
    emerald: 'border-l-emerald-200 dark:border-l-emerald-900/60',
    violet: 'border-l-violet-200 dark:border-l-violet-900/60',
    amber: 'border-l-amber-200 dark:border-l-amber-900/60',
    rose: 'border-l-rose-200 dark:border-l-rose-900/60',
    stone: 'border-l-stone-300 dark:border-l-stone-700',
};

/**
 * A FAIXA dos cartões de Aulas e Sumários: 6px, degrau 400, porque é ela que
 * separa cartões largos ao descer a semana. Lisa numa aula da turma inteira.
 */
export const TURMA_BAND: Record<TurmaTone, string> = {
    blue: 'bg-blue-400 dark:bg-blue-500',
    emerald: 'bg-emerald-400 dark:bg-emerald-500',
    violet: 'bg-violet-400 dark:bg-violet-500',
    amber: 'bg-amber-400 dark:bg-amber-500',
    rose: 'bg-rose-400 dark:bg-rose-500',
    stone: 'bg-stone-400 dark:bg-stone-500',
};

/**
 * A faixa LISTRADA de uma aula de grupo (T1, T2…): o mesmo tom da turma,
 * interrompido. É o segundo sinal, além do texto «Grupo T1», de que a aula é
 * de parte da turma — e não depende da cor para se ver.
 */
export const TURMA_BAND_STRIPED: Record<TurmaTone, string> = {
    blue: 'bg-[repeating-linear-gradient(180deg,var(--color-blue-400)_0_7px,var(--color-blue-100)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-blue-500)_0_7px,var(--color-blue-950)_7px_11px)]',
    emerald:
        'bg-[repeating-linear-gradient(180deg,var(--color-emerald-400)_0_7px,var(--color-emerald-100)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-emerald-500)_0_7px,var(--color-emerald-950)_7px_11px)]',
    violet: 'bg-[repeating-linear-gradient(180deg,var(--color-violet-400)_0_7px,var(--color-violet-100)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-violet-500)_0_7px,var(--color-violet-950)_7px_11px)]',
    amber: 'bg-[repeating-linear-gradient(180deg,var(--color-amber-400)_0_7px,var(--color-amber-100)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-amber-500)_0_7px,var(--color-amber-950)_7px_11px)]',
    rose: 'bg-[repeating-linear-gradient(180deg,var(--color-rose-400)_0_7px,var(--color-rose-100)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-rose-500)_0_7px,var(--color-rose-950)_7px_11px)]',
    stone: 'bg-[repeating-linear-gradient(180deg,var(--color-stone-400)_0_7px,var(--color-stone-200)_7px_11px)] dark:bg-[repeating-linear-gradient(180deg,var(--color-stone-500)_0_7px,var(--color-stone-800)_7px_11px)]',
};

/** O quadrado de cor ao lado do nome (filtros, escolha de turma). */
export const TURMA_SWATCH: Record<TurmaTone, string> = TURMA_BAND;

/**
 * As classes da cápsula do nome de uma turma. `self-start` porque o nome vive
 * muitas vezes dentro de um `flex flex-col`, onde um filho se estica à largura
 * toda por omissão.
 */
export function turmaBadgeClass(tone: TurmaTone): string {
    return `self-start rounded px-1.5 py-0.5 ${TURMA_BADGE[tone]}`;
}

/** As classes da barra esquerda do bloco no Horário do Professor. */
export function turmaBarClass(tone: TurmaTone): string {
    return `border-l-2 ${TURMA_BAR[tone]}`;
}
