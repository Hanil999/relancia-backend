<?php
// app/Http/Requests/UpdateCategorieRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererCatalogue', $this->route('entreprise'));
    }

    public function rules(): array
    {
        $entreprise = $this->route('entreprise');
        $categorie = $this->route('categorie');

        return [
            'nom' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('categories')
                    ->where(fn ($q) => $q->where('entreprise_id', $entreprise->id))
                    ->ignore($categorie->id),
            ],
            'couleur' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
