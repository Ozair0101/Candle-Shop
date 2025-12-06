<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->unsignedBigInteger('order_id');
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending');
            $table->decimal('amount', 10, 2);
            $table->string('transaction_id')->nullable();
            $table->string('payment_provider')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('order_id')->references('order_id')->on('orders')->onDelete('cascade');
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
