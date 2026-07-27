<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform SMTP settings — the system mail used for verification, password reset
 * and invites. Stored in the DB and applied over the .env at boot
 * (AppServiceProvider). The password is write-only from the UI.
 */
class AdminSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = PlatformSetting::current();

        return Inertia::render('admin/Settings', [
            'settings' => [
                'mail_mailer' => $settings->mail_mailer,
                'mail_host' => $settings->mail_host,
                'mail_port' => $settings->mail_port,
                'mail_username' => $settings->mail_username,
                'mail_encryption' => $settings->mail_encryption,
                'mail_from_address' => $settings->mail_from_address,
                'mail_from_name' => $settings->mail_from_name,
                'password_set' => filled($settings->mail_password),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_mailer' => ['required', 'string', 'max:50'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = PlatformSetting::current();

        // A blank password field keeps the stored one — never wipes it.
        $data = Arr::except($validated, 'mail_password');
        if (($validated['mail_password'] ?? '') !== '') {
            $data['mail_password'] = $validated['mail_password'];
        }
        $settings->fill($data)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Definições de email guardadas.')]);

        return back();
    }

    public function test(Request $request): RedirectResponse
    {
        $email = $request->user()->email;

        try {
            Mail::raw('Email de teste do LÁPIS — o SMTP está configurado corretamente.', fn ($message) => $message
                ->to($email)->subject('LÁPIS — teste de SMTP'));

            Inertia::flash('toast', ['type' => 'success', 'message' => __("Email de teste enviado para {$email}.")]);
        } catch (\Throwable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Falhou o envio: ').$exception->getMessage()]);
        }

        return back();
    }
}
