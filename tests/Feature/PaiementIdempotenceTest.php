<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Paiement;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaiementIdempotenceTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Entreprise, Commande} */
    private function gerantAvecCommande(): array
    {
        Role::findOrCreate('gerant');

        $gerant = User::factory()->create();
        $gerant->assignRole('gerant');

        $entreprise = $gerant->entrepriseGeree()->create([
            'nom' => 'Boutique Test',
            'actif' => true,
        ]);

        $client = Client::create(['nom' => 'Client Test']);

        $commande = Commande::create([
            'entreprise_id' => $entreprise->id,
            'client_id' => $client->id,
            'numero' => Commande::genererNumero($entreprise->id),
            'canal' => 'Manuel',
            'statut' => 'confirmee',
            'montant_total' => 10000,
        ]);

        return [$gerant, $entreprise, $commande];
    }

    public function test_la_meme_cle_idempotence_ne_cree_qu_un_seul_paiement(): void
    {
        [$gerant, $entreprise, $commande] = $this->gerantAvecCommande();

        $payload = [
            'montant' => 5000,
            'methode' => 'especes',
            'reference' => 'RECU-001',
            'idempotency_key' => 'client-uuid-abc',
        ];

        $premierResp = $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/paiements", $payload);

        $secondResp = $this->actingAs($gerant, 'sanctum')
            ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/paiements", $payload);

        $premier = $premierResp->json();
        $second = $secondResp->json();

        $this->assertSame($premier['id'], $second['id'], 'La seconde requête doit renvoyer le paiement existant.');
        $this->assertSame(1, $commande->paiements()->count(), 'Un seul paiement doit exister pour cette clé.');
    }

    public function test_deux_cles_distinctes_creent_deux_paiements(): void
    {
        [$gerant, $entreprise, $commande] = $this->gerantAvecCommande();

        foreach (['uuid-1', 'uuid-2'] as $cle) {
            $this->actingAs($gerant, 'sanctum')
                ->postJson("/api/auth/entreprises/{$entreprise->id}/commandes/{$commande->id}/paiements", [
                    'montant' => 1000,
                    'methode' => 'especes',
                    'idempotency_key' => $cle,
                ])
                ->assertStatus(201);
        }

        $this->assertSame(2, $commande->paiements()->count());
    }

    public function test_la_meme_session_stripe_n_est_pas_dupliquee(): void
    {
        [$gerant, $entreprise, $commande] = $this->gerantAvecCommande();
        unset($gerant, $entreprise);

        $service = app(StripeService::class);

        $session = [
            'id' => 'cs_test_idempotent',
            'amount_total' => 10000,
            'payment_intent' => 'pi_test_idempotent',
            'metadata' => ['commande_id' => (string) $commande->id],
        ];

        $service->enregistrerPaiementSession($session);
        $service->enregistrerPaiementSession($session);

        $this->assertSame(1, $commande->paiements()->where('stripe_session_id', 'cs_test_idempotent')->count());
        $this->assertSame(1, $commande->paiements()->count());
    }
}