<?php

namespace Tests\Feature;

use App\Models\Entreprise;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SimulationTest extends TestCase
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
            'nom' => 'Collier',
            'prix' => 5000,
            'stock' => $stock,
        ]);
    }

    public function test_une_question_catalogue_renvoie_les_produits_sans_creer_de_commande(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $this->creerProduit($entreprise, 10);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'Voir les produits',
                'canal' => 'Messenger',
            ])
            ->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Voici nos produits'))
            ->assertJsonPath('commande', null)
            ->assertJsonCount(1, 'produits')
            ->assertJsonPath('client.nom', 'Client Messenger');

        $this->assertSame(0, $entreprise->commandes()->count());
    }

    public function test_une_question_prix_renvoie_le_prix_du_produit(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $this->creerProduit($entreprise, 10);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'Combien coûte le collier ?',
                'canal' => 'Messenger',
            ])
            ->assertOk()
            ->assertJsonPath('commande', null)
            ->assertJsonCount(1, 'produits')
            ->assertJsonPath('produits.0.nom', 'Collier')
            ->assertJsonPath('produits.0.prix', 5000);

        $this->assertSame(0, $entreprise->commandes()->count());
    }

    public function test_une_commande_est_confirmee_stock_preleve_facture_generee_et_notification(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $produit = $this->creerProduit($entreprise, 10);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'je veux 2 colliers',
                'canal' => 'Messenger',
            ])
            ->assertOk()
            ->assertJsonPath('commande.statut', 'confirmee')
            ->assertJsonPath('commande.montant_total', 10000)
            ->assertJsonPath('commande.facture_numero', fn ($n) => is_string($n) && str_starts_with($n, 'FAC-'));

        $commande = $entreprise->commandes()->firstOrFail();

        $this->assertSame('confirmee', $commande->statut);
        $this->assertSame(8, (int) $produit->refresh()->stock);
        $this->assertSame(2, (int) $commande->items()->first()->quantite);
        $this->assertNotNull($commande->facture);

        $this->assertDatabaseHas('notifications_internes', [
            'entreprise_id' => $entreprise->id,
            'type' => 'nouvelle_commande',
        ]);
    }

    public function test_stock_insuffisant_pas_de_commande_et_message_de_rupture(): void
    {
        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $this->creerProduit($entreprise, 1);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'je veux 2 colliers',
                'canal' => 'Messenger',
            ])
            ->assertOk()
            ->assertJsonPath('commande', null)
            ->assertJsonPath('ruptures', fn ($r) => count($r) === 1)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'pas disponibles'));

        $this->assertSame(0, $entreprise->commandes()->count());
    }

    public function test_l_index_des_notifications_renvoie_la_notification_de_nouvelle_commande(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $this->creerProduit($entreprise, 10);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'je veux 1 collier',
                'canal' => 'Messenger',
            ])
            ->assertOk();

        $this->actingAs($gerant, 'sanctum')
            ->getJson("/api/auth/entreprises/{$entreprise->id}/notifications")
            ->assertOk()
            ->assertJsonFragment(['type' => 'nouvelle_commande']);
    }

    public function test_apres_archivage_de_la_derniere_commande_le_numero_ne_collisionne_pas(): void
    {
        Storage::fake('public');

        [$gerant, $entreprise] = $this->gerantAvecEntreprise();
        $this->creerProduit($entreprise, 10);

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'je veux 1 collier',
                'canal' => 'Messenger',
            ])
            ->assertOk();

        $archivee = $entreprise->commandes()->firstOrFail();
        $archivee->delete();

        $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/simulation/messages", [
                'message' => 'je veux 1 collier',
                'canal' => 'Messenger',
            ])
            ->assertOk()
            ->assertJsonPath('commande.numero', fn (string $n) => $n !== $archivee->numero);

        $this->assertSame(2, $entreprise->commandes()->withTrashed()->count());
    }
}
