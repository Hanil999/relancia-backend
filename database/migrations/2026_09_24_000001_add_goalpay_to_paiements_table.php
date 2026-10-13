<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('goalpay_order_reference')->nullable()->unique()
                ->after('stripe_payment_intent_id');
        });

        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_methode_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_methode_check CHECK (methode IN ('especes', 'mobile_money', 'carte', 'virement', 'stripe', 'goalpay'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_methode_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_methode_check CHECK (methode IN ('especes', 'mobile_money', 'carte', 'virement', 'stripe'))");

        Schema::table('paiements', function (Blueprint $table) {
            $table->dropUnique(['goalpay_order_reference']);
            $table->dropColumn('goalpay_order_reference');
        });
    }
};