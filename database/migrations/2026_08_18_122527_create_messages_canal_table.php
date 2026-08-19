<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_canal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('canal'); // "Telegram", "Messenger", ...
            $table->enum('direction', ['entrant', 'sortant']);
            $table->text('texte');

            // Identifiant de conversation côté plateforme externe (chat_id Telegram, etc.)
            $table->string('conversation_id');

            $table->timestamps();

            $table->index(['entreprise_id', 'client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_canal');
    }
};
