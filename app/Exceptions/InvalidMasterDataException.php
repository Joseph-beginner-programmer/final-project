<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidMasterDataException extends RuntimeException
{
    public const PRODUCT_LOCKED = 'product_locked';
    public const SELLING_PRICE_NOT_FG = 'selling_price_not_fg';
    public const FORMULA_PRODUCT_TYPE = 'formula_product_type';
    public const FORMULA_EXISTS = 'formula_exists';
    public const FORMULA_NO_MATERIALS = 'formula_no_materials';
    public const FORMULA_MATERIAL_TYPE = 'formula_material_type';
    public const FORMULA_SELF = 'formula_self';
    public const CATALOG_NOT_RAW = 'catalog_not_raw';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid master data [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::PRODUCT_LOCKED => __(':name already has stock or documents, so its type and unit can no longer change.', ['name' => $this->detail]),
            self::SELLING_PRICE_NOT_FG => __('Only finished goods have a selling price.'),
            self::FORMULA_PRODUCT_TYPE => __('A formula can only produce WIP or finished goods.'),
            self::FORMULA_EXISTS => __(':name already has a formula. Edit that one instead.', ['name' => $this->detail]),
            self::FORMULA_NO_MATERIALS => __('Add at least one material to the formula.'),
            self::FORMULA_MATERIAL_TYPE => __(':name cannot be a material — only raw materials and WIP can.', ['name' => $this->detail]),
            self::FORMULA_SELF => __('A product cannot be a material of its own formula.'),
            self::CATALOG_NOT_RAW => __(':name is not a raw material, so it cannot be bought from a supplier.', ['name' => $this->detail]),
            default => __('Invalid data.'),
        };
    }
}
