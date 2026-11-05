<?php

/*
|--------------------------------------------------------------------------
| Legal and business details
|--------------------------------------------------------------------------
|
| Rendered into the public privacy, terms and cookie pages and the page footer.
| A value left empty is shown as "not configured" on the pages (never invented).
| The page texts are drafts and must be reviewed by whoever is legally responsible.
|
*/

return [
    'company_name' => env('LEGAL_COMPANY_NAME'),
    'registration_number' => env('LEGAL_REGISTRATION_NUMBER'),
    'vat_number' => env('LEGAL_VAT_NUMBER'),
    'address' => env('LEGAL_ADDRESS'),
    'contact_email' => env('LEGAL_CONTACT_EMAIL'),
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL', env('LEGAL_CONTACT_EMAIL')),
    'governing_law' => env('LEGAL_GOVERNING_LAW'),
    'updated_at' => env('LEGAL_UPDATED_AT'),
];
