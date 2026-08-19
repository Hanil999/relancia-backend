<?php

namespace App\Services;

use App\Events\NotificationCreee;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\NotificationInterne;
use App\Models\Paiement;
use App\Models\Produit;
use Illuminate\Broadcasting\BroadcastException;

class NotificationService
{
    public function commandeCreee(Commande $commande): NotificationInterne
    {
        return $this->creer(
            $commande->entreprise_id,
            NotificationInterne::TYPE_NOUVELLE_COMMANDE,
            "Nouvelle commande {$commande->numero}",
            "Client : {$commande->client->nom} · Montant : " . number_format((float) $commande->montant_total, 0, ',', ' ') . ' Ar',
            ['commande_id' => $commande->id],
        );
    }

    public function commandeAnnulee(Commande $commande): NotificationInterne
    {
        return $this->creer(
            $commande->entreprise_id,
            NotificationInterne::TYPE_COMMANDE_ANNULEE,
            "Commande {$commande->numero} annulée",
            'Le stock des articles concernés a été restitué automatiquement.',
            ['commande_id' => $commande->id],
        );
    }

    public function paiementRecu(Paiement $paiement): NotificationInterne
    {
        $commande = $paiement->commande;

        return $this->creer(
            $commande->entreprise_id,
            NotificationInterne::TYPE_PAIEMENT_RECU,
            "Paiement reçu pour {$commande->numero}",
            number_format((float) $paiement->montant, 0, ',', ' ') . ' Ar via ' . $paiement->methode,
            ['commande_id' => $commande->id, 'paiement_id' => $paiement->id],
        );
    }

    public function ruptureStock(Produit $produit): NotificationInterne
    {
        return $this->creer(
            $produit->entreprise_id,
            NotificationInterne::TYPE_RUPTURE_STOCK,
            "Stock faible : {$produit->nom}",
            "Il reste {$produit->stock} unité(s) en stock.",
            ['produit_id' => $produit->id],
        );
    }

    public function nouveauClient(Entreprise $entreprise, Client $client): NotificationInterne
    {
        return $this->creer(
            $entreprise->id,
            NotificationInterne::TYPE_NOUVEAU_CLIENT,
            'Nouveau client',
            "{$client->nom} vient d'être ajouté.",
            ['client_id' => $client->id],
        );
    }

    public function messageRecu(Entreprise $entreprise, Client $client, string $texte, string $canal): NotificationInterne
    {
        $tronque = mb_strlen($texte) > 80 ? mb_substr($texte, 0, 80) . '…' : $texte;

        return $this->creer(
            $entreprise->id,
            NotificationInterne::TYPE_MESSAGE_RECU,
            "Nouveau message de {$client->nom}",
            "[$canal] {$tronque}",
            ['client_id' => $client->id, 'canal' => $canal],
        );
    }

    private function creer(int $entrepriseId, string $type, string $titre, string $message, array $data = []): NotificationInterne
    {
        $notification = NotificationInterne::create([
            'entreprise_id' => $entrepriseId,
            'type' => $type,
            'titre' => $titre,
            'message' => $message,
            'data' => $data,
        ]);

        try {
            broadcast(new NotificationCreee($notification))->toOthers();
        } catch (BroadcastException $e) {
            report($e);
        }

        return $notification;
    }
}
