<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OrganizationIdentity;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\SchoolLogoService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The school as it will appear on a document.
 *
 * Configuration only: nothing here is generated, exported or rendered. What it
 * produces is an identity that Relatórios — and any later Word or PDF export —
 * can ask DocumentIdentity for, instead of each module inventing its own idea
 * of what a school's header looks like (§19, §28).
 */
class SchoolIdentityController extends Controller
{
    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected SchoolLogoService $logos,
        protected DocumentIdentity $documentIdentity,
    ) {}

    public function edit(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewIdentity', $organization);

        return Inertia::render('settings/SchoolIdentity', [
            'identity' => $this->payload(),
            // The same structure a document will receive, so the preview on
            // screen and the header on paper can never drift apart.
            'preview' => $this->documentIdentity->for($organization),
            // A teacher who is not the owner still sees the letterhead their
            // reports will carry — they just cannot rewrite it for everyone.
            'canEdit' => Gate::allows('updateIdentity', $organization),
            'organizationName' => $organization->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('updateIdentity', $organization);

        // NOTHING IS REQUIRED. A school known by name today and by NIF next
        // week must be able to save what it knows (§12). What is validated is
        // the shape of what was actually typed.
        $validated = $request->validate([
            'official_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            // A string, never numeric: «1000-001» is not arithmetic and its
            // leading zero has to survive.
            'postal_code' => ['nullable', 'string', 'max:32'],
            'locality' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:64'],
            // Deliberately loose: «+351 21 000 0000», «213 000 000» and an
            // extension are all real, and a stricter rule would reject a
            // number that works (§13).
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'school_code' => ['nullable', 'string', 'max:32'],
            'tax_number' => ['nullable', 'string', 'max:32'],
            'department' => ['nullable', 'string', 'max:120'],
            'footer_note' => ['nullable', 'string', 'max:255'],
        ]);

        $identity = $this->identity();

        // TRIM AND NOTHING ELSE. The school wrote its own name; upper-casing it
        // or «tidying» it would be the application overruling them (§14). An
        // empty field is «not filled in», which is null rather than «».
        $identity->fill(array_map(
            fn (?string $value): ?string => ($value === null || trim($value) === '') ? null : trim($value),
            $validated,
        ));

        $identity->website = $this->normalizeWebsite($identity->website);
        $identity->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Identidade da escola guardada.']);
    }

    /**
     * The logo, on its own request.
     *
     * Its own endpoint rather than part of the form: an upload and a text edit
     * fail in different ways, and a rejected image must never discard an
     * address the teacher has just typed.
     */
    public function storeLogo(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('updateIdentity', $organization);

        // `image` and `mimes` both, because they check different things: the
        // first that it decodes as an image at all, the second that its REAL
        // type is one a document renderer can place. SVG is excluded on
        // purpose — it is a script container, and clearing it for use here
        // needs a security audit this task does not include (§4).
        $request->validate([
            'logo' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', SchoolLogoService::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', SchoolLogoService::ALLOWED_MIMES),
                'max:'.SchoolLogoService::MAX_KILOBYTES,
                // Big enough to print, small enough not to be a photograph
                // somebody dragged in by mistake.
                'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000',
            ],
        ], [
            'logo.mimes' => 'O logótipo tem de ser PNG, JPG ou WebP.',
            'logo.mimetypes' => 'O logótipo tem de ser PNG, JPG ou WebP.',
            'logo.max' => 'O logótipo não pode exceder 2 MB.',
            'logo.dimensions' => 'O logótipo tem de ter entre 32 e 4000 píxeis de lado.',
        ]);

        $this->logos->replace($this->identity(), $request->file('logo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Logótipo atualizado.']);
    }

    public function destroyLogo(): RedirectResponse
    {
        Gate::authorize('updateIdentity', $this->currentOrganization->get());

        $this->logos->remove($this->identity());

        return back()->with('toast', ['type' => 'success', 'message' => 'Logótipo removido.']);
    }

    /**
     * Streams the logo to somebody entitled to see it.
     *
     * The file lives on the private disk, so this is the only way to it — and
     * the path comes from the identity row, never from the request.
     */
    public function logo(): StreamedResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewIdentity', $organization);

        $path = $organization->identity?->logo_path;

        abort_if($path === null || ! Storage::disk(SchoolLogoService::DISK)->exists($path), 404);

        return Storage::disk(SchoolLogoService::DISK)->response($path);
    }

    /**
     * The row for this organization, created on first use.
     *
     * A school that has never opened this page has no row at all, which is
     * what lets «ainda não configurado» be a real state.
     */
    protected function identity(): OrganizationIdentity
    {
        return $this->currentOrganization->get()->identity
            ?? OrganizationIdentity::create([]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $identity = $this->currentOrganization->get()->identity;

        return [
            'official_name' => $identity?->official_name,
            'short_name' => $identity?->short_name,
            'address' => $identity?->address,
            'postal_code' => $identity?->postal_code,
            'locality' => $identity?->locality,
            'country' => $identity?->country,
            'phone' => $identity?->phone,
            'email' => $identity?->email,
            'website' => $identity?->website,
            'school_code' => $identity?->school_code,
            'tax_number' => $identity?->tax_number,
            'department' => $identity?->department,
            'footer_note' => $identity?->footer_note,
        ];
    }

    /**
     * «escola.pt» becomes «https://escola.pt»; anything already carrying a
     * scheme is left exactly as typed.
     *
     * Deliberately not a URL validator: a school writing its address without
     * the scheme is writing a real address, and rejecting it would be the
     * application being pedantic about a field nobody has to fill in (§13).
     */
    protected function normalizeWebsite(?string $website): ?string
    {
        if ($website === null) {
            return null;
        }

        return preg_match('~^https?://~i', $website) === 1 ? $website : 'https://'.$website;
    }
}
