<?php

namespace App\Enums;

enum AccountSystemRole: string
{
    case InventoryRaw = 'inventory_raw';
    case InventoryWip = 'inventory_wip';
    case InventoryFinished = 'inventory_finished';
    case GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';
    case WagesPayable = 'wages_payable';
    case OverheadApplied = 'overhead_applied';
    case MaterialVariance = 'material_variance';
    case ProductionLoss = 'production_loss';
    case Cogs = 'cogs';

    public function label(): string
    {
        return match ($this) {
            self::InventoryRaw => 'Persediaan Bahan Baku',
            self::InventoryWip => 'Persediaan Barang Dalam Proses',
            self::InventoryFinished => 'Persediaan Barang Jadi',
            self::GoodsReceivedNotInvoiced => 'Barang Diterima Belum Difakturkan',
            self::WagesPayable => 'Utang Gaji',
            self::OverheadApplied => 'BOP Dibebankan',
            self::MaterialVariance => 'Selisih Bahan',
            self::ProductionLoss => 'Kerugian Produksi',
            self::Cogs => 'Harga Pokok Penjualan',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::InventoryRaw => 'Didebit saat menerima barang dari pemasok, dikredit saat bahan dikeluarkan ke produksi.',
            self::InventoryWip => 'Menampung biaya bahan, tenaga kerja, dan overhead selama barang masih dalam proses produksi.',
            self::InventoryFinished => 'Didebit saat produk jadi diterima dari produksi, dikredit saat dijual.',
            self::GoodsReceivedNotInvoiced => 'Menampung nilai barang yang sudah diterima tetapi fakturnya belum dicatat.',
            self::WagesPayable => 'Dikredit sebesar biaya tenaga kerja yang dibebankan ke hasil produksi.',
            self::OverheadApplied => 'Dikredit sebesar BOP yang dibebankan ke hasil produksi dengan tarif yang telah ditentukan.',
            self::MaterialVariance => 'Menampung selisih antara perkiraan sisa bahan dan bahan yang benar-benar dikembalikan.',
            self::ProductionLoss => 'Menampung kekurangan antara hasil produksi yang dicatat dan yang benar-benar diterima gudang.',
            self::Cogs => 'Didebit sebesar nilai persediaan barang jadi yang keluar saat pengiriman penjualan.',
        };
    }
}
