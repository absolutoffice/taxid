<?php

declare(strict_types=1);

use TaxId\TaxIdFactory;
use TaxId\TaxIdService;

require_once __DIR__ . '/config/environment.php';

/** @var TaxIdService $taxId */
$taxId = TaxIdFactory::fromEnvironment(__DIR__ . '/.env');

return $taxId;
