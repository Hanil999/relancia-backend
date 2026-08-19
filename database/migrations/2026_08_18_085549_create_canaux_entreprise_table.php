<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canaux_entreprise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // "telegram", plus tard "messenger", "whatsapp", "instagram" si vous unifiez
            $table->string('type');

            // Token du bot Telegram (chiffré via le cast Eloquent 'encrypted')
            $table->text('token')->nullable();

            // Infos renvoyées par getMe() : @username du bot, id, etc.
            $table->string('bot_username')->nullable();
            $table->string('bot_id')->nullable();

            // Secret unique inséré dans l'URL du webhook pour vérifier
            // que l'appel vient bien de Telegram (Telegram n'a pas de
            // signature HMAC comme Meta, donc on utilise un secret d'URL
            // + le header X-Telegram-Bot-Api-Secret-Token)
            $table->string('webhook_secret')->unique()->nullable();

            $table->boolean('actif')->default(false);
            $table->timestamp('connecte_le')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canaux_entreprise');
    }
};
