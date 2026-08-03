<!DOCTYPE html>
<html lang="fr">
<body style="font-family: sans-serif; color: #1f2937;">
    <p>Bonjour {{ $facture->commande->client->nom }},</p>

    <p>
        Merci pour votre commande <strong>{{ $facture->commande->numero }}</strong>.
        Vous trouverez votre facture <strong>{{ $facture->numero }}</strong> en pièce jointe.
    </p>

    <p>Montant total : <strong>{{ number_format((float) $facture->commande->montant_total, 0, ',', ' ') }} FCFA</strong></p>

    <p>À très bientôt !</p>
</body>
</html>
