<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_entreprise', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropUnique(['client_id', 'entreprise_id', 'plateforme_sociale']);
        });

        DB::statement("ALTER TABLE client_entreprise DROP CONSTRAINT client_entreprise_plateforme_sociale_check");
        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'instagram'::character varying, 'whatsapp'::character varying, 'telegram'::character varying, 'autre'::character varying]::text[]))");

        Schema::table('client_entreprise', function (Blueprint $table) {
            $table->unique(['client_id', 'entreprise_id', 'plateforme_sociale']);
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_entreprise', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropUnique(['client_id', 'entreprise_id', 'plateforme_sociale']);
        });

        DB::statement("ALTER TABLE client_entreprise DROP CONSTRAINT client_entreprise_plateforme_sociale_check");
        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'instagram'::character varying, 'whatsapp'::character varying, 'autre'::character varying]::text[]))");

        Schema::table('client_entreprise', function (Blueprint $table) {
            $table->unique(['client_id', 'entreprise_id', 'plateforme_sociale']);
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }
};
