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
 *
 * E DO CONJUNTO INTEIRO DE `ulid`s, E NÃO DE UM SÓ. O tom saía de uma dispersão
 * do `ulid` sozinho, e duas turmas caíam na mesma gaveta com quatro gavetas
 * vazias ao lado: com cinco turmas na página, o 7.º D e o 7.º E ficaram com a
 * mesma cor. Era estável e determinístico, e não servia para nada — o sinal
 * existe para SEPARAR turmas. `assignTurmaTones` decide os tons olhando para
 * todas as turmas visíveis de uma vez, para que enquanto houver tons a sobrar
 * não haja duas turmas a partilhar um.
 */

export type TurmaTone =
    'blue' | 'emerald' | 'violet' | 'amber' | 'rose' | 'stone';

/**
 * Seis tons, e a ordem não tem significado nenhum: são apenas seis gavetas
 * para onde o `ulid` cai. Seis chegam para a semana de um professor sem que a
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

/**
 * OS TONS DAS TURMAS QUE ESTÃO NESTA PÁGINA, decididos de uma vez e a olhar
 * para todas elas.
 *
 * A REGRA: enquanto as turmas visíveis couberem na paleta, cada uma leva um tom
 * SÓ SEU. Nunca duas turmas com a mesma cor enquanto houver uma cor por usar —
 * era exatamente isso que a dispersão do `ulid` sozinho deixava acontecer, e o
 * sinal existe para separar turmas, não para ser bonito. Passadas as seis, os
 * tons repetem-se, e repetem-se o mais espaçadamente que esta ordem permite.
 *
 * ORDENADO PELO PRÓPRIO `ulid`, e não pela ordem em que os blocos chegaram. A
 * ordem do `props.slots` é a ordem das aulas da semana: bastava o professor
 * marcar mais uma aula à segunda-feira para a lista mudar de ordem e as turmas
 * todas trocarem de cor. Ordenar os `ulid`s é uma decisão que só depende dos
 * `ulid`s — o mesmo CONJUNTO de turmas dá sempre o mesmo mapa, venha ele na
 * ordem que vier, e as três leituras da página (grelha, fim de semana e agenda)
 * leem todas o mesmo mapa.
 *
 * ISTO É DESTA PÁGINA, e não da aplicação: o tom de uma turma é o que a separa
 * das turmas que estão AO PÉ DELA no horário, e não uma identidade que a siga
 * por todo o Lapispro.
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
export function turmaBadgeClass(tone: TurmaTone): string {
    return `self-start rounded px-1.5 py-0.5 ${TURMA_BADGE[tone]}`;
}

/** As classes da barra esquerda do bloco desta turma. */
export function turmaBarClass(tone: TurmaTone): string {
    return `border-l-2 ${TURMA_BAR[tone]}`;
}
