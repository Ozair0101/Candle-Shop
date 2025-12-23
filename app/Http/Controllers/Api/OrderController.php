<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Cart;
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

        $order->status = $request->status;
        $order->save();

        $order->load(['items.product.images']);

        return $this->success($order, 'Order status updated successfully');
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

        $order->status = 'cancelled';
        $order->save();

        $order->load(['items.product.images']);

        return $this->success($order, 'Order cancelled successfully');
    }
}
