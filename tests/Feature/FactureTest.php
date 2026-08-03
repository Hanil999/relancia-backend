<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Entreprise;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FactureTest extends TestCase
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

    private function creerCommandeConfirmee(User $gerant, Entreprise $entreprise): \App\Models\Commande
    {
        $produit = Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Produit Test',
            'prix' => 5000,
            'stock' => 10,
        ]);

        $client = Client::create(['nom' => 'Client Test']);
        $entreprise->clients()->attach($client->id);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 2]],
            ])
            ->assertStatus(201);

        $commande = $entreprise->commandes()->firstOrFail();

        $this->actingAs($gerant, 'sanctum')
            ->patchJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/statut", [
                'statut' => 'confirmee',
            ])
            ->assertOk();

        return $commande->refresh();
    }

    public function test_generer_une_facture_necessite_une_commande_confirmee(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Produit Test',
            'prix' => 5000,
            'stock' => 10,
        ]);
        $client = Client::create(['nom' => 'Client Test']);
        $entreprise->clients()->attach($client->id);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes", [
                'client_id' => $client->id,
                'canal' => 'Manuel',
                'items' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ])
            ->assertStatus(201);

        $commande = $entreprise->commandes()->firstOrFail();

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/facture")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La facture ne peut être générée que pour une commande confirmée.');
    }

    public function test_la_confirmation_genere_la_facture_et_le_telechargement_fonctionne(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $commande = $this->creerCommandeConfirmee($gerant, $entreprise);

        $this->assertNotNull($commande->facture, 'La confirmation doit générer une facture.');

        $this->actingAs($gerant, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/commandes")
            ->assertOk()
            ->assertJsonPath('data.0.facture.numero', $commande->facture->numero);

        $this->actingAs($gerant, 'sanctum')
            ->get("/api/auth/entreprises/{$entreprise->id}/factures/{$commande->facture->id}/telecharger")
            ->assertStatus(200);

        // Une facture existante ne peut pas être régénérée.
        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/facture")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette commande a déjà une facture.');
    }

    public function test_generer_manuellement_une_facture_pour_une_commande_sans_facture(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $commande = $this->creerCommandeConfirmee($gerant, $entreprise);

        $commande->facture()->delete();

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/facture")
            ->assertStatus(201)
            ->assertJsonPath('data.commande_id', $commande->id);

        $this->assertNotNull($commande->refresh()->facture);
    }

    public function test_lister_les_factures_avec_statut_de_paiement(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $commande = $this->creerCommandeConfirmee($gerant, $entreprise);

        $this->actingAs($gerant, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/factures")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', $commande->facture->numero)
            ->assertJsonPath('data.0.statut', 'En attente');

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/paiements", [
                'montant' => $commande->montant_total,
                'methode' => 'especes',
            ])
            ->assertStatus(201);

        $this->actingAs($gerant, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/factures")
            ->assertOk()
            ->assertJsonPath('data.0.statut', 'Payée');
    }
}
