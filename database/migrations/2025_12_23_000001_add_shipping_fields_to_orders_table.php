<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('email')->after('payment_method');
            $table->string('first_name')->after('email');
            $table->string('last_name')->after('first_name');
            $table->string('address')->after('last_name');
            $table->string('city')->after('address');
            $table->string('state', 100)->after('city');
            $table->string('zip_code', 20)->after('state');
            $table->string('phone', 50)->after('zip_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'first_name',
                'last_name',
                'address',
                'city',
                'state',
                'zip_code',
                'phone',
            ]);
        });
    }
};
