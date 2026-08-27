export type TourRegion =
    'menu' | 'context' | 'actions' | 'decision' | 'account';

export type TourStop = {
    key: TourRegion;
    title: string;
    body: string;
};

/**
 * What the tour explains, in the order somebody reads a screen: where you go,
 * what you are looking at, what you can do, what the answer is, and who you are
 * while doing it.
 *
 * Every claim here is about the application as it exists. The tour is a picture
 * of the product, and a picture that promises a screen nobody can reach is the
 * fastest way to lose the visitor on the first day of the trial.
 */
export const TOUR_STOPS: readonly TourStop[] = [
    {
        key: 'menu',
        title: 'O menu segue o seu trabalho',
        body: 'Organizar, avaliar, acompanhar, intervir, documentar — por esta ordem. O que o seu plano não inclui não aparece no menu.',
    },
    {
        key: 'context',
        title: 'O contexto vive no cabeçalho',
        body: 'Ano letivo, disciplina, ano, turma e período. Escolhe uma vez, e todos os ecrãs passam a falar dessa turma.',
    },
    {
        key: 'actions',
        title: 'As ações estão onde o trabalho está',
        body: 'Gerar as propostas a partir do que está registado, e publicar as que já confirmou. Nada acontece sozinho.',
    },
    {
        key: 'decision',
        title: 'A proposta é do Lapispro. A decisão é sua',
        body: 'Média ponderada, proposta na escala, e a sua classificação ao lado. Decidir diferente da proposta fica registado com a razão.',
    },
    {
        key: 'account',
        title: 'A sua conta e a sua organização',
        body: 'Trocar entre a sua organização pessoal e a da escola, e proteger a conta com dois passos ou uma passkey.',
    },
];
