<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrait du canal Instagram de la plateforme.
     *
     * Normalise les donnees rattachees a Instagram, puis reconstruit les
     * contraintes de canal (commandes.canal et client_entreprise.plateforme_sociale)
     * sans la valeur 'instagram' / 'Instagram'.
     */
    public function up(): void
    {
        if (Schema::hasTable('canaux_entreprise')) {
            DB::table('canaux_entreprise')->where('type', 'instagram')->delete();
        }

        if (Schema::hasTable('messages_canal')) {
            DB::table('messages_canal')->where('canal', 'Instagram')->delete();
        }

        DB::table('commandes')->where('canal', 'Instagram')->update(['canal' => 'Manuel']);

        DB::table('client_entreprise')
            ->where('plateforme_sociale', 'instagram')
            ->update(['plateforme_sociale' => 'autre']);

        DB::statement('ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_canal_check');
        DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_check CHECK (canal IN ('Messenger', 'WhatsApp', 'Telegram', 'Manuel'))");

        DB::statement('ALTER TABLE client_entreprise DROP CONSTRAINT IF EXISTS client_entreprise_plateforme_sociale_check');
        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'whatsapp'::character varying, 'telegram'::character varying, 'autre'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_canal_check');
        DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_check CHECK (canal IN ('Messenger', 'Instagram', 'WhatsApp', 'Telegram', 'Manuel'))");

        DB::statement('ALTER TABLE client_entreprise DROP CONSTRAINT IF EXISTS client_entreprise_plateforme_sociale_check');
        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'instagram'::character varying, 'whatsapp'::character varying, 'telegram'::character varying, 'autre'::character varying]::text[]))");
    }
};
