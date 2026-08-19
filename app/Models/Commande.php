<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Commande extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUTS = ['en_attente', 'confirmee', 'expediee', 'livree', 'annulee'];

    public const LABELS_STATUT = [
        'en_attente' => 'En attente',
        'confirmee' => 'Confirmée',
        'expediee' => 'Expédiée',
        'livree' => 'Livrée',
        'annulee' => 'Annulée',
    ];

    protected $fillable = [
        'entreprise_id',
        'client_id',
        'numero',
        'canal',
        'statut',
        'montant_total',
        'message_origine',
        'creee_automatiquement',
        'confirmee_le',
        'expediee_le',
        'livree_le',
        'annulee_le',
    ];

    protected $casts = [
        'creee_automatiquement' => 'boolean',
        'montant_total' => 'decimal:2',
        'confirmee_le' => 'datetime',
        'expediee_le' => 'datetime',
        'livree_le' => 'datetime',
        'annulee_le' => 'datetime',
    ];

    /**
     * Génère un numéro de commande séquentiel et lisible, propre à l'entreprise.
     * Ex: CMD-000256
     *
     * On tient compte des commandes archivées (soft deleted) : elles gardent leur
     * numéro et restent soumises à la contrainte unique — sinon la commande qui
     * suit une commande archivée prendrait le même numéro (SQLSTATE 23505).
     */
    public static function genererNumero(int $entrepriseId): string
    {
        $prefixe = 'CMD' . str_pad((string) $entrepriseId, 4, '0', STR_PAD_LEFT) . '-';
        $dernierNumero = static::withTrashed()
            ->where('numero', 'like', $prefixe . '%')
            ->max('numero');

        if ($dernierNumero) {
            $suivant = (int) substr($dernierNumero, strlen($prefixe)) + 1;
        } else {
            $suivant = 1;
        }

        return $prefixe . str_pad((string) $suivant, 6, '0', STR_PAD_LEFT);
    }

    public function getStatutLabelAttribute(): string
    {
        return self::LABELS_STATUT[$this->statut] ?? $this->statut;
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommandeProduit::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function facture(): HasOne
    {
        return $this->hasOne(Facture::class);
    }
}
