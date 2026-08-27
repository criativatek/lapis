<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Convite — Lapispro') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:8px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="font-size:18px; margin:0 0 16px;">{{ __('Lapispro') }}</h1>
                            <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                {{ __(':inviter convidou-o para se juntar a :organization no Lapispro.', ['inviter' => $inviterName, 'organization' => $organizationName]) }}
                            </p>
                            <p style="text-align:center; margin:24px 0;">
                                <a href="{{ $acceptUrl }}" style="display:inline-block; background-color:#18181b; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:6px; font-size:15px;">
                                    {{ __('Aceitar convite') }}
                                </a>
                            </p>
                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0 0 8px;">
                                {{ __('Este convite expira a :date.', ['date' => $expiresAt->translatedFormat('d \d\e F \d\e Y')]) }}
                            </p>
                            <p style="font-size:13px; line-height:1.6; color:#71717a; margin:0;">
                                {{ __('Se não estava à espera deste email, pode ignorá-lo em segurança.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
