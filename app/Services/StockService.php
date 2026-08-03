<?php

namespace App\Services;

use App\Models\Produit;

class StockService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    public function disponible(Produit $produit, int $quantite): bool
    {
        return $produit->stock >= $quantite;
    }

    /**
     * Décrémente le stock et déclenche une alerte si on passe sous le seuil.
     */
    public function decrementer(Produit $produit, int $quantite): void
    {
        $produit->decrement('stock', $quantite);
        $produit->refresh();

        if ($produit->stock <= $produit->seuil_alerte_stock) {
            $this->notifications->ruptureStock($produit);
        }
    }

    /**
     * Restitue le stock, typiquement lors de l'annulation d'une commande.
     */
    public function incrementer(Produit $produit, int $quantite): void
    {
        $produit->increment('stock', $quantite);
    }
}
