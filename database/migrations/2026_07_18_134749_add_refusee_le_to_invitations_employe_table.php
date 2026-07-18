<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations_employe', function (Blueprint $table) {
            $table->timestamp('refusee_le')->nullable()->after('acceptee_le');
        });
    }

    public function down(): void
    {
        Schema::table('invitations_employe', function (Blueprint $table) {
            $table->dropColumn('refusee_le');
        });
    }
};
