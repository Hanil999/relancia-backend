<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelanciaCompte extends Model
{
    protected $fillable = [
        'libelle',
        'solde',
    ];

    protected $casts = [
        'solde' => 'decimal:2',
    ];

    public function ecritures(): HasMany
    {
        return $this->hasMany(RelanciaEcriture::class, 'compte_id');
    }
}