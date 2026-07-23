<?php
// app/Models/Produit.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Produit extends Model
{
    protected $fillable = [
        'nom', 'image', 'description', 'prix', 'stock', 'sku', 'categorie_id', 'entreprise_id',
    ];

    protected $casts = [
        'prix' => 'integer',
        'stock' => 'integer',
    ];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }
}
