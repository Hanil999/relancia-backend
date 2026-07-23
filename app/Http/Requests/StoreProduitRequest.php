<?php
// app/Http/Requests/StoreProduitRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererCatalogue', $this->route('entreprise'));
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'categorie_id' => ['nullable', 'exists:categories,id'],
            'prix' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:5120'], // 5 Mo max
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:100'],
        ];
    }
}
