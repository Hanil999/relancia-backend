<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement reçu</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #f5f0ff 0%, #f8fafc 100%);
            color: #1e293b;
            display: flex;
            justify-content: center;
            padding: 32px 16px;
        }
        .carte {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(30, 41, 59, 0.12);
            overflow: hidden;
        }
        .entete {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #fff;
            padding: 28px 28px 24px;
            text-align: center;
        }
        .entete h1 { margin: 8px 0 4px; font-size: 22px; }
        .entete p { margin: 0; opacity: 0.9; font-size: 14px; }
        .icone {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
            color: #bbf7d0;
        }
        .corps { padding: 24px 28px 28px; }
        .section-titre {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6b7280;
            margin: 18px 0 8px;
        }
        .infos { font-size: 14px; line-height: 1.7; }
        .infos strong { color: #334155; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; color: #475569; }
        .sous-total { text-align: right; white-space: nowrap; }
        .montant-paye {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding: 14px 16px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            color: #166534;
            font-weight: 600;
        }
        .solde {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            padding: 12px 16px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            color: #92400e;
            font-size: 14px;
        }
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
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
        .outline { border: 1px solid #cbd5e1; color: #475569; }
        .outline:hover { background: #f8fafc; }
        .pied {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            margin-top: 18px;
        }
        .pied svg { color: #d4d4d8; width: 14px; height: 14px; }
    </style>
</head>
<body>
    <div class="carte">
        <div class="entete">
            <span class="icone">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <path d="m9 11 3 3L22 4"/>
                </svg>
            </span>
            <h1>Paiement reçu</h1>
            <p>
                Votre commande {{ $commande->numero }} sera livrée dans quelques heures.
            </p>
        </div>

        <div class="corps">
            <div class="section-titre">Récapitulatif</div>
            <div class="infos">
                <div><strong>Client :</strong> {{ $commande->client->nom }}
                    @if($commande->client->telephone) · {{ $commande->client->telephone }} @endif
                </div>
                <div><strong>Entreprise :</strong>
                    @if($commande->entreprise?->nom) {{ $commande->entreprise->nom }} @endif
                </div>
                <div><strong>Date du paiement :</strong> {{ $paiement->created_at->format('d/m/Y à H:i') }}</div>
                @if($commande->facture)
                    <div><strong>Facture :</strong> {{ $commande->facture->numero }}</div>
                @endif
            </div>

            <div class="section-titre">Articles</div>
            <table>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Qté</th>
                        <th class="sous-total">Sous-total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($commande->items as $item)
                        <tr>
                            <td>{{ $item->produit_nom }}</td>
                            <td>{{ $item->quantite }}</td>
                            <td class="sous-total">{{ number_format((float) $item->sous_total, 0, ',', ' ') }} Ar</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="montant-paye">
                <span>Montant réglé</span>
                <span>{{ number_format((float) $paiement->montant, 0, ',', ' ') }} Ar</span>
            </div>

            @if($reste > 0)
                <div class="solde">
                    <span>Solde restant</span>
                    <span>{{ number_format($reste, 0, ',', ' ') }} Ar</span>
                </div>
            @endif

            <div class="actions">
                @if($lienFacture)
                    <a class="primary" href="{{ $lienFacture }}">
                        <svg style="display:inline;vertical-align:-3px;margin-right:6px;width:16px;height:16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14,2 14,8 20,8"/><path d="m10 13-2 2 2 2"/><path d="m14 17 2-2-2-2"/></svg>
                        Télécharger la facture
                    </a>
                @endif

                @if($reste > 0 && $lienSolde)
                    <a class="primary" href="{{ $lienSolde }}" style="background:#b45309">
                        <svg style="display:inline;vertical-align:-3px;margin-right:6px;width:16px;height:16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/></svg>
                        Payer le solde restant ({{ number_format($reste, 0, ',', ' ') }} Ar)
                    </a>
                @endif

                @if($lienConversation)
                    <a class="outline" href="{{ $lienConversation }}">Retour à la conversation</a>
                @endif
            </div>

            <div class="pied">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                Merci pour votre confiance
            </div>
        </div>
    </div>
</body>
</html>