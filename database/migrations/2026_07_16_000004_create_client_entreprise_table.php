<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_entreprise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->enum('plateforme_sociale', ['facebook', 'instagram', 'whatsapp', 'autre'])->default('autre');
            $table->string('identifiant_social')->nullable(); // psid / instagram id / numero whatsapp
            $table->timestamp('premier_contact_le')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'entreprise_id', 'plateforme_sociale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_entreprise');
    }
};
