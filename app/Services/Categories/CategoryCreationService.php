<?php

namespace App\Services\Categories;

use App\Http\Controllers\LevelController;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CategoryCreationService
{
    /**
     * @throws ValidationException
     */
    public function create(int $userId, array $payload): array
    {
        $validated = Validator::make($payload, [
            'category_description' => ['required', 'string', 'min:2', 'max:50'],
            'type_id' => ['required', 'integer', 'in:1,2,3'],
        ], [
            'category_description.required' => 'Informe a descricao da categoria.',
            'category_description.string' => 'A descricao da categoria deve ser um texto.',
            'category_description.min' => 'A descricao da categoria deve ter pelo menos 2 caracteres.',
            'category_description.max' => 'A descricao da categoria deve ter no maximo 50 caracteres.',
            'type_id.required' => 'Informe o tipo da categoria.',
            'type_id.integer' => 'O tipo da categoria deve ser numerico.',
            'type_id.in' => 'O tipo da categoria deve ser 1, 2 ou 3.',
        ])->validate();

        $category = Category::create([
            'user_id' => $userId,
            'category_description' => trim((string) $validated['category_description']),
            'type_id' => (int) $validated['type_id'],
        ]);

        LevelController::completeMission(5);

        return [
            'status' => 'created',
            'category' => $category,
        ];
    }
}
