<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    protected $fillable = [
        'commande_id',
        'montant',
        'methode',
        'statut',
        'reference',
        'paye_le',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'paye_le' => 'datetime',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
