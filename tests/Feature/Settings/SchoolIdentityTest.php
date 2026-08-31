<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\OrganizationIdentity;
use App\Models\User;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\SchoolLogoService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The school as it will appear on a document.
 *
 * Nothing here renders a report — what these assert is that the letterhead is
 * stored once, isolated per organization, and handed to a future document
 * already assembled, so no module has to invent this again.
 */
class SchoolIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    private function organization(): Organization
    {
        return $this->user->personalOrganization();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'official_name' => 'Agrupamento de Escolas de Exemplo',
            'address' => 'Rua das Escolas, 12',
            'postal_code' => '1000-001',
            'locality' => 'Lisboa',
            'phone' => '+351 210 000 000',
            'email' => 'geral@aeexemplo.pt',
            'website' => 'aeexemplo.pt',
        ], $overrides);
    }

    // ------------------------------------------------- 1. guardar os dados

    #[Test]
    public function the_school_name_address_and_contacts_are_saved(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/school-identity', $this->payload())
            ->assertRedirect();

        $identity = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame('Agrupamento de Escolas de Exemplo', $identity->official_name);
        $this->assertSame('Rua das Escolas, 12', $identity->address);
        $this->assertSame('1000-001', $identity->postal_code, 'o código postal é texto, e o zero à esquerda sobrevive');
        $this->assertSame('Lisboa', $identity->locality);
        $this->assertSame('+351 210 000 000', $identity->phone);
        $this->assertSame('geral@aeexemplo.pt', $identity->email);
    }

    #[Test]
    public function every_field_is_optional(): void
    {
        // A school known by name today and by NIF next week must be able to
        // save what it knows.
        $this->actingAs($this->user)
            ->put('/settings/school-identity', ['official_name' => 'Escola Básica de Exemplo'])
            ->assertSessionHasNoErrors();

        $identity = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame('Escola Básica de Exemplo', $identity->official_name);
        $this->assertNull($identity->tax_number);
        $this->assertNull($identity->school_code);
        $this->assertNull($identity->address);
    }

    #[Test]
    public function an_empty_field_is_stored_as_absent_and_not_as_an_empty_string(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/school-identity', $this->payload(['locality' => '   ']))
            ->assertSessionHasNoErrors();

        $this->assertNull(OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->locality);
    }

    #[Test]
    public function what_the_school_typed_is_kept_as_they_typed_it(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/school-identity', $this->payload(['official_name' => '  Escola da Ponte  ']));

        // Trimmed, and nothing else: upper-casing a school's own name would be
        // the application overruling them.
        $this->assertSame(
            'Escola da Ponte',
            OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->official_name,
        );
    }

    #[Test]
    public function a_website_without_a_scheme_is_completed_rather_than_refused(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload(['website' => 'aeexemplo.pt']));

        $this->assertSame(
            'https://aeexemplo.pt',
            OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->website,
        );

        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload(['website' => 'http://aeexemplo.pt']));

        $this->assertSame(
            'http://aeexemplo.pt',
            OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->website,
        );
    }

    #[Test]
    public function an_invalid_email_is_refused(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/school-identity', $this->payload(['email' => 'nao-e-um-email']))
            ->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------ 2. o logótipo

    #[Test]
    public function a_valid_logo_is_stored_on_the_private_disk(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/school-identity/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
            ])
            ->assertSessionHasNoErrors();

        $identity = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail();

        $this->assertNotNull($identity->logo_path);
        $this->assertStringStartsWith(SchoolLogoService::DIRECTORY.'/', $identity->logo_path);
        Storage::disk(SchoolLogoService::DISK)->assertExists($identity->logo_path);
    }

    #[Test]
    public function the_stored_name_is_the_servers_and_never_the_browsers(): void
    {
        $this->actingAs($this->user)->post('/settings/school-identity/logo', [
            'logo' => UploadedFile::fake()->image('../../etc/passwd.png', 200, 200),
        ]);

        $path = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->logo_path;

        $this->assertStringNotContainsString('passwd', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    #[Test]
    public function a_format_a_document_cannot_place_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/school-identity/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 40, 'application/pdf')])
            ->assertSessionHasErrors('logo');

        // SVG is a script container and is not cleared for use here.
        $this->actingAs($this->user)
            ->post('/settings/school-identity/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])
            ->assertSessionHasErrors('logo');

        $this->assertNull(OrganizationIdentity::withoutGlobalScope('organization')->first()?->logo_path);
    }

    #[Test]
    public function a_file_too_large_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/school-identity/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 400, 400)->size(SchoolLogoService::MAX_KILOBYTES + 1),
            ])
            ->assertSessionHasErrors('logo');
    }

    #[Test]
    public function replacing_the_logo_drops_the_previous_file(): void
    {
        $this->actingAs($this->user)->post('/settings/school-identity/logo', [
            'logo' => UploadedFile::fake()->image('primeiro.png', 300, 300),
        ]);

        $first = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->logo_path;

        $this->actingAs($this->user)->post('/settings/school-identity/logo', [
            'logo' => UploadedFile::fake()->image('segundo.png', 300, 300),
        ]);

        $second = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk(SchoolLogoService::DISK)->assertMissing($first);
        Storage::disk(SchoolLogoService::DISK)->assertExists($second);
    }

    #[Test]
    public function removing_the_logo_clears_the_reference_and_the_file_and_nothing_else(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload());
        $this->actingAs($this->user)->post('/settings/school-identity/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]);

        $path = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail()->logo_path;

        $this->actingAs($this->user)->delete('/settings/school-identity/logo')->assertRedirect();

        $identity = OrganizationIdentity::withoutGlobalScope('organization')->firstOrFail();

        $this->assertNull($identity->logo_path);
        Storage::disk(SchoolLogoService::DISK)->assertMissing($path);
        // Everything else survives.
        $this->assertSame('Agrupamento de Escolas de Exemplo', $identity->official_name);
        $this->assertSame('Lisboa', $identity->locality);
    }

    // ------------------------------------------------- 3. o isolamento

    #[Test]
    public function one_school_never_reads_or_overwrites_anothers_identity(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload());

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put('/settings/school-identity', $this->payload(['official_name' => 'Escola Intrusa']))
            ->assertSessionHasNoErrors();

        // Two rows, one each, neither touched by the other.
        $this->assertSame(2, OrganizationIdentity::withoutGlobalScope('organization')->count());

        $mine = app(CurrentOrganization::class)->runFor(
            $this->organization(),
            fn (): ?OrganizationIdentity => OrganizationIdentity::first(),
        );

        $this->assertSame('Agrupamento de Escolas de Exemplo', $mine->official_name);
    }

    #[Test]
    public function a_stranger_cannot_stream_another_schools_logo(): void
    {
        $this->actingAs($this->user)->post('/settings/school-identity/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]);

        // The route resolves the logo from the CALLER's own organization, so a
        // stranger asking for it gets their own — which is nothing.
        $this->actingAs(User::factory()->create())
            ->get('/settings/school-identity/logo')
            ->assertNotFound();

        $this->actingAs($this->user)->get('/settings/school-identity/logo')->assertOk();
    }

    // ------------------------------------------------- 4. as permissões

    #[Test]
    public function a_member_who_does_not_own_the_organization_may_look_but_not_change(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload());

        $colleague = User::factory()->create();
        $this->organization()->members()->attach($colleague->getKey(), ['joined_at' => now()]);

        // Reading is fine: they are building documents that carry this header.
        $this->actingAs($colleague)
            ->withSession(['current_organization_id' => $this->organization()->id])
            ->get('/settings/school-identity');

        // Writing is the owner's.
        $this->assertFalse($colleague->can('updateIdentity', $this->organization()));
        $this->assertTrue($colleague->can('viewIdentity', $this->organization()));
        $this->assertTrue($this->user->can('updateIdentity', $this->organization()));
    }

    #[Test]
    public function somebody_outside_the_organization_can_do_neither(): void
    {
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can('viewIdentity', $this->organization()));
        $this->assertFalse($stranger->can('updateIdentity', $this->organization()));
    }

    // --------------------------------------------- 5. o read model

    #[Test]
    public function the_document_identity_hands_over_a_finished_header(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload());

        $identity = app(CurrentOrganization::class)->runFor(
            $this->organization(),
            fn (): array => app(DocumentIdentity::class)->forCurrentOrganization(),
        );

        $this->assertSame('Agrupamento de Escolas de Exemplo', $identity['name']);
        $this->assertTrue($identity['is_configured']);
        // Already assembled, with nothing empty in it — and condensed: the
        // address and the locality share a line, the phone and the email share
        // the next, and the site closes without its scheme (§50).
        $this->assertSame([
            'Rua das Escolas, 12 · 1000-001 Lisboa',
            '+351 210 000 000 · geral@aeexemplo.pt',
            'aeexemplo.pt',
        ], $identity['header_lines']);
    }

    #[Test]
    public function a_school_that_configured_nothing_still_gets_a_name_and_no_invented_address(): void
    {
        $identity = app(CurrentOrganization::class)->runFor(
            $this->organization(),
            fn (): array => app(DocumentIdentity::class)->forCurrentOrganization(),
        );

        // The organization's own name is the floor — never a made-up school.
        $this->assertSame($this->organization()->name, $identity['name']);
        $this->assertFalse($identity['is_configured']);
        $this->assertSame([], $identity['header_lines']);
        $this->assertNull($identity['address']);
        $this->assertFalse($identity['has_logo']);
    }

    #[Test]
    public function a_missing_contact_never_leaves_an_empty_line_in_the_header(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', [
            'official_name' => 'Escola Básica de Exemplo',
            'locality' => 'Porto',
            'email' => 'geral@escola.pt',
        ]);

        $identity = app(CurrentOrganization::class)->runFor(
            $this->organization(),
            fn (): array => app(DocumentIdentity::class)->forCurrentOrganization(),
        );

        // No address, no postal code and no phone: «Porto» stands alone and the
        // contact line carries only the email — never «· » with a gap.
        $this->assertSame(['Porto', 'geral@escola.pt'], $identity['header_lines']);
    }

    // ------------------------------------------------------- 6. a página

    #[Test]
    public function the_page_carries_the_identity_the_preview_and_the_permission(): void
    {
        $this->actingAs($this->user)->put('/settings/school-identity', $this->payload());

        $this->actingAs($this->user)
            ->get('/settings/school-identity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/SchoolIdentity')
                ->where('identity.official_name', 'Agrupamento de Escolas de Exemplo')
                ->where('preview.is_configured', true)
                ->has('preview.header_lines', 3)
                ->where('canEdit', true));
    }
}
