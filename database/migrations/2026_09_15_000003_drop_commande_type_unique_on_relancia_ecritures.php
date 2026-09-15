<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une commande peut avoir plusieurs paiements partiels (acompte + solde) :
        // l'unicité sur (commande_id, type) empêchait d'encaisser plusieurs paiements d'une même commande.
        Schema::table('relancia_ecritures', function (Blueprint $table) {
            $table->dropUnique('relancia_ecr_commande_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('relancia_ecritures', function (Blueprint $table) {
            $table->unique(['commande_id', 'type'], 'relancia_ecr_commande_type_unique');
        });
    }
};