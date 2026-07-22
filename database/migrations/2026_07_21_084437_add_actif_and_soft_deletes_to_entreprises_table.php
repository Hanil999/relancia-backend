<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (! Schema::hasColumn('entreprises', 'actif')) {
                $table->boolean('actif')->default(true)->after('secteur_activite');
            }

            if (! Schema::hasColumn('entreprises', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (Schema::hasColumn('entreprises', 'actif')) {
                $table->dropColumn('actif');
            }

            if (Schema::hasColumn('entreprises', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
