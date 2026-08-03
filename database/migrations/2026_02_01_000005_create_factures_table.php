<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->unique();
            $table->string('chemin_pdf');
            $table->timestamp('envoyee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
