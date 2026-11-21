<?php

return [
    // "Ignore due days" and settlement:cancel are testing tools.
    // They always work outside production; on production they need SETTLEMENT_ALLOW_OVERRIDE=true in .env
    // (leave it unset/false on production).
    'allow_test_override' => env('SETTLEMENT_ALLOW_OVERRIDE', false),
];