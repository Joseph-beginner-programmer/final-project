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

        $colors = ['Biru', 'Pink', 'Hijau', 'Gold', 'Merah', 'Putih'];

        $products = [
            'WIP-001' => ['product_name' => 'Handel Biru Muda', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-002' => ['product_name' => 'Handel Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-003' => ['product_name' => 'Handel Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-004' => ['product_name' => 'Handel Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-005' => ['product_name' => 'Handel Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-006' => ['product_name' => 'Handel Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-007' => ['product_name' => 'Cangkir / Tutup Atas HTC - Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-008' => ['product_name' => 'Cangkir / Tutup Atas HTC - Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-009' => ['product_name' => 'Cangkir / Tutup Atas HTC - Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-010' => ['product_name' => 'Cangkir / Tutup Atas HTC - Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-011' => ['product_name' => 'Cangkir / Tutup Atas HTC - Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-012' => ['product_name' => 'Cangkir / Tutup Atas HTC - Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-013' => ['product_name' => 'Kaki Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-014' => ['product_name' => 'Kaki Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-015' => ['product_name' => 'Kaki Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-016' => ['product_name' => 'Kaki Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-017' => ['product_name' => 'Kaki Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-018' => ['product_name' => 'Kaki Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-019' => ['product_name' => 'Body Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-020' => ['product_name' => 'Body Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-021' => ['product_name' => 'Body Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-022' => ['product_name' => 'Body Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-023' => ['product_name' => 'Body Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-024' => ['product_name' => 'Body Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-025' => ['product_name' => 'Leher Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-026' => ['product_name' => 'Leher Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-027' => ['product_name' => 'Leher Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-028' => ['product_name' => 'Leher Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-029' => ['product_name' => 'Leher Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-030' => ['product_name' => 'Leher Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-031' => ['product_name' => 'Kepala Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-032' => ['product_name' => 'Kepala Pink', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-033' => ['product_name' => 'Kepala Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-034' => ['product_name' => 'Kepala Gold', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-035' => ['product_name' => 'Kepala Merah', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-036' => ['product_name' => 'Kepala Putih', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-037' => ['product_name' => 'Tutup Dalam Bulat Putih ( HPC , DSC )', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-038' => ['product_name' => 'Tutup Bawah Hitam ( HTC, HPC, DSC )', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-039' => ['product_name' => 'Seal 34 - 2 Ltr', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'FG-001' => ['product_name' => 'Thermos APC 888 Biru Muda', 'unit_of_measure' => 'Pcs', 'type' => 'finished_goods', 'selling_price' => '150000'],
            'FG-002' => ['product_name' => 'Thermos APC 888 Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'finished_goods', 'selling_price' => '150000'],
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

        $pewarna = ['Biru' => 'RAW-002', 'Pink' => 'RAW-003', 'Hijau' => 'RAW-004', 'Gold' => 'RAW-005', 'Merah' => 'RAW-006', 'Putih' => 'RAW-007'];
        $ppColor = ['Biru' => 'RAW-009', 'Pink' => 'RAW-010', 'Hijau' => 'RAW-011', 'Gold' => 'RAW-012', 'Merah' => 'RAW-013', 'Putih' => 'RAW-014'];
        $mastebath = ['Biru' => 'RAW-015', 'Pink' => 'RAW-016', 'Hijau' => 'RAW-017', 'Gold' => 'RAW-018', 'Merah' => 'RAW-019', 'Putih' => 'RAW-020'];

        $formulas = [];

        $handelWip = ['Biru' => 'WIP-001', 'Pink' => 'WIP-002', 'Hijau' => 'WIP-003', 'Gold' => 'WIP-004', 'Merah' => 'WIP-005', 'Putih' => 'WIP-006'];
        foreach ($colors as $color) {
            $formulas["F-{$handelWip[$color]}"] = [
                'product' => $handelWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => [$pewarna[$color] => '0.23', 'RAW-001' => '23'],
            ];
        }

        $cangkirWip = ['Biru' => 'WIP-007', 'Pink' => 'WIP-008', 'Hijau' => 'WIP-009', 'Gold' => 'WIP-010', 'Merah' => 'WIP-011', 'Putih' => 'WIP-012'];
        foreach ($colors as $color) {
            $formulas["F-{$cangkirWip[$color]}"] = [
                'product' => $cangkirWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => [$pewarna[$color] => '0.19', 'RAW-001' => '19'],
            ];
        }

        $kakiWip = ['Biru' => 'WIP-013', 'Pink' => 'WIP-014', 'Hijau' => 'WIP-015', 'Gold' => 'WIP-016', 'Merah' => 'WIP-017', 'Putih' => 'WIP-018'];
        foreach ($colors as $color) {
            $formulas["F-{$kakiWip[$color]}"] = [
                'product' => $kakiWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => [$ppColor[$color] => '24', 'RAW-001' => '1.9', $mastebath[$color] => '0.26'],
            ];
        }

        $bodyWip = ['Biru' => 'WIP-019', 'Pink' => 'WIP-020', 'Hijau' => 'WIP-021', 'Gold' => 'WIP-022', 'Merah' => 'WIP-023', 'Putih' => 'WIP-024'];
        foreach ($colors as $color) {
            $formulas["F-{$bodyWip[$color]}"] = [
                'product' => $bodyWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => ['RAW-001' => '120.3', $pewarna[$color] => '0.72'],
            ];
        }

        $leherWip = ['Biru' => 'WIP-025', 'Pink' => 'WIP-026', 'Hijau' => 'WIP-027', 'Gold' => 'WIP-028', 'Merah' => 'WIP-029', 'Putih' => 'WIP-030'];
        foreach ($colors as $color) {
            $formulas["F-{$leherWip[$color]}"] = [
                'product' => $leherWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => ['RAW-001' => '28', $pewarna[$color] => '0.28'],
            ];
        }

        $kepalaWip = ['Biru' => 'WIP-031', 'Pink' => 'WIP-032', 'Hijau' => 'WIP-033', 'Gold' => 'WIP-034', 'Merah' => 'WIP-035', 'Putih' => 'WIP-036'];
        foreach ($colors as $color) {
            $formulas["F-{$kepalaWip[$color]}"] = [
                'product' => $kepalaWip[$color], 'work_center' => 'MOLDING', 'output_quantity' => '100',
                'items' => ['RAW-001' => '42.2', $pewarna[$color] => '0.25'],
            ];
        }

        $formulas['F-WIP-037'] = [
            'product' => 'WIP-037', 'work_center' => 'MOLDING', 'output_quantity' => '100',
            'items' => ['RAW-007' => '0.12', 'RAW-001' => '12'],
        ];
        $formulas['F-WIP-038'] = [
            'product' => 'WIP-038', 'work_center' => 'MOLDING', 'output_quantity' => '100',
            'items' => ['RAW-008' => '0.15', 'RAW-001' => '15'],
        ];
        $formulas['F-WIP-039'] = [
            'product' => 'WIP-039', 'work_center' => 'MOLDING', 'output_quantity' => '100',
            'items' => ['RAW-021' => '20.5'],
        ];

        $packaging = ['RAW-022' => '1', 'RAW-023' => '1', 'RAW-024' => '1', 'RAW-025' => '0.17'];

        $formulas['F-FG-001'] = [
            'product' => 'FG-001', 'work_center' => 'ASSEMBLY', 'output_quantity' => '1',
            'items' => ['WIP-001' => '1', 'WIP-007' => '1', 'WIP-013' => '1', 'WIP-019' => '1', 'WIP-025' => '1', 'WIP-031' => '1', 'WIP-037' => '1', 'WIP-038' => '1', 'WIP-039' => '1'] + $packaging,
        ];
        $formulas['F-FG-002'] = [
            'product' => 'FG-002', 'work_center' => 'ASSEMBLY', 'output_quantity' => '1',
            'items' => ['WIP-003' => '1', 'WIP-009' => '1', 'WIP-015' => '1', 'WIP-021' => '1', 'WIP-027' => '1', 'WIP-033' => '1', 'WIP-037' => '1', 'WIP-038' => '1', 'WIP-039' => '1'] + $packaging,
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
