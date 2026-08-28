<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ver as classes: sem isto, quem entra vai sempre parar ao painel, e a
        // razão que o trouxe à aplicação fica para trás.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);

        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        /*
         * A deactivated account never completes a login.
         *
         * EnsureUserIsActive already ends the session of anyone deactivated
         * while inside, and covers passkeys and the "remember me" cookie. This
         * closes the front door too, so the form does not appear to succeed for
         * one request before bouncing them.
         *
         * The password is checked FIRST and the refusal comes after: someone who
         * does not know the password learns nothing about whether the address
         * exists, while the person who does know it gets told plainly what
         * happened instead of being left to guess at "credenciais inválidas".
         */
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->input(Fortify::username()))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->isDeactivated()) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('A sua conta foi desativada. Contacte o suporte do Lapispro.'),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(function (Request $request) {
            // `pull` rather than `flash`: this page can be reached after one or
            // two redirects (register -> dashboard -> here, since a fresh
            // account is unverified), and flash data only survives exactly one
            // request. A plain session value read-and-cleared on whichever
            // request actually renders this page is what "show it once" means
            // here — see User::sendEmailVerificationNotification().
            $sendFailed = (bool) $request->session()->pull('verification_send_failed', false);

            return Inertia::render('auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
                'sendFailed' => $sendFailed,
            ]);
        });

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'status' => $request->session()->get('status'),
            // Set by InvitationAcceptanceController when the invited address
            // has no account yet — prefilled, never locked, so someone free
            // to use a different address for their account still can.
            'prefillEmail' => $request->session()->get('invitation_email'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
