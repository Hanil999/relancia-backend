<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Client;
use App\Models\Entreprise;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sendPasswordResetNotification($token)
{
    $frontendUrl = rtrim(config('app.frontend_url'), '/');
    $url = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);

    $this->notify(new \App\Notifications\ResetPasswordNotification($url));
}

public function entrepriseGeree(): HasOne
{
    return $this->hasOne(Entreprise::class, 'gerant_id');
}

/**
 * Si l'utilisateur est EMPLOYÉ : les entreprises où il est rattaché.
 */
public function entreprisesEmploye(): BelongsToMany
{
    return $this->belongsToMany(Entreprise::class, 'employe_entreprise')
        ->withPivot(['poste', 'actif', 'invite_le', 'peut_gerer_catalogue','retire_le'])
        ->withTimestamps();
}

/**
 * Si l'utilisateur CLIENT a un compte classique lié à une fiche client.
 */
public function client(): HasOne
{
    return $this->hasOne(Client::class);
}

/**
 * Raccourci pratique pour récupérer "l'entreprise active" peu importe le rôle
 * (gérant -> la sienne, employé -> la première où il est actif).
 */
public function entrepriseActive(): ?Entreprise
{
    if ($this->hasRole('gerant')) {
        return $this->entrepriseGeree;
    }

    if ($this->hasRole('employe')) {
        return $this->entreprisesEmploye()->wherePivot('actif', true)->first();
    }

    return null;
}

}
