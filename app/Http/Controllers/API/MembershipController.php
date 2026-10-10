<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgram;
use App\Models\MembershipInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    
    // ------------------------------------------------------------------
    // Invite-only memberships: "Request consideration"
    // ------------------------------------------------------------------
 
    private function inviteError($message, $httpStatus = 422, $errors = null)
    {
        $body = [
            'status' => [
                'success' => false,
                'httpStatus' => $httpStatus,
            ],
            'message' => $message,
        ];
        if ($errors) {
            $body['error'] = $errors;
        }
        return response()->json($body);
    }
 
    /**
     * Customer: apply to be considered for an invite-only membership.
     * POST { program_id, pan_number, pan_verified, has_criminal_record, social_media: {instagram, facebook, linkedin, x} }
     */
    public function requestConsideration(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->inviteError('Please log in to continue', 401);
            }
 
            $validator = Validator::make($request->all(), [
                'program_id' => 'required|integer|exists:loyalty_program,program_id',
                'pan_number' => ['required', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
                'pan_verified' => 'required|accepted',
                'consent' => 'required|accepted',
                'has_criminal_record' => 'nullable|boolean',
                'social_media' => 'nullable|array',
                'social_media.instagram' => 'nullable|string|max:191',
                'social_media.facebook' => 'nullable|string|max:191',
                'social_media.linkedin' => 'nullable|string|max:191',
                'social_media.x' => 'nullable|string|max:191',
            ], [
                'pan_number.regex' => 'Enter a valid PAN (e.g. ABCDE1234F).',
                'pan_verified.accepted' => 'Verify your PAN before submitting.',
                'consent.accepted' => 'You need to give your consent to send this request.',
            ]);
 
            if ($validator->fails()) {
                return $this->inviteError(
                    implode(', ', $validator->errors()->all()),
                    422,
                    $validator->messages()
                );
            }
 
            $program = LoyaltyProgram::where('program_id', $request->program_id)->first();
            if (!$program || !$program->is_active) {
                return $this->inviteError('This membership is not available right now', 404);
            }
            if (!$program->invitation_required) {
                return $this->inviteError('This membership does not need an invitation. You can join it directly.');
            }
 
            // Keep only the handles the customer actually filled in
            $social = collect($request->input('social_media', []))
                ->only(['instagram', 'facebook', 'linkedin', 'x'])
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '')
                ->all();
 
            $result = DB::transaction(function () use ($request, $user, $social) {
                // Re-check inside the transaction so a double-click can't create two requests
                $existing = MembershipInvite::where('user_id', $user->id)
                    ->where('program_id', $request->program_id)
                    ->whereIn('status', [MembershipInvite::STATUS_PENDING, MembershipInvite::STATUS_APPROVED])
                    ->lockForUpdate()
                    ->first();
 
                if ($existing) {
                    return ['existing' => $existing];
                }
 
                $invite = MembershipInvite::create([
                    'program_id' => $request->program_id,
                    'user_id' => $user->id,
                    'pan_number' => strtoupper($request->pan_number),
                    'pan_verified' => 1,
                    'consent_given' => 1,
                    'consented_at' => now(),
                    // The form asks "Do you have a criminal record?" (default No); the column stores the opposite
                    'no_criminal_record' => $request->boolean('has_criminal_record') ? 0 : 1,
                    'social_media_details' => empty($social) ? null : $social,
                    'status' => MembershipInvite::STATUS_PENDING,
                ]);
 
                return ['created' => $invite];
            });
 
            if (isset($result['existing'])) {
                $label = $result['existing']->status === MembershipInvite::STATUS_APPROVED
                    ? 'Your request for this membership is already approved.'
                    : 'You have already requested this membership. We will get back to you soon.';
                return response()->json([
                    'status' => [
                        'success' => false,
                        'httpStatus' => 409,
                    ],
                    'message' => $label,
                    'data' => [
                        'already_requested' => true,
                        'invite_status' => $result['existing']->status,
                        'invite_status_label' => $result['existing']->status_label,
                    ],
                ]);
            }
 
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Request submitted. We will review it and get back to you.',
                'data' => [
                    'id' => $result['created']->id,
                    'invite_status' => $result['created']->status,
                ],
            ]);
        } catch (\Throwable $th) {
            return $this->inviteError($th->getMessage(), 500);
        }
    }
 
    /**
     * Customer: has this user already requested this plan? Used to show "Already requested" when the modal opens.
     * GET membership-invite/status/{program_id}
     */
    public function myInviteStatus(Request $request, $program_id)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->inviteError('Please log in to continue', 401);
            }
 
            // Latest request of any status, so a rejection can be shown with its reason
            $invite = MembershipInvite::where('user_id', $user->id)
                ->where('program_id', $program_id)
                ->latest('id')
                ->first();
 
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Success',
                'data' => $invite ? [
                    'requested' => in_array($invite->status, [MembershipInvite::STATUS_PENDING, MembershipInvite::STATUS_APPROVED], true),
                    'invite_status' => $invite->status,
                    'invite_status_label' => $invite->status_label,
                    'rejection_reason' => $invite->rejection_reason,
                    'created_at' => $invite->created_at,
                ] : [
                    'requested' => false,
                    'invite_status' => null,
                ],
            ]);
        } catch (\Throwable $th) {
            return $this->inviteError($th->getMessage(), 500);
        }
    }
 
    /**
     * Admin: all invite requests. POST { status?, program_id?, keyword? (PAN), per_page? }
     */
    public function inviteList(Request $request)
    {
        try {
            $perPage = ($request->per_page > 0) ? (int) $request->per_page : 25;
            $post = [
                'status' => $request->input('status'),
                'program_id' => $request->input('program_id'),
                'keyword' => $request->input('keyword'),
            ];
 
            $result = MembershipInvite::getInviteList($perPage, $post);
 
            // Only the user fields the admin screen needs (columns that don't exist on users are skipped)
            $result->getCollection()->transform(function ($invite) {
                $row = $invite->toArray();
                $row['user'] = $invite->user ? $invite->user->only(['id', 'name', 'email', 'mobile', 'phone']) : null;
                $row['reviewer'] = $invite->reviewer ? $invite->reviewer->only(['id', 'name']) : null;
                return $row;
            });
 
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => 'Success',
                'data' => $result,
            ]);
        } catch (\Throwable $th) {
            return $this->inviteError($th->getMessage(), 500);
        }
    }
 
    /**
     * Admin: approve or reject a pending request. POST { action: approve|reject, rejection_reason? (required on reject) }
     */
    public function reviewInvite(Request $request, $id)
    {
        try {
            $admin = $request->user();
 
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:approve,reject',
                'rejection_reason' => 'nullable|required_if:action,reject|string|max:1000',
            ], [
                'rejection_reason.required_if' => 'Add a reason for rejecting this request.',
            ]);
            if ($validator->fails()) {
                return $this->inviteError(implode(', ', $validator->errors()->all()), 422, $validator->messages());
            }
 
            $invite = MembershipInvite::find($id);
            if (!$invite) {
                return $this->inviteError('Request not found', 404);
            }
            if ($invite->status !== MembershipInvite::STATUS_PENDING) {
                return $this->inviteError('This request has already been reviewed.', 409);
            }
 
            $approve = $request->action === 'approve';
            $invite->status = $approve ? MembershipInvite::STATUS_APPROVED : MembershipInvite::STATUS_REJECTED;
            $invite->rejection_reason = $approve ? null : $request->rejection_reason;
            $invite->reviewed_by = $admin ? $admin->id : null;
            $invite->reviewed_at = now();
            $invite->save();
 
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'message' => $approve ? 'Request approved' : 'Request rejected',
                'data' => [
                    'id' => $invite->id,
                    'invite_status' => $invite->status,
                    'invite_status_label' => $invite->status_label,
                ],
            ]);
        } catch (\Throwable $th) {
            return $this->inviteError($th->getMessage(), 500);
        }
    }
}