<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Facture extends Model
{
    protected $fillable = [
        'commande_id',
        'entreprise_id',
        'numero',
        'chemin_pdf',
        'envoyee_le',
    ];

    protected $casts = [
        'envoyee_le' => 'datetime',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    /**
     * Une facture est payée lorsque les paiements enregistrés sur la commande
     * couvrent le montant total.
     */
    public function getEstPayeeAttribute(): bool
    {
        $paye = (float) ($this->commande?->paiements->sum('montant') ?? 0);

        return $paye >= (float) $this->commande?->montant_total;
    }
}
