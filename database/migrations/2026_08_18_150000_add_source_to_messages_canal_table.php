<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->string('source')->default('manuel')->after('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages_canal', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
