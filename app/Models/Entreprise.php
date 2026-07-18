<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entreprise extends Model
{
    use HasFactory;

    protected $fillable = [
        'gerant_id',
        'nom',
        'secteur_activite',
        'email_contact',
        'telephone',
        'logo_path',
        'actif',
        'abonnement_expire_le',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'abonnement_expire_le' => 'datetime',
    ];

    public function gerant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerant_id');
    }

    public function employes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employe_entreprise')
            ->withPivot(['poste', 'actif', 'peut_gerer_catalogue', 'invite_le','retire_le'])
            ->withTimestamps();
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_entreprise')
            ->withPivot(['plateforme_sociale', 'identifiant_social', 'premier_contact_le'])
            ->withTimestamps();
    }

    public function invitationsEmploye(): HasMany
    {
        return $this->hasMany(InvitationEmploye::class);
    }
}
