<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->string('statut_envoi')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->dropColumn('statut_envoi');
        });
    }
};