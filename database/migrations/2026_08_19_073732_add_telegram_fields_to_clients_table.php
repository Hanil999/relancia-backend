<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('canal_prefere')->nullable()->after('email');
            $table->string('identifiant_externe')->nullable()->after('canal_prefere');
            $table->text('notes')->nullable()->after('identifiant_externe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['canal_prefere', 'identifiant_externe', 'notes']);
        });
    }
};
