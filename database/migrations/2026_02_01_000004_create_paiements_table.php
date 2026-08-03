<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained()->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->enum('methode', ['especes', 'mobile_money', 'carte', 'virement'])->default('especes');
            $table->enum('statut', ['en_attente', 'paye', 'echoue', 'rembourse'])->default('en_attente');
            $table->string('reference')->nullable();
            $table->timestamp('paye_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
