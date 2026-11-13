<?php

return [
    // manual : base créée à la main (mutualisé) | auto : créée par Tenancy (VPS)
    'provisioner' => env('TENANT_PROVISIONER', 'manual'),
];
