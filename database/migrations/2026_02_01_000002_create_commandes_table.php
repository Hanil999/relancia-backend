<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('numero')->unique();
            $table->enum('canal', ['Messenger', 'Instagram', 'WhatsApp', 'Manuel'])->default('Manuel');
            $table->enum('statut', ['en_attente', 'confirmee', 'expediee', 'livree', 'annulee'])->default('en_attente');
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->text('message_origine')->nullable();
            $table->boolean('creee_automatiquement')->default(false);
            $table->timestamp('confirmee_le')->nullable();
            $table->timestamp('expediee_le')->nullable();
            $table->timestamp('livree_le')->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            $table->index(['entreprise_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};
