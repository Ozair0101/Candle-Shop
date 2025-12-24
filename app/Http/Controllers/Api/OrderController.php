<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Cart;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends ApiController
{
    /**
     * Get all orders (with optional filters)
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:pending,paid,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $query = Order::with(['items.product.images', 'user']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        return $this->success($orders, 'Orders retrieved successfully');
    }

    /**
     * Create a new order
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'payment_method' => 'required|in:card,cod,paypal,other',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,product_id',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'email' => 'required|email',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:100',
            'zip_code' => 'required|string|max:20',
            'phone' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Calculate total amount
            $totalAmount = 0;
            $orderItems = [];

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                
                if (!$product) {
                    DB::rollBack();
                    return $this->error('Product not found: ' . $item['product_id'], 404);
                }

                $priceAtPurchase = $product->price;
                $subtotal = $priceAtPurchase * $item['quantity'];
                $totalAmount += $subtotal;

                $orderItems[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price_at_purchase' => $priceAtPurchase,
                ];
            }

            // Create the order
            $order = Order::create([
                'user_id' => $request->user_id,
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                'email' => $request->email,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'zip_code' => $request->zip_code,
                'phone' => $request->phone,
            ]);

            // Create order items
            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id' => $order->order_id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'price_at_purchase' => $item['price_at_purchase'],
                ]);
            }

            // Optionally clear the cart if from_cart_id is provided
            if ($request->has('from_cart_id')) {
                $cart = Cart::find($request->from_cart_id);
                if ($cart) {
                    $cart->items()->delete();
                }
            }

            // If payment method is COD, create a pending payment record
            if ($order->payment_method === 'cod') {
                Payment::create([
                    'order_id' => $order->order_id,
                    'status' => 'pending',
                    'amount' => $order->total_amount,
                    'transaction_id' => null,
                    'payment_provider' => 'cod',
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            // Load relationships
            $order->load(['items.product.images']);

            return $this->success($order, 'Order created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a specific order
     */
    public function show($orderId)
    {
        $order = Order::with(['items.product.images', 'user'])->find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        return $this->success($order, 'Order retrieved successfully');
    }

    /**
     * Update order status
     */
    public function update(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,paid,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $order = Order::find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        try {
            DB::beginTransaction();

            $order->status = $request->status;
            $order->save();

            // If this is a COD order, keep the related payment in sync
            if ($order->payment_method === 'cod') {
                $paymentsQuery = Payment::where('order_id', $order->order_id);

                // Ensure a payment record exists for this order
                if (!$paymentsQuery->exists()) {
                    Payment::create([
                        'order_id' => $order->order_id,
                        'status' => $request->status === 'delivered' ? 'success' : 'pending',
                        'amount' => $order->total_amount,
                        'transaction_id' => null,
                        'payment_provider' => 'cod',
                        'created_at' => now(),
                    ]);
                    // Refresh the query after creating
                    $paymentsQuery = Payment::where('order_id', $order->order_id);
                }

                if ($request->status === 'delivered') {
                    // Cash has been collected successfully
                    $paymentsQuery->update(['status' => 'success']);
                } elseif ($request->status === 'cancelled') {
                    // Order cancelled, mark any pending COD payment as failed
                    $paymentsQuery->where('status', 'pending')->update(['status' => 'failed']);
                }
            }

            DB::commit();

            $order->load(['items.product.images']);

            return $this->success($order, 'Order status updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update order status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete an order (only if pending or cancelled)
     */
    public function destroy($orderId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        // Only allow deletion of pending or cancelled orders
        if (!in_array($order->status, ['pending', 'cancelled'])) {
            return $this->error('Cannot delete order with status: ' . $order->status, 400);
        }

        $order->delete();

        return $this->success(null, 'Order deleted successfully');
    }

    /**
     * Cancel an order
     */
    public function cancel($orderId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        // Only allow cancellation of pending or paid orders
        if (in_array($order->status, ['shipped', 'delivered', 'cancelled'])) {
            return $this->error('Cannot cancel order with status: ' . $order->status, 400);
        }

        try {
            DB::beginTransaction();

            $order->status = 'cancelled';
            $order->save();

            // For COD orders, mark any pending payment as failed when order is cancelled,
            // and create one if it does not exist yet
            if ($order->payment_method === 'cod') {
                $paymentsQuery = Payment::where('order_id', $order->order_id);

                if (!$paymentsQuery->exists()) {
                    Payment::create([
                        'order_id' => $order->order_id,
                        'status' => 'failed',
                        'amount' => $order->total_amount,
                        'transaction_id' => null,
                        'payment_provider' => 'cod',
                        'created_at' => now(),
                    ]);
                } else {
                    $paymentsQuery
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                }
            }

            DB::commit();

            $order->load(['items.product.images']);

            return $this->success($order, 'Order cancelled successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to cancel order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update quantity of an order item (only for pending orders)
     */
    public function updateItem(Request $request, $orderId, $orderItemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $order = Order::with('items')->find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== 'pending') {
            return $this->error('Only pending orders can be modified', 400);
        }

        $item = OrderItem::where('order_id', $orderId)
            ->where('order_item_id', $orderItemId)
            ->first();

        if (!$item) {
            return $this->error('Order item not found', 404);
        }

        $item->quantity = $request->quantity;
        $item->save();

        // Recalculate total amount
        $totalAmount = OrderItem::where('order_id', $orderId)
            ->sum(DB::raw('quantity * price_at_purchase'));

        $order->total_amount = $totalAmount;
        $order->save();

        $order->load(['items.product.images', 'user']);

        return $this->success($order, 'Order item updated successfully');
    }

    /**
     * Remove an order item (only for pending orders)
     */
    public function removeItem($orderId, $orderItemId)
    {
        $order = Order::with('items')->find($orderId);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== 'pending') {
            return $this->error('Only pending orders can be modified', 400);
        }

        $item = OrderItem::where('order_id', $orderId)
            ->where('order_item_id', $orderItemId)
            ->first();

        if (!$item) {
            return $this->error('Order item not found', 404);
        }

        $item->delete();

        // Recalculate total amount (may become 0 if no items)
        $totalAmount = OrderItem::where('order_id', $orderId)
            ->sum(DB::raw('quantity * price_at_purchase'));

        $order->total_amount = $totalAmount;
        $order->save();

        $order->load(['items.product.images', 'user']);

        return $this->success($order, 'Order item removed successfully');
    }
}
