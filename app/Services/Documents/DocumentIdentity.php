<?php

namespace App\Services\Documents;

use App\Models\Organization;
use App\Models\OrganizationIdentity;
use App\Support\Tenancy\CurrentOrganization;

/**
 * The school's letterhead, resolved once and handed to whoever is building a
 * document.
 *
 * THE ONE PLACE A DOCUMENT ASKS WHO THE SCHOOL IS. Relatórios de turma,
 * relatórios individuais, an eventual Word or PDF export — none of them should
 * query `organization_identities`, decide what to do when it is missing, or
 * re-derive a display name. They ask this, and get an answer that is already
 * complete: every field present, empty ones as null, the lines that would go on
 * a header already assembled and already free of blanks.
 *
 * IT NEVER INVENTS. A school that has filled in nothing gets a payload whose
 * `name` falls back to the organization's own name — which is a real name the
 * user typed when they signed up — and nulls everywhere else. It does not make
 * up an address, and it does not repeat a field to fill a gap.
 *
 * THE LOGO IS A ROUTE, NOT A PATH. The file lives on the private disk and is
 * served by an authorizing controller, so what leaves here is a URL a browser
 * (or a document renderer holding a session) may ask for — never a filesystem
 * location, and never the bytes.
 */
class DocumentIdentity
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    /**
     * @return array<string, mixed>
     */
    public function forCurrentOrganization(): array
    {
        return $this->for($this->currentOrganization->get());
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization): array
    {
        $identity = $organization->identity;

        // The organization's own name is the floor: a document with no name at
        // all on it is worse than one naming the account the teacher created.
        $name = $this->clean($identity?->official_name) ?? $organization->name;

        return [
            'organization_name' => $organization->name,
            'name' => $name,
            'short_name' => $this->clean($identity?->short_name),
            'address' => $this->clean($identity?->address),
            'postal_code' => $this->clean($identity?->postal_code),
            'locality' => $this->clean($identity?->locality),
            'country' => $this->clean($identity?->country),
            'phone' => $this->clean($identity?->phone),
            'email' => $this->clean($identity?->email),
            'website' => $this->clean($identity?->website),
            'school_code' => $this->clean($identity?->school_code),
            'tax_number' => $this->clean($identity?->tax_number),
            'department' => $this->clean($identity?->department),
            'footer_note' => $this->clean($identity?->footer_note),
            'has_logo' => $identity?->logo_path !== null,
            'logo_url' => $identity?->logo_path === null ? null : route('settings.school-identity.logo'),
            // The header, already assembled: a caller that only wants to print
            // it should not have to work out which pieces exist.
            'header_lines' => $this->headerLines($identity),
            // Whether anything at all has been configured, so a screen can say
            // «ainda não configurado» instead of showing a blank letterhead.
            'is_configured' => $identity !== null && ! $identity->isEmpty(),
        ];
    }

    /**
     * The address and contact lines a header would print, with nothing empty
     * in them.
     *
     * THREE LINES AT MOST, AND NEVER FOUR (§50). A letterhead identifies; it
     * does not describe. The address and the locality share a line, the phone
     * and the email share the next, and the site closes. Giving every field a
     * line of its own — the previous shape — spent a fifth of a relatório de
     * turma's first page before the document had said anything.
     *
     * «1000-001 Lisboa» is one half of the address line when both parts exist
     * and either part alone when only one does — never «1000-001 ·» with a gap
     * after it.
     *
     * @return list<string>
     */
    protected function headerLines(?OrganizationIdentity $identity): array
    {
        if ($identity === null) {
            return [];
        }

        $lines = [];

        $place = array_filter([$this->clean($identity->postal_code), $this->clean($identity->locality)]);

        $address = array_filter([
            // An address is often typed as the first line of two and ends with
            // a comma; joined with a middot that reads «Rua das Escolas, 12, ·
            // 1000-001 Lisboa». The separator does the punctuation's work.
            $this->trimTrailingPunctuation($this->clean($identity->address)),
            $place === [] ? null : implode(' ', $place),
        ]);

        if ($address !== []) {
            $lines[] = implode(' · ', $address);
        }

        $contacts = array_filter([$this->clean($identity->phone), $this->clean($identity->email)]);

        if ($contacts !== []) {
            $lines[] = implode(' · ', $contacts);
        }

        $website = $this->displayWebsite($this->clean($identity->website));

        if ($website !== null) {
            $lines[] = $website;
        }

        return $lines;
    }

    /**
     * A site as a letterhead prints it: «aeexemplo.pt», not
     * «https://aeexemplo.pt/».
     *
     * A PDF is not clickable paper and the scheme carries nothing a reader
     * needs. The stored value is untouched — this is presentation, and
     * `website` still leaves here in full for anything that follows it.
     */
    protected function displayWebsite(?string $website): ?string
    {
        if ($website === null) {
            return null;
        }

        $display = rtrim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $website), '/');

        return $display === '' ? $website : $display;
    }

    /** A trailing comma or semicolon that a middot is about to make redundant. */
    protected function trimTrailingPunctuation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = rtrim($value, " \t,;·-");

        return $trimmed === '' ? null : $trimmed;
    }

    /** An empty string is «not filled in», which is the same as null. */
    protected function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
