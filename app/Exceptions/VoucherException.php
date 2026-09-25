<?php

namespace App\Exceptions;

use RuntimeException;

class VoucherException extends RuntimeException
{
    public function render()
    {
        return response()->json(['status' => false, 'message' => $this->getMessage()], 422);
    }
}
