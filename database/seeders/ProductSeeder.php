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
                'name' => 'Varta Silver Dynamic E44 12V 77Ah',
                'price' => 1250.00,
                'is_discountable' => true,
                'discount_percentage' => 5,
                'stock_quantity' => 12,
                'status' => 'active',
            ],
            [
                'name' => 'Bosch S5 A08 AGM 12V 70Ah',
                'price' => 1850.00,
                'is_discountable' => true,
                'discount_percentage' => 12,
                'stock_quantity' => 6,
                'status' => 'active',
            ],
            [
                'name' => 'Optima RedTop RTC 4.2 12V 50Ah',
                'price' => 2400.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 3,
                'status' => 'active',
            ],
            [
                'name' => 'Tudor High Tech TA640 12V 64Ah',
                'price' => 890.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 20,
                'status' => 'active',
            ],
            [
                'name' => 'Fulmen Formula FA530 12V 53Ah',
                'price' => 720.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 0, // Test Out of Stock
                'status' => 'active',
            ],
            [
                'name' => 'Exide Premium Carbon Boost 12V 62Ah',
                'price' => 980.00,
                'is_discountable' => true,
                'discount_percentage' => 15,
                'stock_quantity' => 14,
                'status' => 'active',
            ],
            [
                'name' => 'ACDelco Gold B24R 12V 45Ah',
                'price' => 680.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 9,
                'status' => 'active',
            ],

            // --- MOTORCYCLE & SCOOTER BATTERIES ---
            [
                'name' => 'Yuasa YTX9-BS 12V 8Ah Maintenance Free',
                'price' => 420.00,
                'is_discountable' => true,
                'discount_percentage' => 8,
                'stock_quantity' => 25,
                'status' => 'active',
            ],
            [
                'name' => 'BS Battery BTX7A 12V 6Ah',
                'price' => 310.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 18,
                'status' => 'active',
            ],
            [
                'name' => 'Skyrich Lithium Ion HJTX5L-FP 12V',
                'price' => 850.00,
                'is_discountable' => true,
                'discount_percentage' => 20, // Premium item promo
                'stock_quantity' => 4,
                'status' => 'active',
            ],
            [
                'name' => 'Yuasa YTZ10S High Performance 12V 8.6Ah',
                'price' => 920.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 0, // Test Out of Stock
                'status' => 'active',
            ],

            // --- TRUCK & HEAVY VEHICLE BATTERIES ---
            [
                'name' => 'Varta Promotive Black K10 12V 143Ah',
                'price' => 1950.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 5,
                'status' => 'active',
            ],
            [
                'name' => 'Bosch T4 077 Heavy Duty 12V 170Ah',
                'price' => 2450.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 7,
                'status' => 'active',
            ],
            [
                'name' => 'Exide Expert HVR 12V 225Ah',
                'price' => 3200.00,
                'is_discountable' => true,
                'discount_percentage' => 15,
                'stock_quantity' => 2,
                'status' => 'active',
            ],

            // --- SOLAR & DEEP CYCLE AGM/GEL BATTERIES ---
            [
                'name' => 'Victron Energy AGM Super Cycle 12V 100Ah',
                'price' => 2900.00,
                'is_discountable' => true,
                'discount_percentage' => 5,
                'stock_quantity' => 8,
                'status' => 'active',
            ],
            [
                'name' => 'Ritar DG12-100 Deep Cycle Gel 12V 100Ah',
                'price' => 1850.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 11,
                'status' => 'active',
            ],
            [
                'name' => 'Narada High Rate 12V 150Ah Access AGM',
                'price' => 2750.00,
                'is_discountable' => true,
                'discount_percentage' => 10,
                'stock_quantity' => 15,
                'status' => 'active',
            ],
            [
                'name' => 'Ultracell UCG200-12 Solar Gel 12V 200Ah',
                'price' => 3600.00,
                'is_discountable' => true,
                'discount_percentage' => 12,
                'stock_quantity' => 0, // Test Out of Stock
                'status' => 'active',
            ],
            [
                'name' => 'Vision 6FM100-X Deep Cycle 12V 100Ah',
                'price' => 1650.00,
                'is_discountable' => false,
                'discount_percentage' => 0,
                'stock_quantity' => 22,
                'status' => 'active',
            ],
            [
                'name' => 'Pylontech US3000C Lithium LiFePO4 48V 74Ah',
                'price' => 14500.00,
                'is_discountable' => true,
                'discount_percentage' => 8,
                'stock_quantity' => 3,
                'status' => 'active',
            ],
        ];

        foreach ($batteries as $battery) {
            Product::create($battery);
        }
    }
}