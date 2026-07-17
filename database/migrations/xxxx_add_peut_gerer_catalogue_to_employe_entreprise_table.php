<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employe_entreprise', function (Blueprint $table) {
            $table->boolean('peut_gerer_catalogue')->default(false)->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table('employe_entreprise', function (Blueprint $table) {
            $table->dropColumn('peut_gerer_catalogue');
        });
    }
};
