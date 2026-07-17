<?php

namespace App\Policies;

use App\Models\Entreprise;
use App\Models\User;

class EntreprisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Entreprise $entreprise): bool
    {
        return $user->hasRole('admin')
            || $entreprise->gerant_id === $user->id
            || $entreprise->employes()->where('users.id', $user->id)->exists();
    }

    public function update(User $user, Entreprise $entreprise): bool
    {
        return $user->hasRole('admin') || $entreprise->gerant_id === $user->id;
    }

    public function suspend(User $user, Entreprise $entreprise): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Gérer les employés : réservé au gérant propriétaire (ou à l'admin en support).
     */
    public function gererEmployes(User $user, Entreprise $entreprise): bool
    {
        return $user->hasRole('admin') || $entreprise->gerant_id === $user->id;
    }

    /**
     * Voir les clients : gérant, employés actifs de l'entreprise, ou admin.
     */
    public function voirClients(User $user, Entreprise $entreprise): bool
    {
        if ($user->hasRole('admin') || $entreprise->gerant_id === $user->id) {
            return true;
        }

        return $entreprise->employes()
            ->where('users.id', $user->id)
            ->wherePivot('actif', true)
            ->exists();
    }
}
