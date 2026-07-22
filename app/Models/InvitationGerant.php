<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitationGerant extends Model
{
    protected $table = 'invitations_gerants';

    protected $fillable = [
        'token',
        'nom',
        'email',
        'entreprise_nom',
        'entreprise_secteur_activite',
        'entreprise_telephone',
        'entreprise_email_contact',
        'invite_par',
        'expires_at',
        'acceptee_le',
        'refusee_le',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'acceptee_le' => 'datetime',
        'refusee_le' => 'datetime',
    ];

    public function invitePar()
    {
        return $this->belongsTo(User::class, 'invite_par');
    }

    /**
     * Vérifie si l'invitation peut encore être acceptée : pas expirée,
     * pas déjà acceptée, pas refusée.
     */
    public function estValide(): bool
    {
        return $this->expires_at->isFuture()
            && is_null($this->acceptee_le)
            && is_null($this->refusee_le);
    }
}
