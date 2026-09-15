<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->boolean('preuve_verifiee')->nullable()->after('preuve_image');
            $table->float('montant_detecte')->nullable()->after('preuve_verifiee');
            $table->text('ocr_texte')->nullable()->after('montant_detecte');
            $table->string('verification_message')->nullable()->after('ocr_texte');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn(['preuve_verifiee', 'montant_detecte', 'ocr_texte', 'verification_message']);
        });
    }
};
