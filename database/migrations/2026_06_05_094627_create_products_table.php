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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            
            // Core Product Information
            $table->string('name');
            // Pricing & Discount Logic
            $table->decimal('price', 10, 2); // Standard retail price (e.g., 99999999.99 max)
            
            // Your Discountable logic split into two highly helpful columns:
            $table->boolean('is_discountable')->default(true); // Flag to explicitly enable/disable discount rules for this product
            $table->unsignedTinyInteger('discount_percentage')->default(0); // The percentage value (0 to 100) that can be reduced

            // Inventory & Management
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(5); // Alert threshold when stock runs thin
            
            // Status Flags
            $table->enum('status', ['draft', 'active', 'out_of_stock', 'archived'])->default('active');
            
            // Timestamps
            $table->timestamps(); // creates created_at and updated_at
            $table->softDeletes(); // optional: allows deleting a product without erasing it instantly from sales history
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};