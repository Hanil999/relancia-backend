<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #1f2937; }
        h1 { font-size: 20px; margin-bottom: 0; }
        .meta { color: #6b7280; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; }
        .total { text-align: right; font-size: 16px; font-weight: bold; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Facture {{ $numero }}</h1>
    <p class="meta">
        Commande {{ $commande->numero }} · {{ $commande->created_at->format('d/m/Y') }}<br>
        Client : {{ $commande->client->nom }}
        @if($commande->client->telephone) · {{ $commande->client->telephone }} @endif
    </p>

    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th>Quantité</th>
                <th>Prix unitaire</th>
                <th>Sous-total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($commande->items as $item)
            <tr>
                <td>{{ $item->produit_nom }}</td>
                <td>{{ $item->quantite }}</td>
                <td>{{ number_format((float) $item->prix_unitaire, 0, ',', ' ') }} FCFA</td>
                <td>{{ number_format((float) $item->sous_total, 0, ',', ' ') }} FCFA</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p class="total">Total : {{ number_format((float) $commande->montant_total, 0, ',', ' ') }} FCFA</p>
</body>
</html>
