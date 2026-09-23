<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BelongsToAgency implements ValidationRule
{
    protected $agencyId;

    public function __construct($agencyId)
    {
        $this->agencyId = $agencyId;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = User::where('id', $value)
            ->where('AgencyID', $this->agencyId)
            ->exists();
        if (!$exists) {
            $fail('The selected customer does not belong to the specified agency.');
        }
    }
}
