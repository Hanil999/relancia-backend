<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_xx_xx_create_produits_table.php
public function up(): void
{
    Schema::create('produits', function (Blueprint $table) {
        $table->id();
        $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
        $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
        $table->string('nom');
        $table->string('image')->nullable(); // emoji ou url storage
        $table->text('description')->nullable();
        $table->unsignedBigInteger('prix'); // XOF, entier
        $table->unsignedInteger('stock')->default(0);
        $table->string('sku')->nullable();
        $table->timestamps();

        $table->index(['entreprise_id', 'categorie_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
