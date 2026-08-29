import {
    BarChart3,
    BookOpen,
    CalendarDays,
    Camera,
    ClipboardCheck,
    FileText,
    Gauge,
    ListChecks,
    Lock,
    NotebookPen,
    Route,
    Scale,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Users,
    Wand2,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * The copy of the five feature pages, keyed by the slug the route carries.
 *
 * ONE COMPONENT, FIVE PAGES. The pages share a structure (hero, product,
 * benefits, how it works, closing) and differ only in words and pictures;
 * five .vue files would have been five places for the structure to drift.
 * Everything here describes the product as it is — a benefit that needs a
 * feature that does not exist is not written.
 *
 * The H1, title and description a search engine reads are declared on the
 * server (App\Support\Seo\PublicPages), next to the route.
 */

export type FeatureBenefit = { icon: Component; title: string; body: string };
export type FeatureStep = { title: string; body: string };

export type Feature = {
    slug: string;
    eyebrow: string;
    title: string;
    /** The second line of the headline, in blue. */
    titleAccent?: string;
    lead: string;
    photo: { src: string; alt: string };
    screenshot: { src: string; alt: string; width: number; height: number };
    /** The sentence over the screenshot, on the blue band. */
    claim: string;
    benefits: readonly FeatureBenefit[];
    steps: readonly FeatureStep[];
    /** The pages a reader of this one is most likely to want next. */
    related: readonly { label: string; href: string }[];
};

const PHOTOS = {
    hands: {
        src: '/images/marketing/hero.webp',
        alt: 'Mãos de uma professora a escrever num caderno ao lado de um portátil aberto.',
    },
    classroom: {
        src: '/images/marketing/classroom.webp',
        alt: 'Sala de aula vazia ao fim da tarde, com luz a entrar pela janela.',
    },
    board: {
        src: '/images/marketing/teacher-back.webp',
        alt: 'Professora de costas a olhar para um quadro com anotações.',
    },
    planning: {
        src: '/images/marketing/planning.webp',
        alt: 'Mesa vista de cima com um planificador semanal, caneta e café.',
    },
} as const;

export const FEATURES: readonly Feature[] = [
    {
        slug: 'avaliacao',
        eyebrow: 'Avaliação de alunos',
        title: 'Os seus critérios. O seu peso.',
        titleAccent: 'A sua escala.',
        lead: 'Domínios com os nomes que a escola usa, ponderações por elemento, e uma escala de 1 a 5, de 0 a 20 ou a que a escola definiu. O Lapispro calcula a média ponderada e propõe a classificação — o professor confirma, corrige ou descarta.',
        photo: PHOTOS.hands,
        screenshot: {
            src: '/images/landing/grid.webp',
            alt: 'Grelha de resultados de uma turma no Lapispro: média ponderada por domínio, proposta na escala e nível atribuído pelo professor.',
            width: 1600,
            height: 854,
        },
        claim: 'Uma grelha que sabe o que aconteceu — e nunca inventa um zero.',
        benefits: [
            {
                icon: Scale,
                title: 'Vazio nunca é zero',
                body: 'Uma avaliação por preencher não conta como zero. O Lapispro distingue o que falta avaliar de uma classificação de zero.',
            },
            {
                icon: ListChecks,
                title: '«Não aplicável» fica fora do cálculo',
                body: 'Se um critério, questão ou domínio não se aplica a um aluno, sai do denominador — o resultado usa só o que era avaliável.',
            },
            {
                icon: Users,
                title: 'Quem chega tarde não é penalizado',
                body: 'Um aluno que entra a meio do período é avaliado só com os elementos em que podia efetivamente participar.',
            },
            {
                icon: Wand2,
                title: 'O sistema propõe, o professor decide',
                body: 'A proposta na escala nunca vira classificação sozinha. Confirmar é um gesto do professor, e o rasto do que entrou fica guardado.',
            },
        ],
        steps: [
            {
                title: 'Defina o perfil de avaliação',
                body: 'Domínios, ponderações e escala — uma vez por disciplina e ano, reutilizável no ano seguinte.',
            },
            {
                title: 'Registe os elementos',
                body: 'Testes, trabalhos, apresentações. A grelha preenche-se pelo teclado, com oito estados por célula.',
            },
            {
                title: 'Confirme a classificação',
                body: 'A média ponderada e a proposta aparecem por aluno e por período. Publicar fecha a pauta.',
            },
        ],
        related: [
            {
                label: 'Acompanhamento do aluno',
                href: '/funcionalidades/acompanhamento',
            },
            {
                label: 'Relatórios e pautas',
                href: '/funcionalidades/relatorios',
            },
        ],
    },
    {
        slug: 'turmas',
        eyebrow: 'Turmas e alunos',
        title: 'As suas turmas,',
        titleAccent: 'sem as escrever outra vez.',
        lead: 'Importe a pauta que a escola já lhe deu — números, nomes e fotografias no mesmo passo. Cada turma fica ligada ao ano letivo, à disciplina e ao perfil de avaliação com que é avaliada.',
        photo: PHOTOS.classroom,
        screenshot: {
            src: '/images/landing/roster.webp',
            alt: 'Ecrã de uma turma no Lapispro: a lista de alunos importada da pauta, com número, nome e pseudónimo.',
            width: 1600,
            height: 1058,
        },
        claim: 'Da pauta da escola a uma turma pronta a avaliar, em minutos.',
        benefits: [
            {
                icon: ClipboardCheck,
                title: 'Importação da pauta',
                body: 'Ficheiros exportados do Intuitivo, ou compatíveis: a lista de alunos em Excel e as fotografias em Word entram no mesmo passo.',
            },
            {
                icon: Camera,
                title: 'Fotografias com os nomes',
                body: 'A cara ao lado do nome desde o primeiro dia — na turma, na grelha e no acompanhamento.',
            },
            {
                icon: Lock,
                title: 'Identidade separada e protegida',
                body: 'O nome fica cifrado e separado. No trabalho diário e em qualquer processamento por IA usa-se só o pseudónimo.',
            },
            {
                icon: CalendarDays,
                title: 'Um ano letivo de cada vez',
                body: 'Turmas arquivadas continuam consultáveis e não contam para os limites do plano. O próximo ano começa limpo.',
            },
        ],
        steps: [
            {
                title: 'Crie o ano letivo',
                body: 'Períodos ou semestres, interrupções e feriados — importados do calendário da escola no plano Pro.',
            },
            {
                title: 'Importe ou inscreva os alunos',
                body: 'Da pauta ou um a um. A data de entrada de cada aluno é o que protege quem chega mais tarde.',
            },
            {
                title: 'Ligue ao perfil de avaliação',
                body: 'A turma passa a ser avaliada com os domínios, pesos e escala desse perfil.',
            },
        ],
        related: [
            {
                label: 'Avaliação de alunos',
                href: '/funcionalidades/avaliacao',
            },
            { label: 'Segurança e dados', href: '/seguranca' },
        ],
    },
    {
        slug: 'acompanhamento',
        eyebrow: 'Acompanhamento pedagógico',
        title: 'A turma inteira num ecrã.',
        titleAccent: 'Cada aluno, ao longo do ano.',
        lead: 'Quadro síntese por domínio e por período, estatística da turma, evolução de cada aluno. E o que o professor faz com isso — estratégias, medidas e registos — fica no processo do aluno, fora do cálculo.',
        photo: PHOTOS.board,
        screenshot: {
            src: '/images/landing/analysis.webp',
            alt: 'Quadro síntese de uma turma no Lapispro: por domínio, a média de cada período, a evolução, o acumulado e a menção na escala.',
            width: 1600,
            height: 800,
        },
        claim: 'Ver quem subiu, quem desceu e onde — antes da reunião, não depois.',
        benefits: [
            {
                icon: BarChart3,
                title: 'Quadro síntese',
                body: 'Por domínio: a média de cada período, a evolução face ao anterior, o acumulado e a menção na escala. Verde e vermelho dizem tendência, não nível.',
            },
            {
                icon: TrendingUp,
                title: 'Evolução do aluno',
                body: 'O percurso de um aluno ao longo dos períodos, domínio a domínio, com a autoavaliação ao lado quando existe.',
            },
            {
                icon: Route,
                title: 'Estratégias e medidas',
                body: 'A dificuldade, a estratégia adotada, o objetivo e a data de revisão. Registado no processo do aluno; nunca altera a classificação.',
            },
            {
                icon: Sparkles,
                title: 'IA que lê, não que decide',
                body: 'No plano Pro, a IA interpreta os resultados já calculados e sugere o próximo passo pedagógico. Sem dados identificáveis, e sem tocar numa nota.',
            },
        ],
        steps: [
            {
                title: 'Avalie como sempre',
                body: 'O acompanhamento não pede registos a mais: parte da grelha que já preencheu.',
            },
            {
                title: 'Leia a turma',
                body: 'Quadro síntese e estatística por período — distribuição pela escala, taxa de sucesso, quem está por classificar.',
            },
            {
                title: 'Registe o que fez',
                body: 'Estratégias, medidas e observações, por aluno ou para a turma inteira, com data.',
            },
        ],
        related: [
            {
                label: 'Avaliação de alunos',
                href: '/funcionalidades/avaliacao',
            },
            {
                label: 'Relatórios e pautas',
                href: '/funcionalidades/relatorios',
            },
        ],
    },
    {
        slug: 'aulas-e-sumarios',
        eyebrow: 'Aulas, sumários e horário',
        title: 'Planeie a semana, escreva o sumário.',
        titleAccent: 'Siga o ano.',
        lead: 'O horário do professor, as aulas com sumário, as sequências reutilizáveis e a agenda do ano letivo — ao lado das turmas e das classificações, não noutra aplicação.',
        photo: PHOTOS.planning,
        screenshot: {
            src: '/images/landing/calendar.webp',
            alt: 'Calendário do ano letivo no Lapispro: o mês com os períodos, as interrupções e os acontecimentos marcados pelo professor.',
            width: 1600,
            height: 1017,
        },
        claim: 'O sumário como centro da aula — com notas, recursos e trabalho de casa no mesmo registo.',
        benefits: [
            {
                icon: Gauge,
                title: 'Horário do professor',
                body: 'A semana inteira num ecrã, montada à mão ou importada do horário que a escola já emitiu.',
            },
            {
                icon: NotebookPen,
                title: 'Aulas e sumários',
                body: 'Cada aula com o seu sumário, notas privadas, recursos e trabalho de casa. O que é para os alunos e o que é só seu ficam separados.',
            },
            {
                icon: BookOpen,
                title: 'Sequências de aulas',
                body: 'Uma progressão escrita uma vez e aplicada a outra turma sem a escrever de novo.',
            },
            {
                icon: CalendarDays,
                title: 'Agenda do ano letivo',
                body: 'Períodos, interrupções e feriados no mesmo calendário onde marca o que é seu — em todos os planos.',
            },
        ],
        steps: [
            {
                title: 'Importe o horário',
                body: 'Ou monte-o à mão. Cada bloco fica ligado à turma e à disciplina.',
            },
            {
                title: 'Escreva o sumário na aula',
                body: 'A aula de hoje já está lá, com a turma certa. Só falta o que aconteceu.',
            },
            {
                title: 'Reutilize no ano seguinte',
                body: 'Sequências e planificações passam de um ano para o outro sem copiar e colar.',
            },
        ],
        related: [
            { label: 'Turmas e alunos', href: '/funcionalidades/turmas' },
            { label: 'Planos', href: '/planos' },
        ],
    },
    {
        slug: 'relatorios',
        eyebrow: 'Relatórios e pautas',
        title: 'O relatório',
        titleAccent: 'já vem meio escrito.',
        lead: 'Relatórios de avaliação cujas secções partem do que já registou — resultados, classificações, estratégias. Pautas por período e quadro síntese exportáveis. Finalizar fixa o documento; corrigir depois deriva outro.',
        photo: PHOTOS.hands,
        screenshot: {
            src: '/images/landing/analysis.webp',
            alt: 'Quadro síntese exportável de uma turma no Lapispro.',
            width: 1600,
            height: 800,
        },
        claim: 'Do que está registado ao documento que a escola pede — sem recomeçar do zero.',
        benefits: [
            {
                icon: FileText,
                title: 'Relatórios a partir dos dados',
                body: 'As secções são compostas do que já existe. O professor edita, acrescenta e assina; não começa em branco.',
            },
            {
                icon: ClipboardCheck,
                title: 'Pautas por período',
                body: 'Só classificações decididas — confirmadas ou publicadas. Exportar em CSV ou imprimir, com ou sem os registos do professor.',
            },
            {
                icon: ShieldCheck,
                title: 'Finalizar fixa o documento',
                body: 'Um relatório finalizado não muda. Uma correção posterior cria outro, e o histórico guarda os dois.',
            },
            {
                icon: Sparkles,
                title: 'Redação apoiada por IA',
                body: 'No plano Pro, a IA aperfeiçoa a redação do que o Lapispro já compôs. Números e datas saem como marcadores e um guarda recusa o que voltar alterado.',
            },
        ],
        steps: [
            {
                title: 'Escolha o modelo',
                body: 'Modelos de relatório guardam a organização das secções para a próxima vez.',
            },
            {
                title: 'Reveja o que foi composto',
                body: 'Cada secção mostra de onde veio. O que não concorda, corrige.',
            },
            {
                title: 'Finalize e exporte',
                body: 'PDF para a escola, CSV para quem precisa dos números.',
            },
        ],
        related: [
            {
                label: 'Acompanhamento',
                href: '/funcionalidades/acompanhamento',
            },
            {
                label: 'Avaliação de alunos',
                href: '/funcionalidades/avaliacao',
            },
        ],
    },
];

export function featureFor(slug: string): Feature {
    const feature = FEATURES.find((candidate) => candidate.slug === slug);

    if (!feature) {
        throw new Error(`Funcionalidade desconhecida: ${slug}`);
    }

    return feature;
}
