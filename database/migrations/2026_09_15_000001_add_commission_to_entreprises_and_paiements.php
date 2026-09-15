<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            $table->decimal('commission_pct', 5, 2)->default(5.00)->after('acompte_pct');
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->decimal('commission_pct', 5, 2)->nullable()->after('commission_relancia');
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            $table->dropColumn('commission_pct');
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn('commission_pct');
        });
    }
};