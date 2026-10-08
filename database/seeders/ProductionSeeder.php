<?php

namespace Database\Seeders;

use App\Actions\Production\SetOverheadRateAction;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductionFormula;
use App\Models\User;
use App\Models\WorkCenter;
use Illuminate\Database\Seeder;

/**
 * Dev data for the Production module: WIP + finished-goods products and one formula each.
 * Two-hop chain: raw PP → WIP (body, lid), then WIP → finished thermos.
 * Idempotent (firstOrCreate on unique codes), so it's safe to re-run.
 */
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

        // work centers ship with their migration (reference data); dev overhead rates are set here
        $workCenter = fn (string $code) => WorkCenter::where('code', $code)->firstOrFail();

        foreach (['MOLDING' => '15000', 'ASSEMBLY' => '40000'] as $code => $rate) {
            if ($workCenter($code)->overheadRates()->doesntExist()) {
                app(SetOverheadRateAction::class)->handle($workCenter($code), $rate, '2026-01-01', $creator->id);
            }
        }

        // material quantities are per output_quantity (a batch of 100 pcs)
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
                'version' => 1,
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
