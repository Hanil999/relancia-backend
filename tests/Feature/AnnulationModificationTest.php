<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Entreprise;
use App\Models\Produit;
use App\Models\User;
use App\Services\CommandeService;
use App\Services\PaiementService;
use App\Services\ReponseAutomatiqueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Donne vie à trois règles transverses des canaux de vente :
 *  - le client peut annuler sa commande en cours ;
 *  - le client peut modifier sa dernière commande (elle garde son identifiant) ;
 *  - aucun message envoyé au client ne doit montrer le numéro de commande,
 *    seulement les produits commandés.
 */
class AnnulationModificationTest extends TestCase
{
    use DatabaseTransactions;

    private Entreprise $entreprise;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolation : aucun appel réseau réel (le .env du poste fuit dans les tests).
        config([
            'services.stripe.secret' => '',
            'services.goalpay.token' => '',
        ]);

        $this->entreprise = Entreprise::create([
            'gerant_id' => User::factory()->create()->id,
            'nom' => 'Boutique Test',
            'telephone' => '0340000000',
            'actif' => true,
        ]);

        $this->client = Client::create(['nom' => 'Client Test']);
        $this->entreprise->clients()->attach($this->client->id);

        $this->entreprise->produits()->create([
            'nom' => 'Bracelet',
            'prix' => 4000,
            'stock' => 10,
        ]);

        $this->entreprise->produits()->create([
            'nom' => 'Collier',
            'prix' => 3000,
            'stock' => 10,
        ]);
    }

    private function repondre(string $message, string $canal = 'Telegram'): array
    {
        return app(ReponseAutomatiqueService::class)->repondre(
            $this->entreprise,
            $this->client,
            $message,
            $canal,
        );
    }

    private function passerCommande(string $message): Commande
    {
        $this->repondre($message);

        return $this->client->commandes()->latest()->first();
    }

    private function payerAcompte(Commande $commande): void
    {
        app(PaiementService::class)->enregistrerPaiement(
            $commande,
            (float) $commande->montant_total * 50 / 100,
            'especes',
        );
    }

    private function confirmer(Commande $commande): void
    {
        $this->payerAcompte($commande);
        app(CommandeService::class)->changerStatut($commande, 'confirmee');
    }

    private function produit(string $nom): Produit
    {
        return Produit::where('entreprise_id', $this->entreprise->id)->where('nom', $nom)->first();
    }

    /**
     * Le client ne doit jamais recevoir le numéro de commande ni le préfixe « CMD ».
     */
    private function assertPasDeNumero(string $message, Commande $commande): void
    {
        $this->assertStringNotContainsString($commande->numero, $message);
        $this->assertStringNotContainsString('CMD', mb_strtoupper($message), 'Le numéro (CMD) ne doit pas être révélé au client.');
    }

    public function test_annulation_restaure_le_stock_dune_commande_confirmee(): void
    {
        $commande = $this->passerCommande('Je veux 1 bracelet');
        $this->confirmer($commande);

        $this->assertSame(9, (int) $this->produit('Bracelet')->refresh()->stock);

        $r = $this->repondre('je veux annuler ma commande');

        $this->assertSame('annulee', $commande->fresh()->statut);
        $this->assertSame(10, (int) $this->produit('Bracelet')->refresh()->stock);
        $this->assertStringContainsString('Bracelet', $r['message']);
        $this->assertPasDeNumero($r['message'], $commande);
    }

    public function test_les_messages_paiement_et_livraison_montrent_les_produits_pas_le_numero(): void
    {
        $commande = $this->passerCommande('Je veux 1 bracelet');

        $paiement = $this->repondre('je veux payer ma commande');
        $this->assertStringContainsString('Bracelet', $paiement['message']);
        $this->assertStringContainsString('Reste à payer', $paiement['message']);
        $this->assertPasDeNumero($paiement['message'], $commande);

        $livraison = $this->repondre('où en est ma livraison');
        $this->assertStringContainsString('Bracelet', $livraison['message']);
        $this->assertStringContainsString('Statut', $livraison['message']);
        $this->assertPasDeNumero($livraison['message'], $commande);
    }

    public function test_modification_garde_la_meme_commande_et_affiche_les_nouveaux_produits(): void
    {
        $commande = $this->passerCommande('Je veux 1 bracelet');
        $this->confirmer($commande);
        $idAvant = (int) $commande->id;

        $r = $this->repondre('modifie pour 2 collier');

        $apres = $commande->fresh(['items']);
        $this->assertSame($idAvant, $apres->id, 'La modification doit conserver la même commande.');
        $this->assertSame('confirmee', $apres->statut);
        $this->assertSame('2', (string) $apres->items()->first()->quantite);
        $this->assertSame('Collier', $apres->items()->first()->produit_nom);

        $this->assertSame(10, (int) $this->produit('Bracelet')->fresh()->stock, 'Le bracelet retiré doit être restitué.');
        $this->assertSame(8, (int) $this->produit('Collier')->refresh()->stock);

        $this->assertStringContainsString('Collier', $r['message']);
        $this->assertStringContainsString('modifiée', $r['message']);
        $this->assertPasDeNumero($r['message'], $apres);
    }

    public function test_modification_sans_produit_reconnu_affiche_le_contenu_actuel_sans_modifier(): void
    {
        $commande = $this->passerCommande('Je veux 1 bracelet');
        $idAvant = (int) $commande->id;

        $r = $this->repondre('je veux modifier ma commande');

        $this->assertSame($idAvant, $commande->fresh()->id, 'Aucune commande ne doit être créée ni remplacée.');
        $this->assertSame('en_attente', $commande->fresh()->statut);
        $this->assertStringContainsString('Bracelet', $r['message']);
        $this->assertStringContainsString('modifier 3', $r['message'], 'Des exemples de reformulation doivent être proposés.');
        $this->assertPasDeNumero($r['message'], $commande);
    }
}
