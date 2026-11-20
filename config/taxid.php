<?php

declare(strict_types=1);

return [
    'nif_pt' => [
        'api_key' => getenv('NIF_PT_API_KEY') !== false ? getenv('NIF_PT_API_KEY') : null,
        'base_url' => getenv('NIF_PT_BASE_URL') !== false ? getenv('NIF_PT_BASE_URL') : 'https://www.nif.pt/',
        'timeout' => getenv('NIF_PT_TIMEOUT') !== false ? getenv('NIF_PT_TIMEOUT') : 5,
    ],
];
