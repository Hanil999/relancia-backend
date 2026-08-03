<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInterne extends Model
{
    protected $table = 'notifications_internes';

    public const TYPE_NOUVELLE_COMMANDE = 'nouvelle_commande';
    public const TYPE_NOUVEAU_CLIENT = 'nouveau_client';
    public const TYPE_PAIEMENT_RECU = 'paiement_recu';
    public const TYPE_RUPTURE_STOCK = 'rupture_stock';
    public const TYPE_COMMANDE_ANNULEE = 'commande_annulee';

    protected $fillable = [
        'entreprise_id',
        'type',
        'titre',
        'message',
        'data',
        'lu_le',
    ];

    protected $casts = [
        'data' => 'array',
        'lu_le' => 'datetime',
    ];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
