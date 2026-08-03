<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyserMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'canal' => ['required', 'in:Messenger,Instagram,WhatsApp'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'client_nom' => ['required_without:client_id', 'string', 'max:255'],
            'client_identifiant_externe' => ['nullable', 'string', 'max:255'],
        ];
    }
}
