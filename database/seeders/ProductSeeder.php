<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $batteries = [
            // --- CAR BATTERIES ---
            [
                'name' => 'Varta Silver Dynamic E44',
                'brand' => 'Varta',
                'amperage' => 77,
                'application_type' => 'car',
                'price' => 1250.00,
                'is_discountable' => true,
                'discount_percentage' => 5,
                'stock_quantity' => 12,
                'status' => 'active',
            ],
            [
                'name' => 'Bosch S4 008',
                'brand' => 'Bosch',
                'amperage' => 74,
                'application_type' => 'car',
                'price' => 1100.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 15,
                'status' => 'active',
            ],
            [
                'name' => 'Bosch S5 A08 AGM',
                'brand' => 'Bosch',
                'amperage' => 70,
                'application_type' => 'car',
                'price' => 1850.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 6,
                'status' => 'active',
            ],
            [
                'name' => 'Tudor High Tech TA640',
                'brand' => 'Tudor',
                'amperage' => 64,
                'application_type' => 'car',
                'price' => 890.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 20,
                'status' => 'active',
            ],
            [
                'name' => 'Varta Blue Dynamic D59',
                'brand' => 'Varta',
                'amperage' => 60,
                'application_type' => 'car',
                'price' => 950.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 8,
                'status' => 'active',
            ],

            // --- MOTORCYCLE BATTERIES ---
            [
                'name' => 'Yuasa YTX9-BS MF',
                'brand' => 'Yuasa',
                'amperage' => 8,
                'application_type' => 'motorcycle',
                'price' => 420.00,
                'is_discountable' => true,
                'discount_percentage' => 8,
                'stock_quantity' => 25,
                'status' => 'active',
            ],
            [
                'name' => 'BS Battery BTX7A',
                'brand' => 'BS Battery',
                'amperage' => 6,
                'application_type' => 'motorcycle',
                'price' => 310.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 18,
                'status' => 'active',
            ],
            [
                'name' => 'Skyrich Lithium Ion HJTX5L',
                'brand' => 'Skyrich',
                'amperage' => 5,
                'application_type' => 'motorcycle',
                'price' => 850.00,
                'is_discountable' => true,
                'discount_percentage' => 15,
                'stock_quantity' => 4,
                'status' => 'active',
            ],

            // --- TRUCK BATTERIES ---
            [
                'name' => 'Varta Promotive Black K10',
                'brand' => 'Varta',
                'amperage' => 143,
                'application_type' => 'truck',
                'price' => 1950.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 5,
                'status' => 'active',
            ],
            [
                'name' => 'Bosch T4 077 Heavy Duty',
                'brand' => 'Bosch',
                'amperage' => 170,
                'application_type' => 'truck',
                'price' => 2450.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 0, // Out of stock check
                'status' => 'active',
            ],

            // --- SOLAR DEEP CYCLE BATTERIES ---
            [
                'name' => 'Victron Energy AGM Super Cycle',
                'brand' => 'Victron',
                'amperage' => 100,
                'application_type' => 'solar',
                'price' => 2900.00,
                'is_discountable' => true,
                'discount_percentage' => 5,
                'stock_quantity' => 8,
                'status' => 'active',
            ],
            [
                'name' => 'Ritar DG12-100 Gel',
                'brand' => 'Ritar',
                'amperage' => 100,
                'application_type' => 'solar',
                'price' => 1850.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 11,
                'status' => 'active',
            ],
            [
                'name' => 'Ultracell UCG200-12 Gel',
                'brand' => 'Ultracell',
                'amperage' => 200,
                'application_type' => 'solar',
                'price' => 3600.00,
                'is_discountable' => true,
                'discount_percentage' => 12,
                'stock_quantity' => 14,
                'status' => 'active',
            ],
        ];

        foreach ($batteries as $battery) {
            Product::create($battery);
        }
    }
}