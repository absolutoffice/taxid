<?php

declare(strict_types=1);

use TaxId\Result\LookupStatus;
use TaxId\Result\ValidationStatus;
use TaxId\TaxIdFactory;

spl_autoload_register(static function (string $class): void {
    $prefix = 'TaxId\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/TaxId/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

function assertFactory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = TaxIdFactory::create();

assertFactory($service->validate('ES', '12345678Z')->status === ValidationStatus::VALID, 'ES validator was not registered.');
assertFactory($service->validate('PT', '501442600')->status === ValidationStatus::VALID, 'PT validator was not registered.');
assertFactory($service->validate('AD', '800649P')->status === ValidationStatus::VALID, 'AD validator was not registered.');

$unsupported = $service->validate('FR', '123456789');
assertFactory($unsupported->status === ValidationStatus::NOT_SUPPORTED, 'Unsupported country must return NOT_SUPPORTED.');
assertFactory(!$unsupported->isInvalid(), 'Unsupported country must not be reported as INVALID.');
assertFactory(!$unsupported->isSupported(), 'Unsupported country must report isSupported()=false.');

$lookup = $service->lookup('PT', '501442600');
assertFactory($lookup->status === LookupStatus::NOT_SUPPORTED, 'PT provider should be NOT_SUPPORTED without API key.');
assertFactory(!$lookup->isSupported(), 'Disabled PT provider should report isSupported()=false.');
assertFactory(
    $lookup->message === 'Tax ID lookup is not supported for country PT.',
    'Unexpected lookup message when nif.pt provider is disabled.',
);

$temp = tempnam(sys_get_temp_dir(), 'taxid-env-');
if ($temp === false) {
    throw new RuntimeException('Unable to create temporary .env file.');
}

try {
    file_put_contents($temp, "# local config\nNIF_PT_API_KEY=\nNIF_PT_TIMEOUT=7\n");
    $serviceFromEnv = TaxIdFactory::fromEnvironment($temp);
    assertFactory($serviceFromEnv->validate('ES', '12345678Z')->status === ValidationStatus::VALID, 'Factory from .env failed.');
} finally {
    @unlink($temp);
}

$thrown = false;
try {
    TaxIdFactory::create([
        'nif_pt' => [
            'api_key' => 'test-key',
            'timeout' => 'abc',
        ],
    ]);
} catch (\InvalidArgumentException) {
    $thrown = true;
}

assertFactory($thrown, 'Invalid NIF_PT_TIMEOUT should throw InvalidArgumentException.');

echo "OK: TaxIdFactory bootstrap/configuration tests passed.\n";
