<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Table dédiée aux notifications métier (commande, stock, paiement...), distincte
// de la table `notifications` standard de Laravel afin d'éviter toute collision
// si le framework Notifiable est utilisé ailleurs (reset password, etc).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_internes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // nouvelle_commande, nouveau_client, paiement_recu, rupture_stock, commande_annulee
            $table->string('titre');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('lu_le')->nullable();
            $table->timestamps();

            $table->index(['entreprise_id', 'lu_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_internes');
    }
};
