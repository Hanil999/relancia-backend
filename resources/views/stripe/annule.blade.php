<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement non abouti</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #fef9c3 0%, #f8fafc 100%);
            color: #1e293b;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 32px 16px;
        }
        .carte {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(30, 41, 59, 0.12);
            padding: 32px 28px;
            text-align: center;
        }
        .icone {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #fef3c7;
            color: #d97706;
            margin-bottom: 12px;
        }
        h1 { font-size: 20px; margin: 0 0 8px; color: #92400e; }
        p { font-size: 14px; color: #64748b; line-height: 1.6; margin: 0; }
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .actions a {
            display: block;
            text-align: center;
            padding: 12px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .primary { background: #7c3aed; color: #fff; }
        .primary:hover { background: #6d28d9; }
    </style>
</head>
<body>
    <div class="carte">
        <div class="icone">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/>
                <path d="M12 9v4"/>
                <path d="M12 17h.01"/>
            </svg>
        </div>
        <h1>Paiement non abouti</h1>
        <p>Votre paiement n'a pas été confirmé. Vous pouvez réessayer ou contacter le vendeur pour finaliser votre commande. Aucun montant n'a été débité.</p>
        @if($lienConversation)
            <div class="actions">
                <a class="primary" href="{{ $lienConversation }}">Retour à la conversation</a>
            </div>
        @endif
    </div>
</body>
</html>