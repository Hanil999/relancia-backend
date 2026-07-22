<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Invitation Relancia</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f4f4f5; padding: 24px;">
    <table role="presentation" width="100%" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr>
            <td style="background: #6b21a8; padding: 24px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 20px;">Relancia</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px 24px;">
                <p>Bonjour {{ $nom }},</p>
                <p>
                    Vous avez été invité(e) à devenir gérant(e) de l'entreprise
                    <strong>{{ $entrepriseNom }}</strong> sur la plateforme Relancia.
                </p>
                <p style="text-align: center; margin: 32px 0;">
                    <a href="{{ $lienInvitation }}"
                       style="background: #6b21a8; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;">
                        Accepter l'invitation
                    </a>
                </p>
                <p style="color: #71717a; font-size: 13px;">
                    Cette invitation expire le {{ $expiresAt->translatedFormat('d F Y à H:i') }}.
                    Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
