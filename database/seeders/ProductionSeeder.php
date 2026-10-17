<?php

namespace Database\Seeders;

use App\Actions\Production\SetOverheadRateAction;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductionFormula;
use App\Models\User;
use App\Models\WorkCenter;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::where('role', UserRole::Production->value)->first() ?? User::query()->orderBy('id')->firstOrFail();

        $products = [
            'WIP-001' => ['product_name' => 'Badan Termos', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-002' => ['product_name' => 'Tutup Termos', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'FG-001' => ['product_name' => 'Termos 1L Merah', 'unit_of_measure' => 'Pcs', 'type' => 'finished_goods'],
        ];

        foreach ($products as $code => $attributes) {
            Product::firstOrCreate(['product_code' => $code], $attributes);
        }

        $id = fn (string $code) => Product::where('product_code', $code)->value('id');

        $workCenter = fn (string $code) => WorkCenter::where('code', $code)->firstOrFail();

        $estimates = [
            'MOLDING' => ['hours' => '1800', 'components' => ['electricity' => '14000000', 'machine_depreciation' => '7200000', 'mold_depreciation' => '2800000', 'maintenance' => '2000000', 'other' => '1000000']],
            'ASSEMBLY' => ['hours' => '200', 'components' => ['electricity' => '3000000', 'machine_depreciation' => '3500000', 'maintenance' => '1000000', 'other' => '500000']],
        ];

        foreach ($estimates as $code => $estimate) {
            if ($workCenter($code)->overheadRates()->doesntExist()) {
                app(SetOverheadRateAction::class)->handle($workCenter($code), $estimate['components'], $estimate['hours'], '2026-01-01', $creator->id);
            }
        }

        $formulas = [
            'F-WIP-001' => [
                'product' => 'WIP-001', 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => ['RAW-001' => '13.60', 'RAW-004' => '0.50'],
            ],
            'F-WIP-002' => [
                'product' => 'WIP-002', 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => ['RAW-005' => '4.00'],
            ],
            'F-FG-001' => [
                'product' => 'FG-001', 'work_center' => 'ASSEMBLY', 'output_quantity' => '100',
                'items' => ['WIP-001' => '100', 'WIP-002' => '100'],
            ],
        ];

        foreach ($formulas as $code => $formula) {
            $model = ProductionFormula::firstOrCreate(['formula_code' => $code], [
                'product_id' => $id($formula['product']),
                'work_center_id' => $workCenter($formula['work_center'])->id,
                'output_quantity' => $formula['output_quantity'],
                'is_active' => true,
                'created_by' => $creator->id,
            ]);

            foreach ($formula['items'] as $materialCode => $quantity) {
                $model->items()->firstOrCreate(
                    ['material_product_id' => $id($materialCode)],
                    ['quantity' => $quantity],
                );
            }
        }
    }
}
