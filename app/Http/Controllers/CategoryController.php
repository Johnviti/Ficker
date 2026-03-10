<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category_description' => ['required', 'string', 'min:2', 'max:50'],
            'type_id' => ['required', 'integer', 'in:1,2,3'],
        ], [
            'category_description.required' => 'Informe a descrição da categoria.',
            'category_description.string' => 'A descrição da categoria deve ser um texto.',
            'category_description.min' => 'A descrição da categoria deve ter pelo menos 2 caracteres.',
            'category_description.max' => 'A descrição da categoria deve ter no máximo 50 caracteres.',
            'type_id.required' => 'Informe o tipo da categoria.',
            'type_id.integer' => 'O tipo da categoria deve ser numérico.',
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
            return response()->json([
                'data' => [
                    'message' => 'A categoria não foi criada.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public static function storeInTransaction($description, $type)
    {
        if (!is_string($description) || trim($description) === '') {
            throw new \InvalidArgumentException('Descrição da categoria inválida.');
        }

        if (!in_array((int) $type, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Tipo de categoria inválido.');
        }

        return Category::create([
            'user_id' => Auth::id(),
            'category_description' => trim($description),
            'type_id' => (int) $type
        ]);
    }

    public function showCategories(): JsonResponse
    {
        try {

            $categories = [];

            foreach (Auth::user()->categories as $category) {

                $category_spending = Transaction::whereMonth('date', now()->month)
                    ->where('category_id', $category->id)
                    ->where('type_id', 2)
                    ->sum('transaction_value');

                $category->category_spending = $category_spending;
                array_push($categories, $category);
            }

            $response = [
                'data' => [
                    'categories' => $categories
                ]
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = "Nenhuma categoria foi encontrada";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];

            return response()->json($response, 404);
        }
    }

    public function showCategoriesByType($id): JsonResponse
    {
        if (!in_array((int) $id, [1, 2, 3], true)) {
            return response()->json([
                'data' => [
                    'message' => 'Tipo de categoria inválido.'
                ]
            ], 422);
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
            return response()->json([
                'data' => [
                    'message' => 'Categoria não encontrada.'
                ]
            ], 404);
        }

        return response()->json([
            'data' => [
                'category_description' => $category->category_description
            ]
        ], 200);
    }
}
