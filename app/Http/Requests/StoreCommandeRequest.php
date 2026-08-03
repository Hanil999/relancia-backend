<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorisation gérée dans le contrôleur via authorize('gererCommandes', ...)
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:clients,id'],
            'client_nom' => ['required_without:client_id', 'string', 'max:255'],
            'client_telephone' => ['nullable', 'string', 'max:30'],
            'canal' => ['sometimes', 'in:Messenger,Instagram,WhatsApp,Manuel'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.produit_id' => ['required', 'exists:produits,id'],
            'items.*.quantite' => ['required', 'integer', 'min:1'],
        ];
    }
}
