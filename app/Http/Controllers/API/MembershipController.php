<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
/**
 * Master CRUD for Memberships.
 *
 * A "Membership" is just a row in loyalty_program (LoyaltyProgram model) —
 * there is no separate membership table. program_name and membership_title
 * were duplicating the same value, so this controller only ever reads/writes
 * program_name; membership_title is left untouched.
 */
class MembershipController extends Controller
{
    public $APP_URL;

    public function __construct()
    {
        $this->APP_URL = env('APP_URL');
    }

    public function membershipList(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $perPage = (isset($request->per_page) && $request->per_page > 0) ? $request->per_page : 25;
                $post['keyword'] = isset($request->keyword) ? $request->keyword : null;
                $post['is_active'] = isset($request->is_active) ? $request->is_active : null;
                $user = $request->user();
                $result = LoyaltyProgram::getMembershipList($perPage, $post);
                if ($user && $result) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => 'Success',
                        'data' => $result,
                    ]);
                }
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => 'Oops something went wrong',
                ]);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }

    public function membershipDetails(Request $request, $program_id)
    {
        try {
            $user = $request->user();
            $result = LoyaltyProgram::getMembershipDetails($program_id);
            if ($user && $result) {
                return response()->json([
                    'status' => [
                        'success' => true,
                        'httpStatus' => 200,
                    ],
                    'message' => 'Success',
                    'data' => $result,
                ]);
            }
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'message' => 'Membership not found',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }

    public function addMembership(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $user = $request->user();

                $program_id = (isset($request->program_id) && $request->program_id > 0)
                ? $request->program_id
                : 0;

                $validator = Validator::make($request->all(), [
                    'program_name' => [
                        'required','string','max:100',
                        Rule::unique('loyalty_program', 'program_name')->ignore($program_id, 'program_id'),
                    ],
                    'description' => 'nullable|string',
                    'terms_conditions' => 'nullable|string',
                    'is_active' => 'nullable|boolean',
                    'services' => 'nullable|array',
                    'services.*' => 'nullable|string|max:191',
                    'card_tagline' => 'nullable|string|max:191',
                    'membership_type' => 'nullable|in:free,paid',
                    'membership_amount' => 'nullable|required_if:membership_type,paid|numeric|min:0',
                    'invitation_required' => 'nullable|boolean',
                    'welcome_coin' => 'nullable|integer',
                    'membership_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
                ]);

                if ($validator->fails()) {
                    $errors = json_encode($validator->messages());
                    $errorArray = [];
                    if (json_decode($errors, 1)) {
                        foreach (json_decode($errors, 1) as $err) {
                            foreach ($err as $errs) {
                                $errorArray[] = ($errs);
                            }
                        }
                    }
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => implode(',', $errorArray),
                        'error' => $validator->messages(),
                    ]);
                }

                $cleanServices = array_values(array_filter($request->services ?? [], function ($s) {
                    return trim((string) $s) !== '';
                }));

                if (empty($cleanServices)) {
                    return response()->json([
                        'status' => false,
                        'httpStatus' => 201,
                        'message' => 'Select at least one service',
                        'error' => ['services' => ['At least one service is required.']],
                    ]);
                }

                $program_id = (isset($request->program_id) && $request->program_id > 0) ? $request->program_id : 0;

                $oldLogoUrl = null;
                if ($program_id > 0 && ($request->hasFile('membership_logo') || $request->boolean('remove_logo'))) {
                    $oldLogoUrl = LoyaltyProgram::where('program_id', $program_id)->value('membership_logo');
                }

                $CreateData = [
                    'program_name' => $request->program_name,
                    'description' => $request->description,
                    'terms_conditions' => $request->terms_conditions,
                    'is_active' => $request->boolean('is_active', true),
                    'services' => json_encode($cleanServices),
                    'card_tagline' => $request->card_tagline,
                    'membership_type' => $request->membership_type ?? 'free',
                    'membership_amount' => ($request->membership_type ?? 'free') === 'paid' ? $request->membership_amount : null,
                    'invitation_required' => $request->boolean('invitation_required'),
                    'welcome_coin' => $request->welcome_coin
                ];

                // Only touch membership_logo when a new file is uploaded or an explicit
                // removal was requested — otherwise editing a membership leaves the logo alone.
                if ($request->hasFile('membership_logo')) {
                    $uploadDir = public_path('uploads/membership');
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $image = $request->file('membership_logo');
                    $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                    $image->move($uploadDir, $imageName);
                    $CreateData['membership_logo'] = $this->APP_URL . '/uploads/membership/' . $imageName;
                } elseif ($request->boolean('remove_logo')) {
                    $CreateData['membership_logo'] = null;
                }


                if ($program_id > 0) {
                    $CreateData['updated_at'] = date('Y-m-d H:i:s');
                    LoyaltyProgram::where('program_id', $program_id)->update($CreateData);
                    $insertGetId = $program_id;
                    $message = 'Membership updated successfully!';
                } else {
                    $CreateData['created_at'] = date('Y-m-d H:i:s');
                    $CreateData['updated_at'] = date('Y-m-d H:i:s');
                    $insertGetId = LoyaltyProgram::insertGetId($CreateData);
                    $message = 'Membership created successfully!';
                }

                if ($oldLogoUrl) {
                    $oldRelative = str_replace(rtrim($this->APP_URL, '/') . '/', '', $oldLogoUrl);
                    $oldFullPath = public_path($oldRelative);
                    if (str_starts_with($oldRelative, 'uploads/membership/') && file_exists($oldFullPath)) {
                        @unlink($oldFullPath);
                    }
                }

                if ($user && $insertGetId) {
                    return response()->json([
                        'status' => [
                            'success' => true,
                            'httpStatus' => 200,
                        ],
                        'message' => $message,
                        'data' => $insertGetId,
                    ]);
                }
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 500,
                    ],
                    'message' => 'Oops something went wrong',
                ]);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }

    public function toggleMembershipStatus(Request $request, $program_id)
    {
        try {
            $user = $request->user();
            $membership = LoyaltyProgram::where('program_id', $program_id)->first();
            if (!$membership) {
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 404,
                    ],
                    'message' => 'Membership not found',
                ]);
            }

            LoyaltyProgram::where('program_id', $program_id)->update([
                'is_active' => !$membership->is_active,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Membership status updated',
                'data' => ['is_active' => !$membership->is_active],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 500,
                ],
                'message' => $th->getMessage(),
            ]);
        }
    }
}