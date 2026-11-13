<?php

namespace App\Services\Notifications;

use App\Models\Invoices;
use App\Models\invoices_items;
use App\Models\User;
use Mpdf\Mpdf;

class InvoicePdf
{
    /** @return array{path:string,name:string} temp file; the runner deletes it after sending */
    public static function make(int $invoiceId): array
    {
        $invoice = Invoices::findOrFail($invoiceId);
        $items   = invoices_items::where('invoice_id', $invoiceId)->get();

        $html = view('invoices.membership_invoice', [
            'invoice'  => $invoice,
            'items'    => $items,
            'customer' => User::find($invoice->customer_id),
            'agency'   => User::find($invoice->AgencyID),
        ])->render();

        $dir = storage_path('app/tmp-invoices');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf'), 'mode' => 'utf-8', 'format' => 'A4']);
        $mpdf->WriteHTML($html);

        $name = 'Invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoice->invoiceNo) . '.pdf';
        $path = $dir . '/' . uniqid('inv_') . '.pdf';
        file_put_contents($path, $mpdf->Output($name, 'S'));

        return ['path' => $path, 'name' => $name];
    }
}