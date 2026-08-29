<?php

return [
    // Optional explicit tenant override for a deployment. When absent,
    // FitFamilyContext resolves the provisioned FitFamily application.
    'empresa_id' => env('FITFAMILY_EMPRESA_ID'),
    'catalog_key' => 'fitfamily',
    'application_slug' => env('FITFAMILY_APPLICATION_SLUG', 'fitfamily'),
];
