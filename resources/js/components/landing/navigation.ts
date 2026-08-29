export type LandingNavItem = {
    label: string;
    href: string;
};

/**
 * The public site's navigation — real pages since 0.94.0, not anchors into
 * one long landing. The header shows the first group; the footer and the
 * mobile sheet show everything. Paths are the ones declared in
 * App\Support\Seo\PublicPages; a link here to a page that is not there is a
 * 404, which MarketingPagesTest catches by visiting every entry.
 */
export const LANDING_NAV: readonly LandingNavItem[] = [
    { label: 'Avaliação', href: '/funcionalidades/avaliacao' },
    { label: 'Turmas', href: '/funcionalidades/turmas' },
    { label: 'Acompanhamento', href: '/funcionalidades/acompanhamento' },
    { label: 'Planos', href: '/planos' },
    { label: 'Segurança', href: '/seguranca' },
];

/** The feature pages, in the order the product is used. */
export const FEATURE_NAV: readonly LandingNavItem[] = [
    { label: 'Avaliação de alunos', href: '/funcionalidades/avaliacao' },
    { label: 'Turmas e alunos', href: '/funcionalidades/turmas' },
    { label: 'Acompanhamento', href: '/funcionalidades/acompanhamento' },
    { label: 'Aulas e sumários', href: '/funcionalidades/aulas-e-sumarios' },
    { label: 'Relatórios e pautas', href: '/funcionalidades/relatorios' },
];

export const COMPANY_NAV: readonly LandingNavItem[] = [
    { label: 'Planos', href: '/planos' },
    { label: 'Segurança e dados', href: '/seguranca' },
    { label: 'Sobre e contacto', href: '/sobre' },
];
