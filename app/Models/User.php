<?php

namespace App\Models;

use App\Support\Tenancy\CurrentOrganization;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_platform_admin
 * @property bool $is_support_technician
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $closure_requested_at
 * @property Carbon|null $scheduled_deletion_at
 * @property Carbon|null $anonymized_at
 * @property string|null $terms_version
 * @property Carbon|null $terms_accepted_at
 * @property Carbon|null $onboarding_dismissed_at
 * @property Carbon|null $privacy_notice_dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Platform capability flags and deactivated_at are intentionally absent: none
// of them is ever mass-assigned.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'is_support_technician' => 'boolean',
            'deactivated_at' => 'datetime',
            'closure_requested_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'privacy_notice_dismissed_at' => 'datetime',
        ];
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    public function isSupportTechnician(): bool
    {
        return $this->is_support_technician === true;
    }

    /**
     * Whether this person has been shut out of the application.
     *
     * Deliberately a different question from the organization's subscription. A
     * suspended subscription takes the PRODUCT away, and takes it away from
     * everybody in the organization at once; this takes the DOOR away, from one
     * person. Only the second one still means something the day an
     * organization has twenty members and one of them leaves.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Overrides the trait's version: a valid account was just created (or the
     * person asked to resend), and a mail transport hiccup — a bounce, a
     * greylisted relay, SMTP momentarily down — is not that person's problem
     * to see as a 500. Never marks the email verified; only stops the failure
     * from escaping the request. `verifyEmailView` (FortifyServiceProvider)
     * reads the session flag this sets to show a friendly message instead.
     */
    public function sendEmailVerificationNotification(): void
    {
        try {
            $this->notify(new VerifyEmail);
        } catch (\Throwable $exception) {
            report($exception);

            session()->put('verification_send_failed', true);
        }
    }

    public function isActive(): bool
    {
        return ! $this->isDeactivated();
    }

    /**
     * A voluntary, recoverable closure request — never confused with
     * `deactivated_at`, which is an operator's administrative action. See
     * docs/account-closure.md.
     */
    public function isClosureRequested(): bool
    {
        return $this->closure_requested_at !== null;
    }

    /**
     * Whether this teacher dismissed the "Primeiros passos" onboarding card
     * (A1a, Onboarding & Help). The only thing this ever gates is whether the
     * card is shown — the progress it displays is computed live elsewhere
     * (DashboardController::firstSteps()) and is never affected by this flag.
     */
    public function hasDismissedOnboarding(): bool
    {
        return $this->onboarding_dismissed_at !== null;
    }

    /**
     * Se há uma Política de Privacidade mais recente do que o último aviso que
     * esta pessoa fechou.
     *
     * COMPARA DATAS, NÃO VERSÕES. Uma actualização futura da Política volta a
     * mostrar o aviso sozinha, sem ninguém ter de se lembrar de limpar uma
     * coluna e sem duas strings de versão que teriam de concordar.
     *
     * NÃO É UMA ACEITAÇÃO PENDENTE: fechar o aviso não consente coisa nenhuma,
     * e não o fechar não bloqueia nada. É informação, e a única coisa que este
     * método decide é se ela ainda é nova para quem está a ler.
     */
    public function shouldSeePrivacyNotice(): bool
    {
        $emVigor = config('lapis.legal.privacy_effective_from');

        if (! is_string($emVigor) || $emVigor === '') {
            return false;
        }

        return $this->privacy_notice_dismissed_at === null
            || $this->privacy_notice_dismissed_at->lt(Carbon::parse($emVigor));
    }

    /**
     * `scheduled_deletion_at` is stamped once, at request time, from the
     * policy days in force that moment (App\Actions\Accounts\RequestPersonalAccountClosure).
     * It does not move if the policy changes later — a request already in
     * flight keeps the deadline it was promised.
     */
    public function isEligibleForDeletion(): bool
    {
        return $this->closure_requested_at !== null
            && $this->scheduled_deletion_at !== null
            && $this->scheduled_deletion_at->isPast();
    }

    /**
     * Every organization this user is a member of, personal or institutional.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_id');
    }

    public function personalOrganization(): ?Organization
    {
        return $this->organizations()
            ->where('organizations.type', OrganizationType::Personal)
            ->where('organizations.owner_id', $this->getKey())
            ->first();
    }

    /**
     * Whether this user is THE owner of the given organization.
     *
     * The one authority this schema actually has above "a teacher who belongs
     * here" (§9, §78 of the module brief) — no role column, no invented
     * permission matrix. Every policy that needs "may govern this
     * organization's shared configuration" reads this single predicate, so the
     * definition of owner cannot drift between OrganizationPolicy,
     * ReportTemplatePolicy and whichever policy is written next.
     *
     * Deliberately NOT `is_platform_admin`. A platform admin administers the
     * SaaS; an organization owner governs one workspace. Conflating the two
     * would let the operator's backoffice flag double as institutional
     * authority inside the pedagogical app, which is a different capability
     * this schema was told never to invent.
     */
    public function owns(Organization $organization): bool
    {
        return (int) $organization->owner_id === (int) $this->getKey();
    }

    /**
     * Whether this user owns the RESOLVED TENANT — never an organization read
     * off the user, since a user may belong to several (ADR-0002). False when
     * no tenant is resolved at all.
     */
    public function ownsCurrentOrganization(): bool
    {
        $tenant = app(CurrentOrganization::class);

        return $tenant->isResolved() && $this->owns($tenant->get());
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeClosureRequested($query)
    {
        return $query->whereNotNull('closure_requested_at');
    }

    /**
     * Deliberately reads the stored deadline, not a fresh diffInDays against
     * live config — see the note on isEligibleForDeletion().
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeEligibleForDeletion($query)
    {
        return $query->whereNotNull('closure_requested_at')
            ->where('scheduled_deletion_at', '<=', now());
    }
}
