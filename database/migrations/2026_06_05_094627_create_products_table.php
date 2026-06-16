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
            
            // Core Product Data
            $table->string('name'); // Full visible name (e.g., "Varta Silver Dynamic E44")
            $table->string('brand'); // Explicitly for filtering (e.g., "Varta", "Bosch")
            $table->integer('amperage')->nullable(); // Battery Capacity in Ah (e.g., 74, 60, 100)
            $table->string('application_type'); // Category (e.g., "car", "motorcycle", "solar")
            
            // Pricing & Stock
            $table->decimal('price', 10, 2);
            $table->boolean('is_discountable')->default(true);
            $table->unsignedTinyInteger('discount_percentage')->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->enum('status', ['draft', 'active', 'out_of_stock', 'archived'])->default('active');
            
            $table->timestamps();
            $table->softDeletes();
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