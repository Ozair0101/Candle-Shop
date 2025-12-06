<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends ApiController
{
    /**
     * Get all payments (with optional filters)
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable|exists:orders,order_id',
            'status' => 'nullable|in:pending,success,failed,refunded',
            'payment_provider' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $query = Payment::with(['order.user', 'order.items.product']);

        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_provider')) {
            $query->where('payment_provider', $request->payment_provider);
        }

        $payments = $query->orderBy('created_at', 'desc')->get();

        return $this->success($payments, 'Payments retrieved successfully');
    }

    /**
     * Create a new payment
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,order_id',
            'amount' => 'required|numeric|min:0',
            'payment_provider' => 'required|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Verify order exists and get order amount
            $order = Order::find($request->order_id);
            
            if (!$order) {
                DB::rollBack();
                return $this->error('Order not found', 404);
            }

            // Check if amount matches order total
            if (bccomp($request->amount, $order->total_amount, 2) !== 0) {
                DB::rollBack();
                return $this->error('Payment amount does not match order total', 400);
            }

            // Create the payment
            $payment = Payment::create([
                'order_id' => $request->order_id,
                'status' => 'pending',
                'amount' => $request->amount,
                'transaction_id' => $request->transaction_id,
                'payment_provider' => $request->payment_provider,
            ]);

            DB::commit();

            $payment->load(['order.user', 'order.items.product']);

            return $this->success($payment, 'Payment created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a specific payment
     */
    public function show($paymentId)
    {
        $payment = Payment::with(['order.user', 'order.items.product'])->find($paymentId);

        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        return $this->success($payment, 'Payment retrieved successfully');
    }

    /**
     * Update payment status
     */
    public function update(Request $request, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,success,failed,refunded',
            'transaction_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        try {
            DB::beginTransaction();

            // Update payment status
            $payment->status = $request->status;
            
            if ($request->has('transaction_id')) {
                $payment->transaction_id = $request->transaction_id;
            }
            
            $payment->save();

            // Update order status based on payment status
            $order = Order::find($payment->order_id);
            
            if ($request->status === 'success' && $order->status === 'pending') {
                $order->status = 'paid';
                $order->save();
            }

            DB::commit();

            $payment->load(['order.user', 'order.items.product']);

            return $this->success($payment, 'Payment status updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a payment
     */
    public function destroy($paymentId)
    {
        $payment = Payment::find($paymentId);

        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        // Only allow deletion of pending or failed payments
        if (!in_array($payment->status, ['pending', 'failed'])) {
            return $this->error('Cannot delete payment with status: ' . $payment->status, 400);
        }

        $payment->delete();

        return $this->success(null, 'Payment deleted successfully');
    }

    /**
     * Process refund
     */
    public function refund(Request $request, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        // Only allow refund of successful payments
        if ($payment->status !== 'success') {
            return $this->error('Can only refund successful payments', 400);
        }

        try {
            DB::beginTransaction();

            $payment->status = 'refunded';
            
            if ($request->has('transaction_id')) {
                $payment->transaction_id = $request->transaction_id;
            }
            
            $payment->save();

            // Update order status to cancelled
            $order = Order::find($payment->order_id);
            if ($order && $order->status !== 'cancelled') {
                $order->status = 'cancelled';
                $order->save();
            }

            DB::commit();

            $payment->load(['order.user', 'order.items.product']);

            return $this->success($payment, 'Payment refunded successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to refund payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get payments by order
     */
    public function getByOrder($orderId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $payments = Payment::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($payments, 'Payments retrieved successfully');
    }
}
