<?php
// app/Http/Requests/StoreCategorieRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererCatalogue', $this->route('entreprise'));
    }

    public function rules(): array
    {
        $entreprise = $this->route('entreprise');

        return [
            'nom' => [
                'required', 'string', 'max:255',
                Rule::unique('categories')->where(fn ($q) => $q->where('entreprise_id', $entreprise->id)),
            ],
            'couleur' => ['nullable', 'string', 'max:20'],
        ];
    }
}
