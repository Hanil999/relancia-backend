<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entreprise extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'gerant_id', 'nom', 'secteur_activite', 'telephone', 'email_contact', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function gerant()
    {
        return $this->belongsTo(User::class, 'gerant_id');
    }

    public function employes()
{
    return $this->belongsToMany(User::class, 'employe_entreprise')
        ->withPivot(['actif', 'retire_le', 'poste', 'peut_gerer_catalogue'])
        ->withTimestamps();
}

public function invitationsEmploye()
{
    return $this->hasMany(InvitationEmploye::class, 'entreprise_id');
}

    /** Statut affiché côté front : Actif / Suspendu / Archivé (En attente vient des invitations) */
    public function getStatutAttribute(): string
    {
        if ($this->trashed()) {
            return 'Archivé';
        }

        return $this->actif ? 'Actif' : 'Suspendu';
    }

    public function produits(): HasMany
{
    return $this->hasMany(Produit::class);
}

public function categories(): HasMany
{
    return $this->hasMany(Categorie::class);
}
}
