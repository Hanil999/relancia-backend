<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('reference');
            $table->unique('idempotency_key', 'paiements_idempotency_key_unique');
            $table->unique('stripe_session_id', 'paiements_stripe_session_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropUnique('paiements_stripe_session_id_unique');
            $table->dropUnique('paiements_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};