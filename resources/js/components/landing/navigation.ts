export type LandingNavItem = {
    label: string;
    href: string;
};

/**
 * The anchors the public header offers, in reading order.
 *
 * One list, used by the desktop nav and the mobile sheet alike — two copies of
 * the same menu is exactly how a link ends up in one and not the other.
 */
export const LANDING_NAV: readonly LandingNavItem[] = [
    { label: 'Como funciona', href: '#como-funciona' },
    { label: 'Funcionalidades', href: '#funcionalidades' },
    { label: 'Segurança', href: '#seguranca' },
    { label: 'Planos', href: '#planos' },
    { label: 'Perguntas', href: '#perguntas' },
];
