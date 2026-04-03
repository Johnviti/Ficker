<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Category;
use App\Services\Analysis\AnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly AnalysisService $analysisService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => $this->analysisService->buildSummary(Auth::id(), $filters),
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function timeline(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request, true);

            return response()->json([
                'data' => [
                    'series' => $this->analysisService->buildTimeline(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function cards(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => [
                    'cards' => $this->analysisService->buildCards(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => [
                    'invoices' => $this->analysisService->buildInvoices(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function categories(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => [
                    'categories' => $this->analysisService->buildCategories(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function composition(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => $this->analysisService->buildComposition(Auth::id(), $filters),
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => [
                    'payment_methods' => $this->analysisService->buildPaymentMethods(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function invoicePaymentMethods(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);

            return response()->json([
                'data' => [
                    'payment_methods' => $this->analysisService->buildInvoicePaymentMethods(Auth::id(), $filters),
                ],
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    public function topExpenses(Request $request): JsonResponse
    {
        try {
            $filters = $this->resolveFilters($request);
            $limit = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ])['limit'] ?? 10;

            return response()->json([
                'data' => $this->analysisService->buildTopExpenses(Auth::id(), $filters, (int) $limit),
                'filters' => $filters,
                'meta' => $this->analysisService->buildMeta($filters),
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os filtros informados sao invalidos.', 422, $e->errors());
        }
    }

    private function resolveFilters(Request $request, bool $withGrouping = false): array
    {
        $rules = [
            'date_from' => ['nullable', 'date', 'required_with:date_to'],
            'date_to' => ['nullable', 'date', 'required_with:date_from', 'after_or_equal:date_from'],
            'month' => ['nullable', 'integer', 'between:1,12', 'required_with:year'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100', 'required_with:month'],
            'card_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer', 'in:1,2'],
        ];

        if ($withGrouping) {
            $rules['group_by'] = ['nullable', 'string', 'in:month,day'];
        }

        $validated = $request->validate($rules, [
            'date_to.after_or_equal' => 'A data final deve ser igual ou posterior a data inicial.',
            'month.required_with' => 'Informe o mes quando o ano for enviado.',
            'year.required_with' => 'Informe o ano quando o mes for enviado.',
        ]);

        if (!empty($validated['card_id'])) {
            $card = Card::where('user_id', Auth::id())->find($validated['card_id']);

            if (!$card) {
                throw ValidationException::withMessages([
                    'card_id' => ['Cartao nao encontrado.'],
                ]);
            }
        }

        if (!empty($validated['category_id'])) {
            $category = Category::where('user_id', Auth::id())->find($validated['category_id']);

            if (!$category) {
                throw ValidationException::withMessages([
                    'category_id' => ['Categoria nao encontrada.'],
                ]);
            }
        }

        return $this->analysisService->resolveFilters($validated);
    }
}
