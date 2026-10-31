<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/** Shared helpers for the admin member / performance report controllers. */
trait MemberReportSupport
{
    /** UserType values allowed to open admin reports (1=Agency, 3=SuperAdmin, 4=Agency Staff). Adjust to your RBAC. */
    protected function adminUserTypes(): array
    {
        return [1, 3, 4];
    }

    private function guard(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array((int) $user->UserType, $this->adminUserTypes(), true)) {
            return $this->fail('You are not authorised to view this report.', 403);
        }
        return null;
    }

    private function agencyId($user): int
    {
        return (int) ($user->UserType == 1 ? $user->id : $user->AgencyID);
    }

    private function customerName($r): string
    {
        return trim($r->name ?: trim(($r->fname ?? '') . ' ' . ($r->lname ?? '')));
    }

    private function ok($data, string $message = 'Success')
    {
        return response()->json(['status' => ['success' => true, 'httpStatus' => 200], 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code)
    {
        return response()->json(['status' => ['success' => false, 'httpStatus' => $code], 'message' => $message], $code);
    }

    /** Streams rows with a cursor (constant memory). UTF-8 BOM so Excel opens it correctly. */
    private function streamCsv(string $name, array $header, $query, callable $mapRow)
    {
        $filename = $name . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($header, $query, $mapRow) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($query->cursor() as $row) {
                // Neutralise spreadsheet formula injection from user-entered text
                fputcsv($out, array_map(function ($cell) {
                    $cell = (string) $cell;
                    return $cell !== '' && strpos("=+-@\t\r", $cell[0]) !== false ? "'" . $cell : $cell;
                }, $mapRow($row)));
            }
            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}