export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Organization = {
    ulid: string;
    name: string;
    type: 'personal' | 'institutional';
    is_owner: boolean;
};

export type Auth = {
    user: User;
    // The SaaS operator, not a role inside the organization: it has nothing to
    // do with `organization.is_owner` or the `institution_admin` module.
    is_platform_admin: boolean;
    is_support_technician: boolean;
    organization: Organization | null;
    organizations: Organization[];
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
