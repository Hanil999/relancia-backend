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
    Schema::table('employe_entreprise', function (Blueprint $table) {
        $table->timestamp('retire_le')->nullable()->after('actif');
    });
}

public function down(): void
{
    Schema::table('employe_entreprise', function (Blueprint $table) {
        $table->dropColumn('retire_le');
    });
}
};
