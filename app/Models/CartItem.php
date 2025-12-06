<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $primaryKey = 'cart_item_id';
    
    public $timestamps = false;
    
    protected $fillable = [
        'cart_id',
        'product_id',
        'variant_id',
        'quantity',
    ];
    
    protected $casts = [
        'quantity' => 'integer',
    ];

    // Relationships
    public function cart()
    {
        return $this->belongsTo(Cart::class, 'cart_id', 'cart_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
