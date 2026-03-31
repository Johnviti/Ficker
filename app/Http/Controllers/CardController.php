<?php

namespace App\Http\Controllers;

use App\Exceptions\CardInvoicePaymentException;
use App\Models\Card;
use App\Models\Flag;
use App\Models\Installment;
use App\Services\Cards\CardCreationService;
use App\Services\Cards\CardInvoicePaymentService;
use App\Services\Cards\CardInvoiceSummaryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CardController extends Controller
{
    public function __construct(
        private readonly CardInvoicePaymentService $cardInvoicePaymentService,
        private readonly CardCreationService $cardCreationService,
        private readonly CardInvoiceSummaryService $cardInvoiceSummaryService
    ) {
    }

    private function nextInvoicePayDay(Card $card): ?string
    {
        return $this->cardInvoiceSummaryService->nextOpenInvoicePayDay($card);
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->cardCreationService->create(Auth::id(), $request->all());

        return response()->json([
            'card' => $result['card']
        ], 201);
    }

    public function showCards(): JsonResponse
    {
        $cards = Auth::user()->cards;
        $response = ['data' => ['cards' => []]];

        foreach ($cards as $card) {
            $card->invoice = $this->invoice($card->id);
            $response['data']['cards'][] = $card;
        }

        return response()->json($response, 200);
    }

    public function showFlags(): JsonResponse
    {
        $flags = Flag::all();
        $response = ['data' => ['flags' => []]];

        foreach ($flags as $flag) {
            $response['data']['flags'][] = $flag;
        }

        return response()->json($response, 200);
    }

    public function invoice($id)
    {
        $card = Card::where('user_id', Auth::id())->find($id);

        if (!$card) {
            return 0;
        }

        return (float) ($this->cardInvoiceSummaryService->currentInvoice($card)['invoice'] ?? 0);
    }

    public function showCardInvoice($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);
            $summary = $this->cardInvoiceSummaryService->currentInvoice($card);

            return response()->json([
                'data' => [
                    'invoice' => $summary['invoice'],
                    'pay_day' => $summary['pay_day'],
                    'total' => $summary['total'],
                    'paid_total' => $summary['paid_total'],
                    'open_total' => $summary['open_total'],
                    'status' => $summary['status'],
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
        }
    }

    public function showInvoiceInstallments($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $nextPayDay = $this->nextInvoicePayDay($card);
            $installments = [];

            if ($nextPayDay) {
                $installments = Installment::where('card_id', $card->id)
                    ->whereNull('paid_at')
                    ->whereDate('pay_day', $nextPayDay)
                    ->get();
            }

            return response()->json([
                'data' => [
                    'pay_day' => $nextPayDay,
                    'installments' => $installments
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
        }
    }

    public function showInvoices($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $invoices = $this->cardInvoiceSummaryService
                ->summariesForCard($card)
                ->map(fn (array $summary) => [
                    'pay_day' => $summary['pay_day'],
                    'closure_date' => $summary['closure_date'],
                    'total' => $summary['total'],
                    'paid_total' => $summary['paid_total'],
                    'open_total' => $summary['open_total'],
                    'is_paid' => $summary['is_paid'],
                    'installments_count' => $summary['installments_count'],
                    'paid_at' => $summary['paid_at'],
                    'payment_transaction_id' => $summary['payment_transaction_id'],
                    'status' => $summary['status'],
                    'payment_count' => $summary['payment_count'],
                ])
                ->all();

            return response()->json([
                'data' => [
                    'card_id' => $card->id,
                    'invoices' => $invoices
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado ou sem faturas.', 404);
        }
    }

    public function payInvoiceByPayDay(Request $request, $id, $payDay): JsonResponse
    {
        try {
            $result = $this->cardInvoicePaymentService->payInvoiceByPayDay(Auth::id(), (int) $id, (string) $payDay, $request->all());

            return response()->json([
                'data' => [
                    'message' => 'Fatura paga com sucesso.',
                    'card_id' => $result['card']->id,
                    'pay_day' => $result['pay_day'],
                    'invoice_value' => $result['invoice_value'],
                    'amount_paid' => $result['amount_paid'],
                    'invoice_total' => $result['invoice_total'],
                    'paid_total' => $result['paid_total'],
                    'open_total' => $result['open_total'],
                    'status' => $result['invoice_status'],
                    'payment_transaction' => $result['payment_transaction'],
                ]
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->validator->errors()->first(), 422, $e->errors());
        } catch (CardInvoicePaymentException $e) {
            return $this->errorResponse($e->getMessage(), $e->status(), $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao pagar fatura.', 500);
        }
    }

    public function payNextInvoice(Request $request, $id): JsonResponse
    {
        try {
            $result = $this->cardInvoicePaymentService->payNextInvoice(Auth::id(), (int) $id, $request->all());

            return response()->json([
                'data' => [
                    'message' => 'Fatura paga com sucesso.',
                    'card_id' => $result['card']->id,
                    'pay_day' => $result['pay_day'],
                    'invoice_value' => $result['invoice_value'],
                    'amount_paid' => $result['amount_paid'],
                    'invoice_total' => $result['invoice_total'],
                    'paid_total' => $result['paid_total'],
                    'open_total' => $result['open_total'],
                    'status' => $result['invoice_status'],
                    'payment_transaction' => $result['payment_transaction'],
                ]
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->validator->errors()->first(), 422, $e->errors());
        } catch (CardInvoicePaymentException $e) {
            return $this->errorResponse($e->getMessage(), $e->status(), $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao pagar fatura.', 500);
        }
    }
}
