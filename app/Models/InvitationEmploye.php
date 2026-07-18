<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvitationEmploye extends Model
{
    protected $table = 'invitations_employe';
protected $fillable = [
    'entreprise_id', 'email', 'nom', 'poste',
    'peut_gerer_catalogue', 'token', 'invite_par_id', 'expire_le', 'acceptee_le', 'refusee_le',
];

protected $casts = [
    'expire_le' => 'datetime',
    'acceptee_le' => 'datetime',
    'refusee_le' => 'datetime',
    'peut_gerer_catalogue' => 'boolean',
];

public function estValide(): bool
{
    return is_null($this->acceptee_le)
        && is_null($this->refusee_le)
        && $this->expire_le->isFuture();
}

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function invitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invite_par_id');
    }

    public static function genererToken(): string
    {
        return Str::random(48);
    }
}
