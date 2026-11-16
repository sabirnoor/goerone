<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VendorCategory;
use App\Models\VendorType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Vendor Category / Vendor Type master.
 *
 * Routes (inside auth:sanctum):
 *   Route::post('/vendor-master/options',        [VendorMasterController::class, 'options']);        // any logged-in user / store
 *   Route::post('/vendor-master',                 [VendorMasterController::class, 'index']);          // admin
 *   Route::post('/vendor-master/category/save',   [VendorMasterController::class, 'saveCategory']);   // admin
 *   Route::post('/vendor-master/type/save',       [VendorMasterController::class, 'saveType']);       // admin
 *   Route::post('/vendor-master/status',          [VendorMasterController::class, 'setStatus']);      // admin
 *   Route::post('/vendor-master/category/delete', [VendorMasterController::class, 'deleteCategory']); // admin
 *   Route::post('/vendor-master/type/delete',     [VendorMasterController::class, 'deleteType']);     // admin
 */
class VendorMasterController extends Controller
{
    /**
     * Dropdown data: active categories with their active types.
     * Optional include_category_id / include_type_id keep a store's current (now inactive) value
     * in the list so the edit form can still show it.
     */
    public function options(Request $request)
    {
        $incCat = (int) $request->input('include_category_id', 0);
        $incType = (int) $request->input('include_type_id', 0);

        $categories = VendorCategory::query()
            ->where(function ($q) use ($incCat) {
                $q->where('is_active', 1);
                if ($incCat) {
                    $q->orWhere('id', $incCat);
                }
            })
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $types = VendorType::query()
            ->whereIn('vendor_category_id', $categories->pluck('id'))
            ->where(function ($q) use ($incType) {
                $q->where('is_active', 1);
                if ($incType) {
                    $q->orWhere('id', $incType);
                }
            })
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->groupBy('vendor_category_id');

        $result = $categories->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'is_food' => $c->is_food,
            'is_active' => $c->is_active,
            'types' => ($types[$c->id] ?? collect())->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_active' => $t->is_active,
            ])->values(),
        ])->values();

        return $this->ok('Success', $result);
    }

    /** Admin: everything (active + inactive) with store usage counts. */
    public function index(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $catCounts = DB::table('stores')->whereNotNull('vendor_category_id')
            ->select('vendor_category_id', DB::raw('COUNT(*) c'))->groupBy('vendor_category_id')
            ->pluck('c', 'vendor_category_id');
        $typeCounts = DB::table('stores')->whereNotNull('vendor_type_id')
            ->select('vendor_type_id', DB::raw('COUNT(*) c'))->groupBy('vendor_type_id')
            ->pluck('c', 'vendor_type_id');

        $list = VendorCategory::with('types')->orderBy('sort_order')->orderBy('name')->get()
            ->map(function ($c) use ($catCounts, $typeCounts) {
                $arr = $c->toArray();
                $arr['stores_count'] = (int) ($catCounts[$c->id] ?? 0);
                $arr['types'] = $c->types->map(function ($t) use ($typeCounts) {
                    $a = $t->toArray();
                    $a['stores_count'] = (int) ($typeCounts[$t->id] ?? 0);
                    return $a;
                })->values();
                return $arr;
            })->values();

        return $this->ok('Success', $list);
    }

    public function saveCategory(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $v = Validator::make($request->all(), [
            'id' => 'nullable|integer|exists:vendor_categories,id',
            'name' => ['required', 'string', 'max:120', Rule::unique('vendor_categories', 'name')->ignore($request->id)],
            'is_food' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails()) return $this->fail($v->errors()->first(), 422);

        $data = ['name' => trim($request->name)];
        if ($request->has('is_food')) $data['is_food'] = $request->boolean('is_food');
        if ($request->has('sort_order')) $data['sort_order'] = (int) $request->sort_order;
        if ($request->has('is_active')) $data['is_active'] = $request->boolean('is_active');

        if ($request->filled('id')) {
            $cat = VendorCategory::find($request->id);
            $cat->update($data);
            // keep the denormalised name on stores in sync after a rename
            DB::table('stores')->where('vendor_category_id', $cat->id)->update(['vendor_category' => $cat->name]);
        } else {
            $cat = VendorCategory::create($data + ['sort_order' => (int) VendorCategory::max('sort_order') + 1]);
        }

        return $this->ok('Category saved.', $cat);
    }

    public function saveType(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $v = Validator::make($request->all(), [
            'id' => 'nullable|integer|exists:vendor_types,id',
            'vendor_category_id' => 'required|integer|exists:vendor_categories,id',
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('vendor_types', 'name')
                    ->where('vendor_category_id', $request->vendor_category_id)
                    ->ignore($request->id),
            ],
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails()) return $this->fail($v->errors()->first(), 422);

        $data = ['name' => trim($request->name)];
        if ($request->has('sort_order')) $data['sort_order'] = (int) $request->sort_order;
        if ($request->has('is_active')) $data['is_active'] = $request->boolean('is_active');

        if ($request->filled('id')) {
            $type = VendorType::find($request->id);
            // a type cannot be moved to another category while stores use it
            if ((int) $type->vendor_category_id !== (int) $request->vendor_category_id
                && DB::table('stores')->where('vendor_type_id', $type->id)->exists()) {
                return $this->fail('This type is used by stores, so it cannot be moved to another category.', 409);
            }
            $type->update($data + ['vendor_category_id' => (int) $request->vendor_category_id]);
            DB::table('stores')->where('vendor_type_id', $type->id)->update(['type' => $type->name]);
        } else {
            $type = VendorType::create($data + [
                'vendor_category_id' => (int) $request->vendor_category_id,
                'sort_order' => (int) VendorType::where('vendor_category_id', $request->vendor_category_id)->max('sort_order') + 1,
            ]);
        }

        return $this->ok('Vendor type saved.', $type);
    }

    /** Active / inactive switch for a category or a type. */
    public function setStatus(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $v = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(['category', 'type'])],
            'id' => 'required|integer',
            'is_active' => 'required|boolean',
        ]);
        if ($v->fails()) return $this->fail($v->errors()->first(), 422);

        $model = $request->kind === 'category' ? VendorCategory::find($request->id) : VendorType::find($request->id);
        if (!$model) return $this->fail('Not found.', 404);

        $model->update(['is_active' => $request->boolean('is_active')]);

        return $this->ok($model->is_active ? 'Activated.' : 'Deactivated.', $model);
    }

    public function deleteCategory(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $v = Validator::make($request->all(), ['id' => 'required|integer|exists:vendor_categories,id']);
        if ($v->fails()) return $this->fail($v->errors()->first(), 422);

        if (DB::table('stores')->where('vendor_category_id', $request->id)->exists()) {
            return $this->fail('Stores are using this category. Deactivate it instead of deleting.', 409);
        }
        if (VendorType::where('vendor_category_id', $request->id)->exists()) {
            return $this->fail('Delete or move its vendor types first.', 409);
        }

        VendorCategory::where('id', $request->id)->delete();
        return $this->ok('Category deleted.', null);
    }

    public function deleteType(Request $request)
    {
        if ($r = $this->adminOrFail($request)) return $r;

        $v = Validator::make($request->all(), ['id' => 'required|integer|exists:vendor_types,id']);
        if ($v->fails()) return $this->fail($v->errors()->first(), 422);

        if (DB::table('stores')->where('vendor_type_id', $request->id)->exists()) {
            return $this->fail('Stores are using this vendor type. Deactivate it instead of deleting.', 409);
        }

        VendorType::where('id', $request->id)->delete();
        return $this->ok('Vendor type deleted.', null);
    }

    // ------------------------------------------------------------------------------------------

    /** Same rule as the other admin endpoints: agency admin only (UserType 1). */
    private function adminOrFail(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof User || $user->UserType != 1) {
            return $this->fail('Only an admin can manage the vendor master.', 403);
        }
        return null;
    }

    private function ok(string $message, $result)
    {
        return response()->json([
            'status' => ['success' => true, 'httpStatus' => 200],
            'message' => $message,
            'result' => $result,
        ]);
    }

    private function fail(string $message, int $http)
    {
        return response()->json([
            'status' => ['success' => false, 'httpStatus' => $http],
            'message' => $message,
        ], $http);
    }
}