<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Entreprise;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GestionStockTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Entreprise} */
    private function gerantAvecEntreprise(): array
    {
        Role::findOrCreate('gerant');

        $gerant = User::factory()->create();
        $gerant->assignRole('gerant');

        $entreprise = $gerant->entrepriseGeree()->create([
            'nom' => 'Boutique Test',
            'actif' => true,
        ]);

        return [$gerant, $entreprise];
    }

    private function creerProduit(Entreprise $entreprise, int $stock): Produit
    {
        return Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Produit Test',
            'prix' => 5000,
            'stock' => $stock,
        ]);
    }

    private function creerClientAttache(Entreprise $entreprise): Client
    {
        $client = Client::create(['nom' => 'Client Test']);
        $entreprise->clients()->attach($client->id);

        return $client;
    }

    public function test_le_stock_est_preleve_a_la_confirmation_pas_a_la_creation(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 10);
        $client = $this->creerClientAttache($entreprise);

        // Création : le stock ne bouge pas (le prélèvement a lieu à la confirmation).
        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 2]],
            ])
            ->assertStatus(201);

        $this->assertSame(10, (int) $produit->refresh()->stock);

        $commande = $entreprise->commandes()->first();

        // Confirmation : stock prélevé.
        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'confirmee',
            ])
            ->assertOk();

        $this->assertSame(8, (int) $produit->refresh()->stock);

        // Annulation après confirmation : stock restitué.
        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'annulee',
            ])
            ->assertOk();

        $this->assertSame(10, (int) $produit->refresh()->stock);
    }

    public function test_annuler_une_commande_en_attente_ne_restaure_rien(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 10);
        $client = $this->creerClientAttache($entreprise);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 2]],
            ])
            ->assertStatus(201);

        $commande = $entreprise->commandes()->first();

        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'annulee',
            ])
            ->assertOk();

        $this->assertSame(10, (int) $produit->refresh()->stock);
    }

    public function test_confirmation_bloquee_si_stock_insuffisant(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 1);
        $client = $this->creerClientAttache($entreprise);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 3]],
            ])
            ->assertStatus(201);

        $commande = $entreprise->commandes()->first();

        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'confirmee',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuffisant pour Produit Test.');

        $this->assertSame(1, (int) $produit->refresh()->stock);
    }

    public function test_modifier_une_commande_confirmee_ajuste_le_stock_au_net(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 10);
        $client = $this->creerClientAttache($entreprise);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 2]],
            ])
            ->assertStatus(201);

        $commande = $entreprise->commandes()->first();

        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'confirmee',
            ])
            ->assertOk();

        $this->assertSame(8, (int) $produit->refresh()->stock);

        // Passage de 2 à 5 unités : net -3.
        $this->actingAs($gerant, 'sanctum')
            ->putJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 5]],
            ])
            ->assertOk();

        $this->assertSame(5, (int) $produit->refresh()->stock);
    }

    public function test_approvisionner_augmente_le_stock(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 3);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/entreprises/{$entreprise->id}/produits/{$produit->id}/approvisionner", [
                'quantite' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 10);

        $this->assertSame(10, (int) $produit->refresh()->stock);
    }

    public function test_approvisionner_exige_une_quantite_valide(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 3);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/entreprises/{$entreprise->id}/produits/{$produit->id}/approvisionner", [
                'quantite' => 0,
            ])
            ->assertStatus(422);

        $this->assertSame(3, (int) $produit->refresh()->stock);
    }
}
