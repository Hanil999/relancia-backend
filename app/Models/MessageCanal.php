<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageCanal extends Model
{
    protected $table = 'messages_canal';

    protected $fillable = [
        'entreprise_id', 'client_id', 'canal', 'direction', 'texte', 'conversation_id', 'source',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
