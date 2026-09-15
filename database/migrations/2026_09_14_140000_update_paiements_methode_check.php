<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_methode_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_methode_check CHECK (methode IN ('especes', 'mobile_money', 'carte', 'virement', 'stripe'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_methode_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_methode_check CHECK (methode IN ('especes', 'mobile_money', 'carte', 'virement'))");
    }
};