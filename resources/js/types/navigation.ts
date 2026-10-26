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
    /** A frequent destination, marked quietly in the sidebar — never the same look as the active entry. */
    priority: boolean;
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

/** The class a `{class}`-bound route put in view — the one its controller already authorised. */
export type ScopeClass = {
    ulid: string;
    label: string;
    subject: string;
    gradeLevel: string | null;
};

/** A class the header chip can switch to, with the destination the server worked out (ClassSwitcher). */
export type ScopeClassOption = ScopeClass & {
    href: string;
    is_current: boolean;
};

/**
 * The header context. `academicYear` reflects the organization's resolved
 * academic year once one exists (session selection with the
 * AcademicYearRetentionClassifier fallback); `class` is the turma in view, or
 * null on a page without one; `classes` is empty whenever `class` is.
 */
export type WorkScope = {
    academicYear: string | null;
    class: ScopeClass | null;
    classes: ScopeClassOption[];
};

export type SelectableAcademicYear = {
    ulid: string;
    label: string;
    is_current: boolean;
};
