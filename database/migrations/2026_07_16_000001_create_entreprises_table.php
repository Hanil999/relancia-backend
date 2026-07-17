<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gerant_id')->constrained('users')->cascadeOnDelete();
            $table->string('nom');
            $table->string('secteur_activite')->nullable();
            $table->string('email_contact')->nullable();
            $table->string('telephone')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamp('abonnement_expire_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises');
    }
};
