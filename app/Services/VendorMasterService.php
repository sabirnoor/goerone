<?php

namespace App\Services;

use App\Models\Store;
use App\Models\VendorCategory;
use App\Models\VendorType;
use Illuminate\Http\Exceptions\HttpResponseException;

class VendorMasterService
{
    /**
     * Validate a category/type pair coming from a form.
     *
     * - Inactive entries cannot be newly selected, but a store that already uses one keeps it.
     * - The type must belong to the category.
     * - If the category has active types, one must be chosen.
     * - No category sent => nothing to validate, no fields returned (old behaviour untouched).
     *
     * @return array{error: ?string, fields: array}
     */
    public static function resolve($categoryId, $typeId, ?Store $current = null): array
    {
        if (empty($categoryId)) {
            return ['error' => null, 'fields' => []];
        }

        $cat = VendorCategory::find($categoryId);
        if (!$cat) {
            return ['error' => 'Selected category does not exist.', 'fields' => []];
        }
        $keepCat = $current && (int) $current->vendor_category_id === (int) $cat->id;
        if (!$cat->is_active && !$keepCat) {
            return ['error' => 'Selected category is inactive.', 'fields' => []];
        }

        $type = null;
        if (!empty($typeId)) {
            $type = VendorType::find($typeId);
            if (!$type || (int) $type->vendor_category_id !== (int) $cat->id) {
                return ['error' => 'The selected vendor type does not belong to the selected category.', 'fields' => []];
            }
            $keepType = $current && (int) $current->vendor_type_id === (int) $type->id;
            if (!$type->is_active && !$keepType) {
                return ['error' => 'Selected vendor type is inactive.', 'fields' => []];
            }
        } elseif (VendorType::where('vendor_category_id', $cat->id)->active()->exists()) {
            return ['error' => 'Please select a vendor type.', 'fields' => []];
        }

        return [
            'error' => null,
            'fields' => [
                'vendor_category_id' => $cat->id,
                'vendor_type_id' => $type?->id,
                // names are kept in the old text columns so existing filters / reports keep working
                'vendor_category' => $cat->name,
                'type' => $type?->name ?? '',
            ],
        ];
    }

    /** Same as resolve() but throws a JSON error response (used where the caller cannot "return"). */
    public static function resolveOrFail($categoryId, $typeId, ?Store $current = null, int $http = 422): array
    {
        $r = self::resolve($categoryId, $typeId, $current);
        if ($r['error']) {
            throw new HttpResponseException(response()->json([
                'status' => ['success' => false, 'httpStatus' => $http],
                'message' => $r['error'],
            ], $http));
        }
        return $r['fields'];
    }
}