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
        'idempotency_key',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'commission_relancia',
        'commission_pct',
        'montant_reverse',
        'preuve_image',
        'preuve_verifiee',
        'montant_detecte',
        'ocr_texte',
        'verification_message',
        'paye_le',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'montant_detecte' => 'decimal:2',
        'commission_relancia' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'montant_reverse' => 'decimal:2',
        'preuve_verifiee' => 'boolean',
        'paye_le' => 'datetime',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
