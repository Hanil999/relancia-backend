<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations_employe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('nom');
            $table->string('poste')->nullable();
            $table->boolean('peut_gerer_catalogue')->default(false);
            $table->string('token', 64)->unique();
            $table->foreignId('invite_par_id')->constrained('users');
            $table->timestamp('expire_le');
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamps();

            $table->index(['entreprise_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations_employe');
    }
};

