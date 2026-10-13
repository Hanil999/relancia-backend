<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le code ecrit 'telegram' en minuscules (Telegramcontroller, CommandeController)
     * et LienPaiementService compare la valeur du pivot en minuscules, mais la
     * contrainte en base n'autorisait que 'Telegram' avec une majuscule.
     * Toute reception de message Telegram echouait donc sur une violation de
     * contrainte (SQLSTATE 23514) et aucune reponse automatique n'etait envoyee.
     *
     * On normalise les donnees et la contrainte sur la valeur minuscule.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE client_entreprise DROP CONSTRAINT IF EXISTS client_entreprise_plateforme_sociale_check');

        DB::statement('UPDATE client_entreprise SET plateforme_sociale = lower(plateforme_sociale)');

        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'whatsapp'::character varying, 'telegram'::character varying, 'autre'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_entreprise DROP CONSTRAINT IF EXISTS client_entreprise_plateforme_sociale_check');

        DB::statement("UPDATE client_entreprise SET plateforme_sociale = 'Telegram' WHERE plateforme_sociale = 'telegram'");

        DB::statement("ALTER TABLE client_entreprise ADD CONSTRAINT client_entreprise_plateforme_sociale_check CHECK (plateforme_sociale::text = ANY (ARRAY['facebook'::character varying, 'whatsapp'::character varying, 'Telegram'::character varying, 'autre'::character varying]::text[]))");
    }
};
