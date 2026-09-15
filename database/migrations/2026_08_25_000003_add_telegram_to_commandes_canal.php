<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            DB::statement("ALTER TABLE commandes DROP CONSTRAINT commandes_canal_check");
            DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_check CHECK (canal IN ('Messenger', 'Instagram', 'WhatsApp', 'Telegram', 'Manuel'))");
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            DB::statement("ALTER TABLE commandes DROP CONSTRAINT commandes_canal_check");
            DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_check CHECK (canal IN ('Messenger', 'Instagram', 'WhatsApp', 'Manuel'))");
        });
    }
};
