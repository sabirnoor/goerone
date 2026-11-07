<?php

namespace App\Http\Controllers;

use App\Models\LegalPolicy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LegalPolicyController extends Controller
{
    /** Agency owner (1) and agency staff (4) can manage policies. */
    private function canManage($user): bool
    {
        return $user && in_array($user->UserType ?? null, [1, 4]);
    }

    private function agencyIdOf($user)
    {
        return ($user->UserType == 1) ? $user->id : $user->AgencyID;
    }

    private function respond(bool $success, int $code, string $message, $data = null)
    {
        return response()->json([
            'status'  => ['success' => $success, 'httpStatus' => $code],
            'message' => $message,
            'data'    => $data,
        ], $success ? 200 : $code);
    }

    private function present(?LegalPolicy $p)
    {
        if (!$p) {
            return null;
        }
        return [
            'id'         => $p->id,
            'type'       => $p->type,
            'title'      => $p->title,
            'content'    => $p->content,
            'version'    => $p->version,
            'is_active'  => $p->is_active,
            'updated_at' => optional($p->updated_at)->toDateTimeString(),
            'created_at' => optional($p->created_at)->toDateTimeString(),
        ];
    }

    /**
     * ADMIN  POST /legal/policy   { type }
     * Returns the saved policy for the logged-in user's agency (data = null when not created yet).
     */
    public function fetch(Request $request)
    {
        try {
            $user = $request->user();
            if (!$this->canManage($user)) {
                return $this->respond(false, 403, 'You are not allowed to manage policies.');
            }

            $validator = Validator::make($request->all(), [
                'type' => ['required', Rule::in(array_keys(LegalPolicy::TYPES))],
            ]);
            if ($validator->fails()) {
                return $this->respond(false, 422, $validator->errors()->first());
            }

            $policy = LegalPolicy::where('AgencyID', $this->agencyIdOf($user))
                ->where('type', $request->type)
                ->first();

            return $this->respond(true, 200, 'Success', $this->present($policy));
        } catch (\Throwable $th) {
            return $this->respond(false, 500, $th->getMessage());
        }
    }

    /**
     * ADMIN  POST /legal/save   { type, title?, content, is_active? }
     * Creates the policy the first time, updates it afterwards (version +1).
     */
    public function save(Request $request)
    {
        try {
            $user = $request->user();
            if (!$this->canManage($user)) {
                return $this->respond(false, 403, 'You are not allowed to manage policies.');
            }

            $validator = Validator::make($request->all(), [
                'type'      => ['required', Rule::in(array_keys(LegalPolicy::TYPES))],
                'title'     => 'nullable|string|max:255',
                'content'   => 'required|string|max:500000',
                'is_active' => 'nullable|boolean',
            ]);
            if ($validator->fails()) {
                return $this->respond(false, 422, $validator->errors()->first());
            }

            $content = $this->sanitizeHtml($request->content);
            if (trim(strip_tags($content)) === '') {
                return $this->respond(false, 422, 'Content cannot be empty.');
            }

            $agencyId = $this->agencyIdOf($user);
            $title    = $request->filled('title') ? trim($request->title) : LegalPolicy::TYPES[$request->type];

            $policy = LegalPolicy::where('AgencyID', $agencyId)->where('type', $request->type)->first();

            if ($policy) {
                $policy->update([
                    'title'      => $title,
                    'content'    => $content,
                    'is_active'  => $request->has('is_active') ? (bool) $request->is_active : $policy->is_active,
                    'updated_by' => $user->id,
                    'version'    => $policy->version + 1,
                ]);
                $message = LegalPolicy::TYPES[$request->type] . ' updated successfully.';
            } else {
                $policy = LegalPolicy::create([
                    'AgencyID'   => $agencyId,
                    'UserSysId'  => $user->id,
                    'updated_by' => $user->id,
                    'type'       => $request->type,
                    'title'      => $title,
                    'content'    => $content,
                    'version'    => 1,
                    'is_active'  => $request->has('is_active') ? (bool) $request->is_active : true,
                ]);
                $message = LegalPolicy::TYPES[$request->type] . ' saved successfully.';
            }

            return $this->respond(true, 200, $message, $this->present($policy->fresh()));
        } catch (\Throwable $th) {
            return $this->respond(false, 500, $th->getMessage());
        }
    }

    /**
     * PUBLIC (customers, API-key based like /store/search)   GET /legal/public/{type}
     * Agency is taken from the API key, only active policies are returned.
     */
    public function publicShow(Request $request, $type)
    {
        try {
            if (!array_key_exists($type, LegalPolicy::TYPES)) {
                return $this->respond(false, 404, 'Page not found.');
            }

            $token = trim((string) $request->bearerToken());
            $key   = $token ? User::where('api_token', hash('sha256', $token))->first() : null;
            if (!$key) {
                return response()->json([
                    'status' => false, 'message' => 'Unauthenticated', 'httpStatus' => 401,
                    'details' => 'Access denied: Invalid or unauthorized API key',
                ], 401);
            }

            $policy = LegalPolicy::where('AgencyID', $this->agencyIdOf($key))
                ->where('type', $type)
                ->where('is_active', 1)
                ->first();

            if (!$policy) {
                return $this->respond(false, 404, LegalPolicy::TYPES[$type] . ' is not available yet.');
            }

            return $this->respond(true, 200, 'Success', [
                'type'       => $policy->type,
                'title'      => $policy->title,
                'content'    => $policy->content,
                'version'    => $policy->version,
                'updated_at' => optional($policy->updated_at)->toDateTimeString(),
            ]);
        } catch (\Throwable $th) {
            return $this->respond(false, 500, $th->getMessage());
        }
    }

    /**
     * The content is shown to customers with dangerouslySetInnerHTML, so keep only safe
     * formatting tags and drop every attribute except a safe href on links.
     */
    private function sanitizeHtml(string $html): string
    {
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li',
            'a', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div'];

        $html = strip_tags($html, '<' . implode('><', $allowed) . '>');

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="legal-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('legal-root');
        if (!$root) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('.//*', $root) as $node) {
            $isLink = strtolower($node->nodeName) === 'a';
            $href   = $isLink ? trim($node->getAttribute('href')) : '';

            foreach (iterator_to_array($node->attributes) as $attr) {
                $node->removeAttributeNode($attr);
            }

            if ($isLink && preg_match('/^(https?:\/\/|mailto:|tel:|#)/i', $href)) {
                $node->setAttribute('href', $href);
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }
}