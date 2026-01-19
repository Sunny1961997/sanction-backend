<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('products')->insert([
            [
                'name' => '18K Gold Jewellery',
                'sku' => 'GOLD-18K',
                'description' => '18 karat gold jewellery (GOAML code)',
                'price' => 1200.00,
                'stock' => 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '21K Gold Jewellery',
                'sku' => 'GOLD-21K',
                'description' => '21 karat gold jewellery',
                'price' => 1400.00,
                'stock' => 80,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '22K Gold Jewellery',
                'sku' => 'GOLD-22K',
                'description' => '22 karat gold jewellery',
                'price' => 1500.00,
                'stock' => 60,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Mined Gold',
                'sku' => 'GOLD-MINED',
                'description' => 'Raw mined gold',
                'price' => 1450.00,
                'stock' => 25,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Dore Bars',
                'sku' => 'GOLD-DORE',
                'description' => 'Dore bars (unrefined gold-silver alloy)',
                'price' => 1300.00,
                'stock' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold Nuggets',
                'sku' => 'GOLD-NUGGET',
                'description' => 'Natural gold nuggets',
                'price' => 1600.00,
                'stock' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Scrap Gold',
                'sku' => 'GOLD-SCRAP',
                'description' => 'Scrap gold for recycling',
                'price' => 1100.00,
                'stock' => 200,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold Coins',
                'sku' => 'GOLD-COIN',
                'description' => 'Minted gold coins',
                'price' => 1700.00,
                'stock' => 40,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold Dust',
                'sku' => 'GOLD-DUST',
                'description' => 'Gold dust',
                'price' => 900.00,
                'stock' => 300,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
