<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seuil à partir duquel on notifie une rupture de stock imminente.
        Schema::table('produits', function (Blueprint $table) {
            if (! Schema::hasColumn('produits', 'seuil_alerte_stock')) {
                $table->unsignedInteger('seuil_alerte_stock')->default(5)->after('stock');
            }
        });

        // Droit de gérer les commandes, sur le même modèle que peut_gerer_catalogue.
        Schema::table('employe_entreprise', function (Blueprint $table) {
            if (! Schema::hasColumn('employe_entreprise', 'peut_gerer_commandes')) {
                $table->boolean('peut_gerer_commandes')->default(false)->after('peut_gerer_catalogue');
            }
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('seuil_alerte_stock');
        });
        Schema::table('employe_entreprise', function (Blueprint $table) {
            $table->dropColumn('peut_gerer_commandes');
        });
    }
};
