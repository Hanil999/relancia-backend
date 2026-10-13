<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Entreprise;
use App\Models\MessageCanal;
use App\Models\User;
use App\Services\ReponseAutomatiqueService;
use App\Services\ReponseIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Garantit que l'IA ne peut ni mentir sur les prix ou les stocks, ni mélanger
 * les conversations d'une autre entreprise, ni casser les règles existantes
 * quand l'API est indisponible.
 *
 * Tous les appels HTTP sont simulés : aucun appel réel à Gemini ou xAI.
 */
class IaReponseTest extends TestCase
{
    use DatabaseTransactions;

    private Entreprise $entreprise;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ia.api_key' => 'ia-test',
            'services.ia.model' => 'ia-test',
            'services.ia.base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        ]);

        $this->entreprise = Entreprise::create([
            'gerant_id' => User::factory()->create()->id,
            'nom' => 'Boutique Test',
            'telephone' => '0340000000',
            'actif' => true,
        ]);

        $this->client = Client::create(['nom' => 'Client Test']);

        $this->entreprise->produits()->create([
            'nom' => 'Bracelet',
            'prix' => 4000,
            'stock' => 10,
        ]);
    }

    private function produits()
    {
        return $this->entreprise->produits()->get();
    }

    private function fakeIa(string $contenu, int $status = 200): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $status === 200
                    ? ['choices' => [['message' => ['content' => $contenu]]]]
                    : ['error' => 'quota'],
                $status,
            ),
        ]);
    }

    private function repondre(string $contenu, string $message = 'Bonjour', string $canal = 'Telegram'): ?array
    {
        return app(ReponseIaService::class)->repondre(
            $this->entreprise,
            $this->client,
            $message,
            $this->produits(),
            $canal,
        );
    }

    public function test_reponse_valide_est_utilisee(): void
    {
        $this->fakeIa('Bonjour ! Le bracelet est disponible, je peux vous renseigner sur la livraison.');

        $r = $this->repondre('Bonjour, le bracelet est dispo ?');

        $this->assertNotNull($r);
        $this->assertSame('ia', $r['source']);
        $this->assertStringContainsString('bracelet', mb_strtolower($r['message']));
    }

    public function test_prix_inventes_est_rejete(): void
    {
        // 999 Ar n'existe pas au catalogue.
        $this->fakeIa('Le bracelet est à 999 Ar, une promotion exceptionnelle !');

        $this->assertNull(
            $this->repondre('c\'est combien ?'),
            'Une réponse citant un prix absent du catalogue doit être rejetée.'
        );
    }

    public function test_prix_exact_du_catalogue_est_accepte(): void
    {
        $this->fakeIa('Le bracelet est à 4 000 Ar. Souhaitez-vous que je vous prépare la commande ?');

        $this->assertNotNull($this->repondre('prix du bracelet ?'));
    }

    public function test_conversion_en_euros_est_refusee(): void
    {
        // Le modèle ne doit pas convertir en EUR : les paiements passent par des liens dédiés.
        $this->fakeIa('Le bracelet est à 4 000 Ar, soit environ 0,80 €.');

        $this->assertNull($this->repondre('prix ?'));
    }

    public function test_stock_inventé_est_rejete(): void
    {
        // Le catalogue annonce 10, le modèle en annonce 3.
        $this->fakeIa('Il ne reste que 3 bracelets en stock, dépêchez-vous !');

        $this->assertNull(
            $this->repondre('il en reste ?'),
            'Une réponse citant un stock absent de la base doit être rejetée.'
        );
    }

    public function test_stock_reel_est_accepte(): void
    {
        $this->fakeIa('Il reste 10 bracelets en stock.');

        $this->assertNotNull($this->repondre('il en reste ?'));
    }

    public function test_prix_et_stock_valides_dans_la_meme_phrase(): void
    {
        // « en stock, à 4 000 Ar » ne doit pas être lu comme un stock de 4 000.
        $this->fakeIa('Il reste 10 bracelets en stock, à 4 000 Ar. Souhaitez-vous commander ?');

        $this->assertNotNull($this->repondre('il en reste et combien ?'));
    }

    public function test_panne_api_retombe_sur_les_regles(): void
    {
        $this->fakeIa('', 500);

        $this->assertNull($this->repondre('salut'));
    }

    public function test_sans_cle_api_le_service_est_inactif(): void
    {
        config(['services.ia.api_key' => '']);

        $this->assertFalse(app(ReponseIaService::class)->estConfigure());
    }

    public function test_le_service_ne_se_declenche_pas_sur_une_intention_transactionnelle(): void
    {
        // Une intention transactionnelle doit rester deterministe : on verifie
        // que le modele n'est jamais sollicite, quel que soit le contenu
        // qu'il aurait pu renvoyer (ici une fausse promotion).
        $this->fakeIa('Je vous offre une promotion de -50% sur tout le catalogue !');

        foreach ([
            'je veux payer ma commande',
            'annule ma commande',
            'livraison quand ?',
        ] as $message) {
            try {
                app(ReponseAutomatiqueService::class)->repondre(
                    $this->entreprise,
                    $this->client,
                    $message,
                    'Telegram',
                );
            } catch (\Throwable) {
                // Le chemin transactionnel peut echouer independamment de l'IA.
                // Ce qui compte ici : aucun appel au modele n'a eu lieu.
            }
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_historique_ne_contient_pas_les_anciens_liens_de_paiement(): void
    {
        MessageCanal::create([
            'entreprise_id' => $this->entreprise->id,
            'client_id' => $this->client->id,
            'canal' => 'Telegram',
            'direction' => 'sortant',
            'texte' => 'Paiement : https://checkout.stripe.com/pay/cs_test_123',
            'conversation_id' => '999888777',
            'source' => 'auto',
        ]);

        $this->fakeIa('Bonjour, comment puis-je vous aider ?');

        $this->repondre('bonjour');

        Http::assertSent(function ($request) {
            $corps = $request->body();
            $this->assertStringNotContainsString('cs_test_123', $corps);
            $this->assertStringContainsString('[lien de paiement]', $corps);

            return true;
        });
    }

    public function test_historique_ignore_les_messages_dune_autre_entreprise(): void
    {
        // Un meme client peut etre rattache a plusieurs boutiques. On ne doit
        // jamais envoyer a un modele les messages d'une autre entreprise.
        $autreEntreprise = Entreprise::create([
            'gerant_id' => User::factory()->create()->id,
            'nom' => 'Boutique Concurrente',
            'actif' => true,
        ]);

        MessageCanal::create([
            'entreprise_id' => $autreEntreprise->id,
            'client_id' => $this->client->id,
            'canal' => 'Telegram',
            'direction' => 'entrant',
            'texte' => 'MON-SECRET-INTER-ENTREPRISE 123456',
            'conversation_id' => '555444333',
            'source' => 'webhook',
        ]);

        $this->fakeIa('Bonjour !');

        $this->repondre('bonjour');

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString(
                'MON-SECRET-INTER-ENTREPRISE',
                $request->body(),
                "L'historique d'une autre entreprise ne doit jamais atteindre le modele."
            );

            return true;
        });
    }

    public function test_historique_ignore_les_autres_canaux(): void
    {
        MessageCanal::create([
            'entreprise_id' => $this->entreprise->id,
            'client_id' => $this->client->id,
            'canal' => 'WhatsApp',
            'direction' => 'entrant',
            'texte' => ' discussions sur whatsapp 42',
            'conversation_id' => '777666555',
            'source' => 'webhook',
        ]);

        $this->fakeIa('Bonjour !');

        $this->repondre('bonjour', 'bonjour', 'Telegram');

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString(
                'discussions sur whatsapp',
                $request->body(),
                "L'historique d'un autre canal ne doit pas melanger la conversation."
            );

            return true;
        });
    }

    public function test_le_garde_fou_peut_etre_desactive_par_configuration(): void
    {
        config(['services.ia.verifier_montants' => false]);

        $this->fakeIa('Le bracelet est à 999 Ar.');

        $this->assertNotNull(
            $this->repondre('prix ?'),
            'Avec verifier_montants=false, la reponse doit passer sans controle des montants.'
        );
    }
}
