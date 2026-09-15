<?php

namespace App\Services;

use App\Events\CommandeCreee;
use App\Events\CommandeMiseAJour;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommandeService
{
    public function __construct(
        private readonly CommandeParserService $parser,
        private readonly StockService $stock,
        private readonly NotificationService $notifications,
        private readonly InvoiceService $invoices,
        private readonly TelegramService $telegram,
    ) {
    }

    /**
     * Cœur de l'automatisation : transforme un message client en commande.
     * Le commerçant n'a rien à saisir — cf. spec Sprint 3.
     *
     * @return array{commande: ?Commande, non_reconnus: array<int, string>, ruptures: array<int, string>}
     */
    public function creerDepuisMessage(Entreprise $entreprise, Client $client, string $message, string $canal): array
    {
        $produits = $entreprise->produits()->get();

        $analyse = $this->parser->parser($message, $produits);

        // Le stock n'est pas prélevé à la création : il le sera à la confirmation.
        if (empty($analyse['reconnus'])) {
            return [
                'commande' => null,
                'non_reconnus' => $analyse['non_reconnus'],
                'ruptures' => [],
            ];
        }

        $commande = DB::transaction(function () use ($entreprise, $client, $message, $canal, $analyse) {
            $commande = Commande::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'numero' => Commande::genererNumero($entreprise->id),
                'canal' => $canal,
                'statut' => 'en_attente',
                'montant_total' => 0,
                'message_origine' => $message,
                'creee_automatiquement' => true,
            ]);

            $total = 0;

            foreach ($analyse['reconnus'] as $ligne) {
                $produit = $ligne['produit'];
                $quantite = $ligne['quantite'];
                $sousTotal = $produit->prix * $quantite;

                $commande->items()->create([
                    'produit_id' => $produit->id,
                    'produit_nom' => $produit->nom,
                    'quantite' => $quantite,
                    'prix_unitaire' => $produit->prix,
                    'sous_total' => $sousTotal,
                ]);

                $total += $sousTotal;
            }

            $commande->update(['montant_total' => $total]);

            return $commande;
        });

        $this->notifications->commandeCreee($commande);
        $this->diffuser(new CommandeCreee($commande));

        return [
            'commande' => $commande->load('items', 'client'),
            'non_reconnus' => $analyse['non_reconnus'],
            'ruptures' => [],
        ];
    }

    /**
     * Création manuelle (formulaire) — même circuit notifications que le flux auto.
     * Le stock n'est prélevé qu'à la confirmation de la commande.
     *
     * @param  array<int, array{produit_id: int, quantite: int}>  $lignes
     */
    public function creerManuellement(Entreprise $entreprise, Client $client, array $lignes, string $canal = 'Manuel'): Commande
    {
        $commande = DB::transaction(function () use ($entreprise, $client, $lignes, $canal) {
            $commande = Commande::create([
                'entreprise_id' => $entreprise->id,
                'client_id' => $client->id,
                'numero' => Commande::genererNumero($entreprise->id),
                'canal' => $canal,
                'statut' => 'en_attente',
                'montant_total' => 0,
                'creee_automatiquement' => false,
            ]);

            $total = 0;

            foreach ($lignes as $ligne) {
                $produit = $entreprise->produits()->findOrFail($ligne['produit_id']);
                $quantite = (int) $ligne['quantite'];

                $sousTotal = $produit->prix * $quantite;

                $commande->items()->create([
                    'produit_id' => $produit->id,
                    'produit_nom' => $produit->nom,
                    'quantite' => $quantite,
                    'prix_unitaire' => $produit->prix,
                    'sous_total' => $sousTotal,
                ]);

                $total += $sousTotal;
            }

            $commande->update(['montant_total' => $total]);

            return $commande;
        });

        $this->notifications->commandeCreee($commande);
        $this->diffuser(new CommandeCreee($commande));

        return $commande->load('items', 'client');
    }

    /**
     * Modification manuelle d'une commande : client, canal et lignes.
     * Le stock n'est ajusté que si la commande était déjà confirmée (stock prélevé) :
     * on restitue les anciennes lignes puis on prélève les nouvelles. Une commande
     * en attente ou annulée voit ses lignes remplacées sans toucher au stock.
     *
     * @param  array<int, array{produit_id: int, quantite: int}>  $lignes
     */
    public function modifier(Commande $commande, Client $client, array $lignes, string $canal): Commande
    {
        DB::transaction(function () use ($commande, $client, $lignes, $canal) {
            $anciens = $commande->items()->get()->keyBy('produit_id');
            $confirmee = in_array($commande->statut, ['confirmee', 'expediee', 'livree'], true);

            $besoins = [];
            foreach ($lignes as $ligne) {
                $produitId = (int) $ligne['produit_id'];
                $besoins[$produitId] = ($besoins[$produitId] ?? 0) + (int) $ligne['quantite'];
            }

            foreach ($besoins as $produitId => $besoin) {
                $produit = $commande->entreprise->produits()->findOrFail($produitId);
                $rendu = $confirmee ? ($anciens[$produitId]->quantite ?? 0) : 0;
                $delta = $besoin - $rendu;

                if ($delta > 0 && ! $this->stock->disponible($produit, $delta)) {
                    abort(422, "Stock insuffisant pour {$produit->nom}.");
                }
            }

            foreach ($commande->items()->get() as $item) {
                if ($confirmee && $item->produit) {
                    $this->stock->incrementer($item->produit, $item->quantite);
                }
                $item->delete();
            }

            $total = 0;

            foreach ($lignes as $ligne) {
                $produit = $commande->entreprise->produits()->findOrFail($ligne['produit_id']);
                $quantite = (int) $ligne['quantite'];
                $sousTotal = $produit->prix * $quantite;

                $commande->items()->create([
                    'produit_id' => $produit->id,
                    'produit_nom' => $produit->nom,
                    'quantite' => $quantite,
                    'prix_unitaire' => $produit->prix,
                    'sous_total' => $sousTotal,
                ]);

                if ($confirmee) {
                    $this->stock->decrementer($produit, $quantite);
                }

                $total += $sousTotal;
            }

            $commande->update([
                'client_id' => $client->id,
                'canal' => $canal,
                'montant_total' => $total,
            ]);
        });

        $this->diffuser(new CommandeMiseAJour($commande->fresh(['items', 'client', 'facture'])));

        return $commande->load('items', 'client');
    }

    /**
     * Change le statut d'une commande et déclenche les effets de bord associés.
     * Le stock est prélevé à la confirmation (bloquant si insuffisant) et restitué
     * à l'annulation d'une commande qui avait été confirmée. La facture est
     * générée à la confirmation.
     */
    public function changerStatut(Commande $commande, string $statut): Commande
    {
        abort_unless(in_array($statut, Commande::STATUTS, true), 422, 'Statut invalide.');

        $ancienStatut = $commande->statut;
        $etaitConfirmee = in_array($commande->statut, ['confirmee', 'expediee', 'livree'], true);

        if ($statut === 'confirmee' && ! $etaitConfirmee) {
            $pct = $commande->entreprise->acompte_pct ?? 50;
            $montantRequis = (float) $commande->montant_total * $pct / 100;
            $montantPaye = (float) $commande->paiements->where('statut', 'paye')->sum('montant');

            if ($montantPaye < $montantRequis) {
                $payeFmt = number_format($montantPaye, 0, ',', ' ');
                $requisFmt = number_format($montantRequis, 0, ',', ' ');
                abort(422, "Acompte insuffisant : {$payeFmt} Ar paye sur {$requisFmt} Ar requis ({$pct}%).");
            }

            foreach ($commande->items as $item) {
                if ($item->produit && ! $this->stock->disponible($item->produit, $item->quantite)) {
                    abort(422, "Stock insuffisant pour {$item->produit_nom}.");
                }
            }
        }

        DB::transaction(function () use ($commande, $statut, $etaitConfirmee) {
            $commande->statut = $statut;

            match ($statut) {
                'confirmee' => $commande->confirmee_le = now(),
                'expediee' => $commande->expediee_le = now(),
                'livree' => $commande->livree_le = now(),
                'annulee' => $commande->annulee_le = now(),
                default => null,
            };

            $commande->save();

            if ($statut === 'confirmee' && ! $etaitConfirmee) {
                foreach ($commande->items as $item) {
                    if ($item->produit) {
                        $this->stock->decrementer($item->produit, $item->quantite);
                    }
                }
            }

            if ($statut === 'annulee' && $etaitConfirmee) {
                foreach ($commande->items as $item) {
                    if ($item->produit) {
                        $this->stock->incrementer($item->produit, $item->quantite);
                    }
                }
            }
        });

        if ($statut === 'confirmee' && ! $commande->facture) {
            $this->invoices->genererPourCommande($commande);
        }

        if ($statut === 'confirmee' && ! $etaitConfirmee) {
            app(RelanciaComptabiliteService::class)->facturerCommande($commande);
            $this->notifierClientStatut($commande, 'confirmee');
        }

        if ($statut === 'annulee') {
            $this->notifications->commandeAnnulee($commande);
            $this->notifierClientStatut($commande, 'annulee');
        }

        if ($statut === 'livree' && $ancienStatut !== 'livree') {
            $this->notifierClientStatut($commande, 'livree');
        }

        $this->diffuser(new CommandeMiseAJour($commande->fresh(['items', 'client', 'facture'])));

        return $commande;
    }

    private function notifierClientStatut(Commande $commande, string $statut): void
    {
        $canal = $commande->entreprise->canalTelegram();
        if (! $canal || ! $canal->actif) {
            return;
        }

        $chatId = $commande->client?->entreprises()
            ->where('entreprises.id', $commande->entreprise_id)
            ->first()?->pivot?->identifiant_social
            ?? (string) $commande->client?->identifiant_externe;

        if (! $chatId) {
            $dernierMessage = MessageCanal::where('entreprise_id', $commande->entreprise_id)
                ->where('client_id', $commande->client_id)
                ->where('canal', 'Telegram')
                ->latest()
                ->first();
            $chatId = $dernierMessage?->conversation_id;
        }

        if (! $chatId) {
            return;
        }

        $items = $commande->items->map(fn ($i) => "{$i->quantite}x {$i->produit_nom}")->implode(', ');
        $montant = number_format((float) $commande->montant_total, 0, ',', ' ');
        $boutonUrl = null;

        $texte = match ($statut) {
            'confirmee' => "Votre commande {$commande->numero} est confirmée ! ({$items} — {$montant} Ar). Préparation en cours.",
            'annulee' => "Votre commande {$commande->numero} a été annulée.",
            'livree' => "Votre commande {$commande->numero} a été livrée.",
            default => null,
        };

        if (! $texte) {
            return;
        }

        if ($statut === 'livree') {
            $reste = (float) $commande->reste_a_payer;
            if ($reste > 0) {
                $texte .= "\n\nSolde restant à régler : " . number_format($reste, 0, ',', ' ') . " Ar.";
                try {
                    $stripe = app(StripeService::class);
                    if ($stripe->estConfigure()) {
                        $sessionData = $stripe->creerSessionCheckout($commande, $reste);
                        $boutonUrl = $sessionData['url'];
                    }
                } catch (\Throwable) {
                    $boutonUrl = null;
                }
            } else {
                $texte .= "\n\nCommande intégralement réglée. Merci !";
            }
        }

        try {
            $this->telegram->envoyerMessage($canal, $chatId, $texte, $boutonUrl);

            MessageCanal::create([
                'entreprise_id' => $commande->entreprise_id,
                'client_id' => $commande->client_id,
                'canal' => 'Telegram',
                'direction' => 'sortant',
                'texte' => $texte,
                'conversation_id' => $chatId,
                'source' => 'auto',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification confirmation client Telegram echouee', [
                'commande_id' => $commande->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private function diffuser(object $event): void
    {
        try {
            event($event);
        } catch (BroadcastException $e) {
            report($e);
        }
    }
}
