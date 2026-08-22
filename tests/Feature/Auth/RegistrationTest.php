<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_an_ordinary_visit_has_no_prefilled_email(): void
    {
        $this->get(route('register'))->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('prefillEmail', null));
    }

    /**
     * `prefillEmail` becomes the email field's `:default-value` — a one-time seed
     * for the input, not a value Vue keeps re-imposing on every re-render. That
     * distinction is the whole point: binding it as a plain `:value` instead used
     * to make the field un-editable (every keystroke got wiped back to empty) the
     * moment ANY reactive state on the page changed. This only proves the prop
     * still reaches the page correctly; the editing behaviour itself is a client
     * concern, verified manually in the browser, not asserted here.
     */
    public function test_an_invited_email_is_prefilled_on_the_register_page(): void
    {
        session(['invitation_email' => 'convidada@escola.pt']);

        $this->get(route('register'))->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('prefillEmail', 'convidada@escola.pt'));
    }
}
