<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nom',
        'telephone',
        'email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entreprises(): BelongsToMany
    {
        return $this->belongsToMany(Entreprise::class, 'client_entreprise')
            ->withPivot(['plateforme_sociale', 'identifiant_social', 'premier_contact_le'])
            ->withTimestamps();
    }
}
