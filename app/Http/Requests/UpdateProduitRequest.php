<?php
// app/Http/Requests/UpdateProduitRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateProduitRequest extends FormRequest
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
            'image' => ['nullable', $this->imageRule()], // fichier ou URL Cloudinary
            'remove_image' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function imageRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if ($value instanceof UploadedFile) {
                $validator = validator(
                    ['image' => $value],
                    ['image' => ['image', 'max:5120']],
                );
                if ($validator->fails()) {
                    $fail($validator->errors()->first('image'));
                }
                return;
            }
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false) {
                return;
            }
            $fail('L\'image doit être un fichier image (max 5 Mo) ou une URL valide.');
        };
    }
}