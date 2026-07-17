<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employe_entreprise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->string('poste')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamp('invite_le')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'entreprise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employe_entreprise');
    }
};
