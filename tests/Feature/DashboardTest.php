<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Entreprise} */
    private function gerantAvecEntreprise(): array
    {
        Role::findOrCreate('gerant');

        $gerant = User::factory()->create();
        $gerant->assignRole('gerant');

        $entreprise = $gerant->entrepriseGeree()->create([
            'nom' => 'Boutique Stats',
            'actif' => true,
        ]);

        return [$gerant, $entreprise];
    }

    private function creerCommande(Entreprise $entreprise, string $statut, int $montant, string $canal = 'Messenger'): Commande
    {
        $client = $entreprise->clients()->create(['nom' => 'Client Test']);

        return $entreprise->commandes()->create([
            'client_id' => $client->id,
            'numero' => Commande::genererNumero($entreprise->id),
            'canal' => $canal,
            'statut' => $statut,
            'montant_total' => $montant,
        ]);
    }

    public function test_les_stats_sont_calculees_depuis_les_donnees_reelles(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();

        Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Collier',
            'prix' => 5000,
            'stock' => 0,
        ]);
        Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Bracelet',
            'prix' => 3000,
            'stock' => 2,
        ]);

        $this->creerCommande($entreprise, 'confirmee', 15000, 'Messenger');
        $this->creerCommande($entreprise, 'confirmee', 8000, 'WhatsApp');
        $this->creerCommande($entreprise, 'annulee', 99999, 'Messenger');

        $this->actingAs($gerant, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/stats")
            ->assertOk()
            ->assertJsonPath('ventes_du_jour', 23000)
            ->assertJsonPath('commandes_du_jour', 3)
            ->assertJsonPath('total_commandes', 3)
            ->assertJsonPath('ca_total', 23000)
            ->assertJsonPath('clients', 3)
            ->assertJsonPath('produits_actifs', 2)
            ->assertJsonPath('rupture_stock', 1)
            ->assertJsonPath('stock_faible', 1)
            ->assertJsonPath('canaux.0.canal', 'Messenger')
            ->assertJsonPath('canaux.0.commandes', 2)
            ->assertJsonPath('statuts.1.statut', 'annulee')
            ->assertJsonCount(7, 'ventes_7_jours')
            ->assertJsonPath('ventes_7_jours.6.commandes', 3);
    }

    public function test_les_stats_sont_interdites_sans_acces(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();

        $autre = User::factory()->create();

        $this->actingAs($autre, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/stats")
            ->assertForbidden();
    }
}
