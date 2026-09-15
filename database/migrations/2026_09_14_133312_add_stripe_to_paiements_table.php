<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('stripe_session_id')->nullable()->after('reference');
            $table->string('stripe_payment_intent_id')->nullable()->after('stripe_session_id');
            $table->decimal('commission_relancia', 12, 2)->nullable()->after('stripe_payment_intent_id');
            $table->decimal('montant_reverse', 12, 2)->nullable()->after('commission_relancia');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_session_id',
                'stripe_payment_intent_id',
                'commission_relancia',
                'montant_reverse',
            ]);
        });
    }
};