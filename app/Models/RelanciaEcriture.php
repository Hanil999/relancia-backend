<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelanciaEcriture extends Model
{
    protected $fillable = [
        'compte_id',
        'entreprise_id',
        'commande_id',
        'paiement_id',
        'type',
        'montant',
        'commission_pct',
        'reference',
        'date_operation',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'date_operation' => 'datetime',
    ];

    public function compte(): BelongsTo
    {
        return $this->belongsTo(RelanciaCompte::class);
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }
}