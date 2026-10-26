<?php

namespace App\Enums;

enum AccountSystemRole: string
{
    case InventoryRaw = 'inventory_raw';
    case InventoryWip = 'inventory_wip';
    case InventoryFinished = 'inventory_finished';
    case InventorySemiFinished = 'inventory_semi_finished';
    case GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';
    case WagesPayable = 'wages_payable';
    case OverheadApplied = 'overhead_applied';
    case MaterialVariance = 'material_variance';
    case ProductionLoss = 'production_loss';
    case Cogs = 'cogs';
    case AccountsPayable = 'accounts_payable';
    case VatIn = 'vat_in';
    case PurchasePriceVariance = 'purchase_price_variance';
    case OverheadElectricity = 'overhead_electricity';
    case OverheadMachineDepreciation = 'overhead_machine_depreciation';
    case OverheadMoldDepreciation = 'overhead_mold_depreciation';
    case OverheadMaintenance = 'overhead_maintenance';
    case OverheadOther = 'overhead_other';
    case AccountsReceivable = 'accounts_receivable';
    case SalesRevenue = 'sales_revenue';
    case VatOut = 'vat_out';

    public function label(): string
    {
        return match ($this) {
            self::InventoryRaw => 'Persediaan Bahan Baku',
            self::InventoryWip => 'Persediaan Barang Dalam Proses',
            self::InventoryFinished => 'Persediaan Barang Jadi',
            self::InventorySemiFinished => 'Persediaan Barang Setengah Jadi',
            self::GoodsReceivedNotInvoiced => 'Barang Diterima Belum Difakturkan',
            self::WagesPayable => 'Utang Gaji',
            self::OverheadApplied => 'BOP Dibebankan',
            self::MaterialVariance => 'Selisih Bahan',
            self::ProductionLoss => 'Kerugian Produksi',
            self::Cogs => 'Harga Pokok Penjualan',
            self::AccountsPayable => 'Utang Usaha',
            self::VatIn => 'PPN Masukan',
            self::PurchasePriceVariance => 'Selisih Harga Pembelian',
            self::OverheadElectricity => 'BOP Listrik',
            self::OverheadMachineDepreciation => 'BOP Penyusutan Mesin',
            self::OverheadMoldDepreciation => 'BOP Penyusutan Molding',
            self::OverheadMaintenance => 'BOP Pemeliharaan',
            self::OverheadOther => 'BOP Lain-lain',
            self::AccountsReceivable => 'Piutang Usaha',
            self::SalesRevenue => 'Penjualan',
            self::VatOut => 'PPN Keluaran',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::InventoryRaw => 'Didebit saat menerima barang dari pemasok, dikredit saat bahan dikeluarkan ke produksi.',
            self::InventoryWip => 'Menampung biaya bahan, tenaga kerja, dan overhead selama barang masih dalam proses produksi.',
            self::InventoryFinished => 'Didebit saat produk jadi diterima dari produksi, dikredit saat dijual.',
            self::InventorySemiFinished => 'Barang setengah jadi di gudang (komponen WIP): didebit saat diterima dari produksi, dikredit saat dikeluarkan ke produksi perakitan.',
            self::GoodsReceivedNotInvoiced => 'Menampung nilai barang yang sudah diterima tetapi fakturnya belum dicatat.',
            self::WagesPayable => 'Dikredit sebesar biaya tenaga kerja yang dibebankan ke hasil produksi.',
            self::OverheadApplied => 'Dikredit sebesar BOP yang dibebankan ke hasil produksi dengan tarif yang telah ditentukan.',
            self::MaterialVariance => 'Menampung selisih antara perkiraan sisa bahan dan bahan yang benar-benar dikembalikan.',
            self::ProductionLoss => 'Menampung kekurangan antara hasil produksi yang dicatat dan yang benar-benar diterima gudang.',
            self::Cogs => 'Didebit sebesar nilai persediaan barang jadi yang keluar saat pengiriman penjualan.',
            self::AccountsPayable => 'Dikredit sebesar total faktur pembelian, didebit saat pemasok dibayar.',
            self::VatIn => 'Didebit sebesar PPN dari faktur pajak pemasok; dapat dikreditkan, tidak masuk biaya persediaan.',
            self::PurchasePriceVariance => 'Menampung selisih antara harga di faktur pemasok dan nilai barang saat diterima.',
            self::OverheadElectricity => 'BOP sesungguhnya: tagihan listrik pabrik. Ditutup setiap akhir bulan.',
            self::OverheadMachineDepreciation => 'BOP sesungguhnya: penyusutan mesin per bulan. Ditutup setiap akhir bulan.',
            self::OverheadMoldDepreciation => 'BOP sesungguhnya: penyusutan molding per bulan. Ditutup setiap akhir bulan.',
            self::OverheadMaintenance => 'BOP sesungguhnya: suku cadang dan jasa teknisi. Ditutup setiap akhir bulan.',
            self::OverheadOther => 'BOP sesungguhnya: biaya overhead pabrik lainnya. Ditutup setiap akhir bulan.',
            self::AccountsReceivable => 'Didebit sebesar total faktur penjualan, dikredit saat pelanggan membayar.',
            self::SalesRevenue => 'Dikredit sebesar DPP faktur penjualan (barang setelah diskon ditambah biaya lain).',
            self::VatOut => 'Dikredit sebesar PPN dari faktur penjualan; utang pajak yang dikurangi PPN Masukan setiap bulan.',
        };
    }
}
