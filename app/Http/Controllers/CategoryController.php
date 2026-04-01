<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\Categories\CategoryCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryCreationService $categoryCreationService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->categoryCreationService->create(Auth::id(), $request->all());

            return response()->json([
                'data' => [
                    'category' => $result['category']
                ]
            ], 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os dados informados sao invalidos.', 422, $e->errors());
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

            $categoryRealSpending = Transaction::whereMonth('date', now()->month)
                ->where('category_id', $category->id)
                ->where('type_id', 2)
                ->where(function ($query) {
                    $query->whereNull('payment_method_id')
                        ->orWhere('payment_method_id', '!=', 4);
                })
                ->sum('transaction_value');

            $category->category_spending = $categorySpending;
            $category->category_real_spending = $categoryRealSpending;
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
