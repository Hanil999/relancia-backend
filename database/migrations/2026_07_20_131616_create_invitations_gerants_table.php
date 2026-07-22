<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations_gerants', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();

            // Infos du gérant invité
            $table->string('nom');
            $table->string('email');

            // Infos de l'entreprise (pas encore créée en base)
            $table->string('entreprise_nom');
            $table->string('entreprise_secteur_activite')->nullable();
            $table->string('entreprise_telephone')->nullable();
            $table->string('entreprise_email_contact')->nullable();

            $table->foreignId('invite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamp('refusee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations_gerants');
    }
};
