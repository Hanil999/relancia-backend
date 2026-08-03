<?php

// À fusionner dans routes/channels.php

use App\Models\Entreprise;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('entreprise.{entrepriseId}', function (User $user, int $entrepriseId) {
    $entreprise = Entreprise::find($entrepriseId);

    if (! $entreprise) {
        return false;
    }

    if ($user->hasRole('gerant')) {
        return $entreprise->gerant_id === $user->id;
    }

    return $entreprise->employes()
        ->wherePivot('actif', true)
        ->where('users.id', $user->id)
        ->exists();
});
