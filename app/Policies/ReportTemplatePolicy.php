<?php

namespace App\Policies;

use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Who may read, create, change and retire a report template (§39, §40).
 *
 * ALL OF IT IN ONE PLACE. The rules are simple enough to be tempting to inline
 * — `kind === personal && user_id === auth()->id()` reads fine in a controller
 * — and that is exactly how a permission ends up enforced in three places and
 * two of them drift. Nothing outside this file compares an owner id.
 *
 * READING is generous: everyone reads the system templates, every member of a
 * school reads its institutional ones, and a teacher reads their own. Nobody
 * reads a colleague's personal template — it is theirs, and a template carries
 * their judgement about how a report should be arranged.
 *
 * WRITING follows the same three worlds. Nobody edits a system template through
 * the application; they are what the seeder says they are. A personal template
 * belongs to its author. An institutional one belongs to whoever owns the
 * organization, because that is the only authority above a teacher this schema
 * actually has — recorded as a debt rather than papered over with an invented
 * role (§40, §78 of the module brief).
 *
 * CAPABILITIES ARE CHECKED HERE TOO, not only in the UI. A Base organization
 * posting a create request gets a 403 rather than a template it cannot use.
 */
class ReportTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReportTemplate $template): bool
    {
        return match ($template->kind) {
            // Shipped with the product; nothing to protect.
            ReportTemplateKind::System => true,
            // Any member of the school this template belongs to. The global
            // scope has already refused another organization's rows.
            ReportTemplateKind::Institutional => $this->belongsToTenant($user),
            // Theirs alone (§38).
            ReportTemplateKind::Personal => $this->authored($user, $template),
        };
    }

    /** Whether this user may create a template of this kind at all. */
    public function createKind(User $user, ReportTemplateKind $kind): bool
    {
        if ($kind->isSystem()) {
            return false;
        }

        $module = $kind->moduleToCreate();

        if ($module !== null && ! app(Entitlements::class)->allows($module)) {
            return false;
        }

        return match ($kind) {
            ReportTemplateKind::Personal => $this->belongsToTenant($user),
            ReportTemplateKind::Institutional => $this->ownsOrganization($user),
            ReportTemplateKind::System => false,
        };
    }

    /**
     * Laravel's `create` ability takes no model, so it answers the only
     * question that can be asked without one: may this user create ANY kind of
     * template. The specific check is createKind().
     */
    public function create(User $user): bool
    {
        return $this->createKind($user, ReportTemplateKind::Personal)
            || $this->createKind($user, ReportTemplateKind::Institutional);
    }

    public function update(User $user, ReportTemplate $template): bool
    {
        return match ($template->kind) {
            // What the seeder says. Editing one would put a school's change
            // into every school's copy at the next deploy.
            ReportTemplateKind::System => false,
            ReportTemplateKind::Personal => $this->authored($user, $template),
            ReportTemplateKind::Institutional => $this->ownsOrganization($user),
        };
    }

    /**
     * Retiring a template (§21, §43).
     *
     * The same authority as editing it: deactivating is an edit, and the model
     * has no delete of its own — a template that reports were built from is
     * kept so that «criado a partir de» still resolves.
     */
    public function deactivate(User $user, ReportTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    /**
     * Hard deletion, allowed only for a template nothing was ever built from.
     *
     * The «nothing was built from it» half is the caller's to check against the
     * reports table; this answers the authority half.
     */
    public function delete(User $user, ReportTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    /**
     * Copying somebody else's arrangement into one's own (§42).
     *
     * Reading is enough: what comes out is a NEW template owned by the person
     * who duplicated it, and the original is untouched. It still needs the
     * capability to own a template at all.
     */
    public function duplicate(User $user, ReportTemplate $template): bool
    {
        return $this->view($user, $template)
            && $this->createKind($user, ReportTemplateKind::Personal);
    }

    protected function authored(User $user, ReportTemplate $template): bool
    {
        return $template->user_id !== null
            && (int) $template->user_id === (int) $user->getKey();
    }

    protected function belongsToTenant(User $user): bool
    {
        $tenant = app(CurrentOrganization::class);

        if (! $tenant->isResolved()) {
            return false;
        }

        $organization = $tenant->get();

        return $user->owns($organization)
            || $organization->members()->whereKey($user->getKey())->exists();
    }

    protected function ownsOrganization(User $user): bool
    {
        return $user->ownsCurrentOrganization();
    }
}
