<?php

return [
    'name' => 'Inventory',

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The single currency this deployment trades in. Read through Support\Money,
    | never inline — a client deployment in another country changes the .env and
    | nothing else. Not a multi-currency feature: every document is denominated
    | in this one currency.
    |
    */
    'currency' => [
        'code'     => env('INVENTORY_CURRENCY_CODE', 'LKR'),
        'symbol'   => env('INVENTORY_CURRENCY_SYMBOL', 'Rs'),
        'decimals' => (int) env('INVENTORY_CURRENCY_DECIMALS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | Prices are stored BEFORE tax (price list, SO lines); the invoice adds VAT.
    | default_vat_pct is the rate a line takes when its costing names none. Read
    | through Support\Vat, never inline.
    |
    */
    'tax' => [
        'default_vat_pct' => (float) env('INVENTORY_DEFAULT_VAT_PCT', 18),
    ],
];
