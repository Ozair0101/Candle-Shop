<?php

namespace App\Http\Controllers\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CartController extends ApiController
{
    /**
     * Get or create cart for a user
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $cart = Cart::with(['items.product.images'])
            ->where('user_id', $request->user_id)
            ->first();

        if (!$cart) {
            // Create a new cart if doesn't exist
            $cart = Cart::create(['user_id' => $request->user_id]);
            $cart->load(['items.product.images']);
        }

        return $this->success($cart, 'Cart retrieved successfully');
    }

    /**
     * Add item to cart
     */
    public function addItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,product_id',
            'variant_id' => 'nullable|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // Get or create cart
            $cart = Cart::firstOrCreate(
                ['user_id' => $request->user_id]
            );

            // Check if item already exists in cart
            $cartItem = CartItem::where('cart_id', $cart->cart_id)
                ->where('product_id', $request->product_id)
                ->where('variant_id', $request->variant_id)
                ->first();

            if ($cartItem) {
                // Update quantity if item exists
                $cartItem->quantity += $request->quantity;
                $cartItem->save();
            } else {
                // Create new cart item
                $cartItem = CartItem::create([
                    'cart_id' => $cart->cart_id,
                    'product_id' => $request->product_id,
                    'variant_id' => $request->variant_id,
                    'quantity' => $request->quantity,
                ]);
            }

            DB::commit();

            // Load relationships
            $cart->load(['items.product.images']);

            return $this->success($cart, 'Item added to cart successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to add item to cart: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update cart item quantity
     */
    public function updateItem(Request $request, $cartItemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation Error', 422, $validator->errors());
        }

        $cartItem = CartItem::find($cartItemId);

        if (!$cartItem) {
            return $this->error('Cart item not found', 404);
        }

        $cartItem->quantity = $request->quantity;
        $cartItem->save();

        // Load the cart with items
        $cart = Cart::with(['items.product.images'])
            ->find($cartItem->cart_id);

        return $this->success($cart, 'Cart item updated successfully');
    }

    /**
     * Remove item from cart
     */
    public function removeItem($cartItemId)
    {
        $cartItem = CartItem::find($cartItemId);

        if (!$cartItem) {
            return $this->error('Cart item not found', 404);
        }

        $cartId = $cartItem->cart_id;
        $cartItem->delete();

        // Load the updated cart
        $cart = Cart::with(['items.product.images'])->find($cartId);

        return $this->success($cart, 'Item removed from cart successfully');
    }

    /**
     * Clear all items from cart
     */
    public function clearCart($cartId)
    {
        $cart = Cart::find($cartId);

        if (!$cart) {
            return $this->error('Cart not found', 404);
        }

        $cart->items()->delete();

        return $this->success(null, 'Cart cleared successfully');
    }

    /**
     * Delete entire cart
     */
    public function destroy($cartId)
    {
        $cart = Cart::find($cartId);

        if (!$cart) {
            return $this->error('Cart not found', 404);
        }

        $cart->delete();

        return $this->success(null, 'Cart deleted successfully');
    }
}
