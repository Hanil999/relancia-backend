<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE canaux_entreprise ALTER COLUMN app_secret TYPE TEXT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE canaux_entreprise ALTER COLUMN app_secret TYPE VARCHAR(255)');
    }
};