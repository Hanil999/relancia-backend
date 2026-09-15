<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relancia_comptes', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->default('Compte Relancia');
            $table->decimal('solde', 14, 2)->default(0); // part réellement encaissée de Relancia
            $table->timestamps();
        });

        Schema::create('relancia_ecritures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compte_id')->nullable()->constrained('relancia_comptes')->nullOnDelete();
            $table->foreignId('entreprise_id')->nullable()->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('paiement_id')->nullable()->constrained('paiements')->cascadeOnDelete();
            $table->string('type'); // commission_facturee | commission_encaissee | remboursement
            $table->decimal('montant', 12, 2);
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('date_operation')->nullable();
            $table->timestamps();

            // Idempotence : une seule commission facturée par commande, une seule encaissée par paiement
            // (une commande peut avoir plusieurs paiements : acompte + solde => pas d'unicité commande/type)
            $table->unique(['paiement_id', 'type'], 'relancia_ecr_paiement_type_unique');
            $table->index('entreprise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relancia_ecritures');
        Schema::dropIfExists('relancia_comptes');
    }
};