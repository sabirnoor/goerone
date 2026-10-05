<?php

namespace App\Http\Controllers;

use App\Exceptions\VoucherException;
use App\Http\Controllers\Controller;
use App\Models\VoucherCartItem;
use App\Models\Vouchers;
use App\Services\Voucher\VoucherCartService;
use Illuminate\Http\Request;

class VoucherCartController extends Controller
{
    public function __construct(private VoucherCartService $cart) {}

    public function index(Request $request)
    {
        return response()->json(['status' => true, 'data' => $this->cart->summary($request->user()->id)]);
    }

    /** Add a voucher (single or multiple qty). Adding again increases the quantity. */
    public function store(Request $request)
    {
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
        $max  = config('voucher.max_qty_per_voucher');

        $data = $request->validate([
            'voucher_id' => 'required|integer|exists:vouchers,id',
            'quantity'   => "nullable|integer|min:1|max:{$max}",
        ]);

        $voucher = Vouchers::purchasable()->where('AgencyID', $AgencyID)->find($data['voucher_id']);
        if (! $voucher) {
            throw new VoucherException('This voucher is not available for purchase.');
        }

        $item   = VoucherCartItem::firstOrNew(['customer_id' => $request->user()->id, 'voucher_id' => $voucher->id]);
        $newQty = min(($item->quantity ?? 0) + ($data['quantity'] ?? 1), $max);

        $left = $voucher->remainingStock();
        if ($left !== null && $newQty > $left) {
            throw new VoucherException("Only {$left} left for '{$voucher->voucher_name}'.");
        }

        $item->quantity = $newQty;
        $item->save();

        return response()->json([
            'status'  => true,
            'message' => 'Voucher added to cart.',
            'data'    => $this->cart->summary($request->user()->id),
        ]);
    }

    /** Set an exact quantity */
    public function update(Request $request, int $voucherId)
    {
        $AgencyID = $request->user()->UserType == 1 ? $request->user()->id : $request->user()->AgencyID;
        $max  = config('voucher.max_qty_per_voucher');
        $data = $request->validate(['quantity' => "required|integer|min:1|max:{$max}"]);

        $item    = VoucherCartItem::where('customer_id', $request->user()->id)->where('voucher_id', $voucherId)->firstOrFail();
        $voucher = Vouchers::where('AgencyID', $AgencyID)->find($voucherId);

        $left = $voucher?->remainingStock();
        if ($left !== null && $data['quantity'] > $left) {
            throw new VoucherException("Only {$left} left for '{$voucher->voucher_name}'.");
        }

        $item->update(['quantity' => $data['quantity']]);

        return response()->json(['status' => true, 'data' => $this->cart->summary($request->user()->id)]);
    }

    public function destroy(Request $request, int $voucherId)
    {
        VoucherCartItem::where('customer_id', $request->user()->id)->where('voucher_id', $voucherId)->delete();

        return response()->json(['status' => true, 'data' => $this->cart->summary($request->user()->id)]);
    }

    public function clear(Request $request)
    {
        VoucherCartItem::where('customer_id', $request->user()->id)->delete();

        return response()->json(['status' => true, 'message' => 'Cart cleared.']);
    }
}
