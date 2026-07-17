<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Admin Relancia
            'gerer-entreprises',
            'voir-statistiques-globales',
            'gerer-abonnements',
            'suspendre-entreprise',

            // Gérant
            'gerer-employes',
            'gerer-reseaux-sociaux',
            'gerer-parametres-entreprise',
            'voir-statistiques-entreprise',

            // Employé
            'gerer-commandes',
            'repondre-messages',
            'gerer-catalogue',
            'voir-clients',

            // Client
            'passer-commande',
            'voir-historique-commandes',
            'gerer-son-profil',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions([
            'gerer-entreprises',
            'voir-statistiques-globales',
            'gerer-abonnements',
            'suspendre-entreprise',
        ]);

        $gerant = Role::firstOrCreate(['name' => 'gerant', 'guard_name' => 'web']);
        $gerant->syncPermissions([
            'gerer-employes',
            'gerer-reseaux-sociaux',
            'gerer-parametres-entreprise',
            'voir-statistiques-entreprise',
            'gerer-commandes',
            'repondre-messages',
            'gerer-catalogue',
            'voir-clients',
        ]);

        $employe = Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $employe->syncPermissions([
            'gerer-commandes',
            'repondre-messages',
            'gerer-catalogue',
            'voir-clients',
        ]);

        $client = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $client->syncPermissions([
            'passer-commande',
            'voir-historique-commandes',
            'gerer-son-profil',
        ]);
    }
}
