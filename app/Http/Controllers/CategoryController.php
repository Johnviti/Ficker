<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
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
        ]);

        try {
            $category = Category::create([
                'user_id' => Auth::id(),
                'category_description' => $request->category_description,
                'type_id' => $request->type_id
            ]);

            LevelController::completeMission(5);

            return response()->json([
                'data' => [
                    'category' => $category
                ]
            ], 201);
        } catch (\Exception $e) {
            return $this->errorResponse('A categoria nao foi criada.', 500);
        }
    }

    public static function storeInTransaction($description, $type)
    {
        if (!is_string($description) || trim($description) === '') {
            throw new \InvalidArgumentException('Descricao da categoria invalida.');
        }

        if (!in_array((int) $type, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Tipo de categoria invalido.');
        }

        return Category::create([
            'user_id' => Auth::id(),
            'category_description' => trim($description),
            'type_id' => (int) $type
        ]);
    }

    public function showCategories(): JsonResponse
    {
        $categories = [];

        foreach (Auth::user()->categories as $category) {
            $categorySpending = Transaction::whereMonth('date', now()->month)
                ->where('category_id', $category->id)
                ->where('type_id', 2)
                ->sum('transaction_value');

            $category->category_spending = $categorySpending;
            $categories[] = $category;
        }

        return response()->json([
            'data' => [
                'categories' => $categories
            ]
        ], 200);
    }

    public function showCategoriesByType($id): JsonResponse
    {
        if (!in_array((int) $id, [1, 2, 3], true)) {
            return $this->errorResponse('Tipo de categoria invalido.', 422);
        }

        $categories = Category::where([
            'user_id' => Auth::id(),
            'type_id' => $id
        ])->get();

        return response()->json($categories, 200);
    }

    public function showCategory($id): JsonResponse
    {
        $category = Category::where('user_id', Auth::id())->find($id);

        if (!$category) {
            return $this->errorResponse('Categoria nao encontrada.', 404);
        }

        return response()->json([
            'data' => [
                'category_description' => $category->category_description
            ]
        ], 200);
    }
}
