<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signing Vault
    |--------------------------------------------------------------------------
    |
    | Envelope encryption for ERI/E-imzo keys (PLAN section 9). Each key is encrypted with its own data key;
    | data keys are encrypted with this master key, which is held outside the database. Use a KMS/HSM in
    | production; locally an environment secret: "base64:" followed by 32 random bytes in base64.
    |
    */

    'master_key' => env('SIGNING_MASTER_KEY'),

    /*
    | Days before expiry when the owner is warned. After expiry the key cannot be used and autopilot drops
    | to level 1.
    */
    'expiry_warning_days' => [30, 14, 3],

    /*
    | Maximum size of an uploaded key container (.pfx/.p12), in kilobytes.
    */
    'max_key_kb' => 64,

];
