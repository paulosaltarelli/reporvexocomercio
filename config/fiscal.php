<?php

return [
    'purchase_invoices_enabled' => env('VENDRA_PURCHASE_INVOICES_ENABLED'),
    'nfce_homologation_tenant_ids' => env('VENDRA_NFCE_HOMOLOGATION_TENANT_IDS', ''),
    'nfce_pix_defaults' => ['casa-de-bolos-sjdr' => 'static'],
    'product_invoices_enabled' => env('VENDRA_PRODUCT_INVOICES_ENABLED', false),
];
