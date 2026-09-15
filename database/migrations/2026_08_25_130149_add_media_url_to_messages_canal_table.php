<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->string('media_url')->nullable()->after('texte');
            $table->string('media_type')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->dropColumn(['media_url', 'media_type']);
        });
    }
};
