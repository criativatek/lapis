/**
 * O SINAL DE TURMA DO HORÁRIO — a cor que ajuda a distinguir turmas ao correr
 * a semana com os olhos, e que nunca é o que DIZ qual é a turma.
 *
 * O nome da turma está sempre escrito ao lado, em texto, em todos os sítios
 * onde este tom aparece: a página lê-se inteira num ecrã monocromático, e quem
 * não distingue estas cores não perde nada. A cor é reforço, e só.
 *
 * DERIVADO DO `ulid`, NUNCA DA POSIÇÃO. Um tom escolhido pelo índice do cartão
 * na lista mudava de dia para dia e de semana para semana — a mesma turma
 * mudava de cor por ter passado a ser a segunda aula da terça em vez da
 * primeira. O `ulid` é o identificador estável que o servidor já manda, e é
 * dele que o tom sai: a mesma turma tem sempre o mesmo tom, em qualquer dia,
 * em qualquer semana e nas três leituras da página (grelha, fim de semana e
 * agenda). Do `ulid` e não do `label`, que é texto editável e só por hábito é
 * único.
 */

export type TurmaTone =
    'blue' | 'emerald' | 'violet' | 'amber' | 'rose' | 'stone';

/**
 * Seis tons, e a ordem não tem significado nenhum: são apenas seis gavetas
 * para onde o `ulid` cai. Seis chegam para a semana de um professor sem que a
 * página vire um arco-íris, e param antes dos vermelhos e verdes fortes que,
 * noutras páginas do LAPIS, querem dizer «negativa» e «positiva».
 */
export const TURMA_TONES: readonly TurmaTone[] = [
    'blue',
    'emerald',
    'violet',
    'amber',
    'rose',
    'stone',
];

/**
 * Soma-com-mistura dos códigos dos caracteres. O `* 31` a cada passo é o que
 * distingue anagramas e, sobretudo, o que espalha ULIDs que partilham o mesmo
 * prefixo de tempo — turmas criadas no mesmo instante — em vez de os empilhar
 * todos no mesmo tom. `| 0` mantém a conta em int32 a cada passo e `>>> 0`
 * devolve-a sem sinal, para que o resto da divisão nunca seja negativo.
 *
 * Função pura, sem estado e sem dependências: dois cálculos do mesmo `ulid`
 * dão sempre o mesmo número, nesta e em qualquer outra renderização.
 */
function hashOf(ulid: string): number {
    let hash = 0;

    for (let index = 0; index < ulid.length; index += 1) {
        hash = (hash * 31 + ulid.charCodeAt(index)) | 0;
    }

    return hash >>> 0;
}

/** O tom desta turma — sempre o mesmo, para o mesmo `ulid`. */
export function turmaTone(ulid: string): TurmaTone {
    return TURMA_TONES[hashOf(ulid) % TURMA_TONES.length] as TurmaTone;
}

/**
 * A cápsula do nome da turma: fundo pálido e texto escuro da mesma família.
 *
 * Os fundos são o degrau 50 (100 no `stone`, que é mais claro à partida) —
 * mais fracos do que os 100 de `qualitativeToneClasses`, de propósito: aquilo
 * é um estado a comunicar, isto é papel colorido por baixo de um nome. O texto
 * é o degrau 900 (800 no `stone`), o que dá pelo menos 8:1 sobre o próprio
 * fundo em modo claro; em modo escuro o fundo cai para 30–40% de opacidade
 * sobre o escuro do cartão e o texto sobe ao degrau 200, onde volta a estar
 * muito acima do mínimo. Discreto não é ilegível.
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

/**
 * A barra do bloco: dois pixéis na margem esquerda, no mesmo tom da cápsula e
 * mais fraca do que ela. É o sinal secundário — dá a ver o desenho da semana ao
 * longe, sem pintar o cartão. O fundo do bloco, a hora e a disciplina ficam tão
 * neutros como sempre foram.
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
 * As classes da cápsula do nome de uma turma. `self-start` porque o nome vive
 * dentro de um `flex flex-col`, onde um filho se estica à largura toda por
 * omissão: sem isto a cápsula deixava de ser uma cápsula e passava a ser uma
 * faixa a atravessar o cartão — que é precisamente o fundo pintado que esta
 * mudança não quer.
 */
export function turmaBadgeClass(ulid: string): string {
    return `self-start rounded px-1.5 py-0.5 ${TURMA_BADGE[turmaTone(ulid)]}`;
}

/** As classes da barra esquerda do bloco desta turma. */
export function turmaBarClass(ulid: string): string {
    return `border-l-2 ${TURMA_BAR[turmaTone(ulid)]}`;
}
