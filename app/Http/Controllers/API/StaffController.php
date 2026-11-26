<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * GoerOne staff master.
 *
 * Staff live in the existing `users` table: UserType = 4 (Agency Staff) and GoerOne = 1.
 * Admin only (UserType 1); every query is scoped to the logged-in admin's AgencyID.
 * Staff are never hard-deleted (they can be referenced as a store's Relationship Manager),
 * they are switched Active / Inactive instead.
 */
class StaffController extends Controller
{
    private const USER_TYPE_STAFF = 4;
    private const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr'];

    // Columns that are safe to send to the browser (never password / tokens).
    private const LIST_COLUMNS = [
        'users.id', 'users.name', 'users.fname', 'users.lname', 'users.title',
        'users.email', 'users.mobile', 'users.countrycode', 'users.active', 'users.created_at',
    ];

    private function deny()
    {
        return response()->json([
            'status' => ['success' => false, 'httpStatus' => 403],
            'message' => 'Only an admin can manage GoerOne staff.',
        ], 403);
    }

    private function staffQuery($admin)
    {
        return User::query()
            ->where('users.UserType', self::USER_TYPE_STAFF)
            ->where('users.GoerOne', 1)
            ->where('users.AgencyID', $admin->id);
    }

    private function fail(string $message, $errors = null, int $http = 2001)
    {
        return response()->json([
            'status' => ['success' => false, 'httpStatus' => $http],
            'message' => $message,
            'error' => $errors,
        ]);
    }

    /** POST /goerone-staff?per_page=&page=   body: { keyword, status: all|active|inactive } */
    public function index(Request $request)
    {
        try {
            $admin = $request->user();
            if (!$admin || $admin->UserType != 1) {
                return $this->deny();
            }

            $keyword = trim((string) $request->input('keyword', ''));
            $status = $request->input('status', 'all');
            $perPage = ($request->per_page > 0 && $request->per_page <= 100) ? (int) $request->per_page : 25;

            $result = $this->staffQuery($admin)
                ->select(self::LIST_COLUMNS)
                ->when($keyword !== '' && $keyword !== 'null', function ($q) use ($keyword) {
                    $q->where(function ($w) use ($keyword) {
                        $w->where('users.name', 'like', "%{$keyword}%")
                            ->orWhere('users.email', 'like', "%{$keyword}%")
                            ->orWhere('users.mobile', 'like', "%{$keyword}%");
                    });
                })
                ->when($status === 'active', fn ($q) => $q->where('users.active', 1))
                ->when($status === 'inactive', fn ($q) => $q->where('users.active', '!=', 1))
                ->orderBy('users.created_at', 'desc')
                ->paginate($perPage);

            // Tab badges, not affected by the keyword / tab filter.
            $counts = $this->staffQuery($admin)
                ->select('users.active', DB::raw('COUNT(*) as total'))
                ->groupBy('users.active')
                ->pluck('total', 'active');
            $active = (int) ($counts[1] ?? 0);
            $all = (int) $counts->sum();

            return response()->json([
                'status' => ['success' => true, 'httpStatus' => 200],
                'message' => 'Success',
                'data' => $result,
                'status_counts' => ['all' => $all, 'active' => $active, 'inactive' => $all - $active],
            ]);
        } catch (\Throwable $th) {
            return $this->fail($th->getMessage(), null, 500);
        }
    }

    /** POST /goerone-staff/save   body: { id?, title, name, email, mobile, password?, active } */
    public function save(Request $request)
    {
        try {
            $admin = $request->user();
            if (!$admin || $admin->UserType != 1) {
                return $this->deny();
            }

            $id = $request->filled('id') ? (int) $request->id : null;
            $existing = null;
            if ($id) {
                $existing = $this->staffQuery($admin)->where('users.id', $id)->first();
                if (!$existing) {
                    return response()->json([
                        'status' => ['success' => false, 'httpStatus' => 404],
                        'message' => 'Staff member not found.',
                    ], 404);
                }
            }

            if ($request->filled('email')) {
                $request->merge(['email' => strtolower(trim($request->email))]);
            }

            $validator = Validator::make($request->all(), [
                'title' => ['required', Rule::in(self::TITLES)],
                'name' => ['required', 'string', 'max:255'],
                // email is the login id, so it must be unique across all users
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                'mobile' => [
                    'required', 'digits_between:9,12',
                    Rule::unique('users', 'mobile')
                        ->where(fn ($q) => $q->where('UserType', self::USER_TYPE_STAFF)->where('AgencyID', $admin->id))
                        ->ignore($id),
                ],
                'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
                'active' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->fail(implode(', ', $validator->errors()->all()), $validator->messages());
            }

            $name = trim(preg_replace('/\s+/', ' ', $request->name));
            $parts = explode(' ', $name, 2);

            $data = [
                'title' => $request->title,
                'name' => $name,
                'fname' => $parts[0],
                'lname' => $parts[1] ?? null,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'active' => $request->boolean('active') ? 1 : 0,
                'updated_at' => now(),
            ];
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            if ($existing) {
                // query builder update: only the columns above are touched
                $this->staffQuery($admin)->where('users.id', $id)->update($data);
                $message = 'Staff updated successfully!';
            } else {
                $id = User::query()->insertGetId($data + [
                    'UserType' => self::USER_TYPE_STAFF,
                    'GoerOne' => 1,
                    'AgencyID' => $admin->id,
                    'UserSysId' => $admin->id,
                    'countrycode' => $request->input('country_code', 91),
                    'created_at' => now(),
                ]);
                $message = 'Staff added successfully!';
            }

            $staff = $this->staffQuery($admin)->select(self::LIST_COLUMNS)->where('users.id', $id)->first();

            return response()->json([
                'status' => ['success' => true, 'httpStatus' => 200],
                'message' => $message,
                'result' => $staff,
            ]);
        } catch (\Throwable $th) {
            return $this->fail($th->getMessage(), null, 500);
        }
    }

    /** POST /goerone-staff/status   body: { id, active } */
    public function setStatus(Request $request)
    {
        try {
            $admin = $request->user();
            if (!$admin || $admin->UserType != 1) {
                return $this->deny();
            }

            $validator = Validator::make($request->all(), [
                'id' => ['required', 'integer'],
                'active' => ['required', 'boolean'],
            ]);
            if ($validator->fails()) {
                return $this->fail(implode(', ', $validator->errors()->all()), $validator->messages());
            }

            $staff = $this->staffQuery($admin)->where('users.id', (int) $request->id)->first();
            if (!$staff) {
                return response()->json([
                    'status' => ['success' => false, 'httpStatus' => 404],
                    'message' => 'Staff member not found.',
                ], 404);
            }

            $active = $request->boolean('active') ? 1 : 0;
            $this->staffQuery($admin)->where('users.id', $staff->id)->update(['active' => $active, 'updated_at' => now()]);

            return response()->json([
                'status' => ['success' => true, 'httpStatus' => 200],
                'message' => $active ? 'Staff activated.' : 'Staff deactivated.',
                'result' => $this->staffQuery($admin)->select(self::LIST_COLUMNS)->where('users.id', $staff->id)->first(),
            ]);
        } catch (\Throwable $th) {
            return $this->fail($th->getMessage(), null, 500);
        }
    }

}