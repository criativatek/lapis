import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/**
 * A teacher menu entry as shared from the backend (config/navigation.php,
 * filtered by NavigationBuilder). `href` is null for a page a later phase will
 * build; `built` is true only for pages that already exist.
 */
export type SharedNavItem = {
    key: string;
    label: string;
    /** One line on what the page is for. Null on items that need no explaining. */
    description: string | null;
    /** Extra path fragments that should mark this entry as the current one. */
    match: string[];
    icon: string;
    phase: number;
    href: string | null;
    built: boolean;
};

export type SharedNavSection = {
    label: string | null;
    items: SharedNavItem[];
};

export type SharedNav = {
    sections: SharedNavSection[];
    footer: SharedNavItem[];
};

/**
 * The header context selectors. `academicYear` reflects the organization's
 * resolved academic year once one exists (session selection with the
 * AcademicYearRetentionClassifier fallback);
 * the rest stay null until there is a canonical "current" one to read
 * instead of inventing one.
 */
export type WorkScope = {
    academicYear: string | null;
    subject: string | null;
    hasSubjects: boolean;
    gradeLevel: string | null;
    class: string | null;
    period: string | null;
};

export type SelectableAcademicYear = {
    ulid: string;
    label: string;
    is_current: boolean;
};
