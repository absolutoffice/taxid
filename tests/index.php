<?php

declare(strict_types=1);

/**
 * TaxId test environment.
 *
 * Browser:
 *   /taxId/tests/
 *
 * CLI:
 *   php tests/index.php
 */

if (PHP_SAPI === 'cli') {
    $testFiles = glob(__DIR__ . '/*Test.php') ?: [];
    sort($testFiles, SORT_NATURAL | SORT_FLAG_CASE);

    $passed = 0;
    $failed = 0;

    foreach ($testFiles as $testFile) {
        $name = basename($testFile);
        $output = '';
        $error = null;

        ob_start();

        try {
            (static function (string $file): void {
                require $file;
            })($testFile);
        } catch (Throwable $throwable) {
            $error = $throwable;
        } finally {
            $output = trim((string) ob_get_clean());
        }

        if ($error === null) {
            ++$passed;
            echo "[OK] {$name}\n";
        } else {
            ++$failed;
            echo "[FAIL] {$name}\n";
        }

        if ($output !== '') {
            foreach (preg_split('/\\R/', $output) ?: [] as $line) {
                echo "       {$line}\n";
            }
        }

        if ($error !== null) {
            printf(
                "       %s: %s (%s:%d)\n",
                $error::class,
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
            );
        }
    }

    $total = $passed + $failed;

    echo str_repeat('-', 72) . "\n";
    printf(
        "%d/%d test files passed, %d failed.\n",
        $passed,
        $total,
        $failed,
    );

    exit($failed === 0 ? 0 : 1);
}

use TaxId\Result\LookupResult;
use TaxId\Result\LookupStatus;
use TaxId\Result\ValidationResult;
use TaxId\Result\ValidationStatus;

/**
 * Read a scalar POST value without casting mixed input.
 */
function postString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;

    return is_string($value)
        ? $value
        : $default;
}

$error = null;
$taxId = null;

$esDni = null;
$esNie = null;
$esNif = null;
$ptNif = null;
$adNrt = null;
$normalized = null;
$invalid = null;
$unsupported = null;
$providerProbe = null;
$nifPtConfigured = false;

$manualValidation = null;
$manualValidationCountry = 'ES';
$manualValidationTaxId = '12345678Z';

$manualLookupValidation = null;
$manualLookup = null;
$manualLookupTaxId = '501442600';

try {
    $service = require __DIR__ . '/../bootstrap.php';

    if (!$service instanceof \TaxId\TaxIdService) {
        throw new RuntimeException(
            'TaxId bootstrap did not return a TaxIdService instance.'
        );
    }

    $taxId = $service;

    /*
     * Fixed integration tests.
     */
    $esDni = $taxId->validate('ES', '12345678Z');
    $esNie = $taxId->validate('ES', 'X1234567L');
    $esNif = $taxId->validate('ES', 'G86106630');
    $ptNif = $taxId->validate('PT', '501442600');
    $adNrt = $taxId->validate('AD', 'D-059888-N');

    $normalized = $taxId->validate(
        'ES',
        ' 12.345.678-z '
    );

    $invalid = $taxId->validate(
        'ES',
        '12345678A'
    );

    $unsupported = $taxId->validate(
        'FR',
        '123456789'
    );

    /*
     * Provider detection without consuming nif.pt credits.
     * The deliberately invalid input is rejected locally by the provider.
     */
    $providerProbe = $taxId->lookup('PT', '123');
    $nifPtConfigured = $providerProbe->provider === 'nif.pt';

    $action = postString('action');

    if ($action === 'validate') {
        $manualValidationCountry = strtoupper(trim(
            postString('country_code', 'ES')
        ));

        $manualValidationTaxId = trim(
            postString('tax_id')
        );

        $manualValidation = $taxId->validate(
            $manualValidationCountry,
            $manualValidationTaxId
        );
    }

    if ($action === 'lookup_pt') {
        $manualLookupTaxId = trim(
            postString('lookup_tax_id')
        );

        $manualLookupValidation = $taxId->validate(
            'PT',
            $manualLookupTaxId
        );

        if ($manualLookupValidation->isValid()) {
            $manualLookup = $taxId->lookup(
                'PT',
                $manualLookupTaxId
            );
        }
    }
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

/**
 * Escape HTML output.
 */
function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/**
 * Human-readable label for ValidationResult.
 */
function validationLabel(ValidationResult $result): string
{
    return match ($result->status) {
        ValidationStatus::VALID => 'Identificador válido.',
        ValidationStatus::INVALID => 'Identificador no válido.',
        ValidationStatus::NOT_SUPPORTED => 'No hay validador registrado para este país.',
    };
}

/**
 * Human-readable label for LookupResult.
 */
function lookupLabel(LookupResult $result): string
{
    return match ($result->status) {
        LookupStatus::FOUND => 'Lookup completado: hay datos asociados.',
        LookupStatus::NO_DATA => 'Lookup completado: NIF válido, pero nif.pt no dispone de datos de una entidad asociada.',
        LookupStatus::RATE_LIMITED => 'Límite de nif.pt alcanzado. Inténtalo de nuevo más tarde.',
        LookupStatus::ERROR => 'Error al realizar el lookup.',
        LookupStatus::NOT_SUPPORTED => 'Lookup no soportado para este país.',
    };
}

/**
 * Compact status class for ValidationResult.
 */
function validationClass(?ValidationResult $result): string
{
    if ($result === null) {
        return '';
    }

    return match ($result->status) {
        ValidationStatus::VALID => 'success',
        ValidationStatus::INVALID => 'error',
        ValidationStatus::NOT_SUPPORTED => 'warning',
    };
}

/**
 * Compact status class for LookupResult.
 */
function lookupClass(?LookupResult $result): string
{
    if ($result === null) {
        return '';
    }

    return match ($result->status) {
        LookupStatus::FOUND => 'success',
        LookupStatus::NO_DATA => 'warning',
        LookupStatus::RATE_LIMITED => 'warning',
        LookupStatus::ERROR => 'error',
        LookupStatus::NOT_SUPPORTED => 'warning',
    };
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>TaxId - Pruebas</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            line-height: 1.5;
            color: #222;
            background: #fff;
        }

        h1,
        h2 {
            color: #1f3b5b;
        }

        h1 {
            margin-bottom: 10px;
        }

        .box {
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            background: #fafafa;
            scroll-margin-top: 20px;
        }

        pre {
            background: #f4f4f4;
            padding: 12px;
            overflow-x: auto;
            border-radius: 4px;
        }

        code {
            background: #f1f1f1;
            padding: 2px 5px;
            border-radius: 3px;
        }

        .error {
            color: #a40000;
            font-weight: bold;
        }

        .success {
            color: #146b2d;
            font-weight: bold;
        }

        .warning {
            color: #8a5a00;
            font-weight: bold;
        }

        .muted {
            color: #666;
        }

        .test-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 10px 16px;
            max-width: 900px;
            align-items: center;
        }

        .inline-fields {
            display: flex;
            gap: 10px;
        }

        .inline-fields input {
            flex: 1;
        }

        input,
        select,
        button {
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid #bbb;
            border-radius: 4px;
            font: inherit;
        }

        input,
        select {
            background: #fff;
        }

        button {
            cursor: pointer;
            background: #1f3b5b;
            color: #fff;
            border-color: #1f3b5b;
        }

        button:hover {
            background: #294d74;
        }

        .test-status {
            min-height: 24px;
            font-weight: bold;
            color: #1f3b5b;
        }

        .test-index {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(320px, 1fr)
                );
            gap: 10px;
        }

        .test-index li {
            margin: 0;
        }

        .test-index a {
            display: block;
            padding: 10px 14px;
            border: 1px solid #d6d6d6;
            border-radius: 5px;
            background: #fff;
            color: #1f3b5b;
            text-decoration: none;
            transition:
                background-color 0.15s ease,
                border-color 0.15s ease,
                transform 0.15s ease;
        }

        .test-index a:hover {
            background: #f4f4f4;
            border-color: #aaa;
            transform: translateY(-1px);
        }

        .test-index-number {
            display: inline-block;
            min-width: 82px;
            font-weight: bold;
        }

        .notice {
            border-left: 4px solid #1f3b5b;
            padding-left: 12px;
        }

        @media (max-width: 700px) {
            body {
                margin: 15px;
            }

            .test-grid {
                grid-template-columns: 1fr;
            }

            .test-index {
                grid-template-columns: 1fr;
            }

            .inline-fields {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<h1>TaxId</h1>

<p>
    Entorno simple de prueba para validar el autoload de Composer,
    el bootstrap, los validadores fiscales por país y los providers
    externos configurados en TaxId.
</p>

<div class="box">
    <h2>Qué hace este proyecto</h2>

    <p>
        TaxId separa la validación local de identificadores fiscales
        de las consultas a proveedores externos.
    </p>

    <ul>
        <li>
            <strong>Validadores</strong>:
            comprueban formato y control local para
            <code>ES</code>, <code>PT</code> y <code>AD</code>.
        </li>

        <li>
            <strong>TaxIdRegistry</strong>:
            registra validadores y providers por país.
        </li>

        <li>
            <strong>TaxIdService</strong>:
            expone la API pública
            <code>validate()</code> y <code>lookup()</code>.
        </li>

        <li>
            <strong>Providers</strong>:
            permiten enriquecer un identificador sin formar parte
            de su validación local. Actualmente se soporta
            <code>nif.pt</code> para Portugal cuando hay API key.
        </li>
    </ul>
</div>

<?php if ($error !== null) : ?>
    <div class="box">
        <h2>Error</h2>

        <p class="error">
            <?= e($error) ?>
        </p>
    </div>

<?php else : ?>
    <!-- ========================================================= -->
    <!-- ÍNDICE DE PRUEBAS                                         -->
    <!-- ========================================================= -->

    <div class="box">
        <h2>Índice de pruebas</h2>

        <p>
            Selecciona una prueba para desplazarte directamente
            a su apartado.
        </p>

        <ul class="test-index">
            <li>
                <a href="#prueba-1">
                    <span class="test-index-number">Prueba 1</span>
                    Validación ES / DNI
                </a>
            </li>

            <li>
                <a href="#prueba-2">
                    <span class="test-index-number">Prueba 2</span>
                    Validación ES / NIE
                </a>
            </li>

            <li>
                <a href="#prueba-3">
                    <span class="test-index-number">Prueba 3</span>
                    Validación ES / NIF
                </a>
            </li>

            <li>
                <a href="#prueba-4">
                    <span class="test-index-number">Prueba 4</span>
                    Validación PT / NIF
                </a>
            </li>

            <li>
                <a href="#prueba-5">
                    <span class="test-index-number">Prueba 5</span>
                    Validación AD / NRT
                </a>
            </li>

            <li>
                <a href="#prueba-6">
                    <span class="test-index-number">Prueba 6</span>
                    Normalización
                </a>
            </li>

            <li>
                <a href="#prueba-7">
                    <span class="test-index-number">Prueba 7</span>
                    Identificador inválido
                </a>
            </li>

            <li>
                <a href="#prueba-8">
                    <span class="test-index-number">Prueba 8</span>
                    País sin validador: NOT_SUPPORTED
                </a>
            </li>

            <li>
                <a href="#prueba-9">
                    <span class="test-index-number">Prueba 9</span>
                    Estado provider nif.pt
                </a>
            </li>

            <li>
                <a href="#prueba-10">
                    <span class="test-index-number">Prueba 10</span>
                    Validación manual
                </a>
            </li>

            <li>
                <a href="#prueba-11">
                    <span class="test-index-number">Prueba 11</span>
                    Lookup PT / nif.pt
                </a>
            </li>
        </ul>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 1                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-1">
        <h2>
            Prueba 1:
            TaxIdService::validate('ES', '12345678Z')
        </h2>

        <?php var_dump($esDni); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 2                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-2">
        <h2>
            Prueba 2:
            TaxIdService::validate('ES', 'X1234567L')
        </h2>

        <?php var_dump($esNie); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 3                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-3">
        <h2>
            Prueba 3:
            TaxIdService::validate('ES', 'G86106630')
        </h2>

        <?php var_dump($esNif); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 4                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-4">
        <h2>
            Prueba 4:
            TaxIdService::validate('PT', '501442600')
        </h2>

        <?php var_dump($ptNif); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 5                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-5">
        <h2>
            Prueba 5:
            TaxIdService::validate('AD', 'D-059888-N')
        </h2>

        <?php var_dump($adNrt); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 6                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-6">
        <h2>
            Prueba 6:
            Normalización automática
        </h2>

        <p>
            Entrada:
            <code> 12.345.678-z </code>
        </p>

        <?php var_dump($normalized); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 7                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-7">
        <h2>
            Prueba 7:
            Identificador con control inválido
        </h2>

        <p>
            Entrada:
            <code>ES / 12345678A</code>
        </p>

        <?php var_dump($invalid); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 8                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-8">
        <h2>
            Prueba 8:
            País sin validador registrado: NOT_SUPPORTED
        </h2>

        <p>
            Entrada:
            <code>FR / 123456789</code>
        </p>

        <p class="warning">
            No se marca como inválido: TaxId no dispone todavía de
            un validador para FR.
        </p>

        <?php var_dump($unsupported); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 9                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-9">
        <h2>
            Prueba 9:
            Estado del provider nif.pt
        </h2>

        <p>
            Provider configurado:
            <strong class="<?= $nifPtConfigured ? 'success' : 'error' ?>">
                <?= $nifPtConfigured ? 'Sí' : 'No' ?>
            </strong>
        </p>

        <p class="muted">
            Esta comprobación no realiza ninguna petición externa ni consume
            créditos de nif.pt.
        </p>

        <?php var_dump($providerProbe); ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 10                                                 -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-10">
        <h2>
            Prueba 10:
            Validación manual
        </h2>

        <p>
            Prueba directamente la API pública
            <code>TaxIdService::validate()</code>.
        </p>

        <form
            method="post"
            action="#prueba-10"
            class="test-grid"
        >
            <input
                type="hidden"
                name="action"
                value="validate"
            >

            <label for="country_code">
                <strong>País</strong>
            </label>

            <select
                id="country_code"
                name="country_code"
            >
                <?php foreach (['ES', 'PT', 'AD'] as $countryCode) : ?>
                    <option
                        value="<?= e($countryCode) ?>"
                        <?= $manualValidationCountry === $countryCode
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($countryCode) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="tax_id">
                <strong>Identificador</strong>
            </label>

            <div class="inline-fields">
                <input
                    type="text"
                    id="tax_id"
                    name="tax_id"
                    value="<?= e($manualValidationTaxId) ?>"
                    autocomplete="off"
                >

                <button type="submit">
                    Validar
                </button>
            </div>
        </form>

        <?php if ($manualValidation !== null) : ?>
            <p class="<?= validationClass($manualValidation) ?>">
                <?= e(validationLabel($manualValidation)) ?>
            </p>

            <?php var_dump($manualValidation); ?>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 11                                                 -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-11">
        <h2>
            Prueba 11:
            TaxIdService::lookup('PT', $nif)
        </h2>

        <p>
            Primero valida localmente el NIF portugués. Solo si es válido
            ejecuta el lookup externo.
        </p>

        <?php if (!$nifPtConfigured) : ?>
            <p class="notice">
                <strong>nif.pt no está configurado.</strong>
                Añade <code>NIF_PT_API_KEY</code> en <code>.env</code>
                para habilitar esta prueba.
            </p>
        <?php else : ?>
            <p class="notice">
                Esta prueba realiza una petición real a
                <code>nif.pt</code> y puede consumir crédito de la API.
            </p>
        <?php endif; ?>

        <form
            method="post"
            action="#prueba-11"
            class="test-grid"
        >
            <input
                type="hidden"
                name="action"
                value="lookup_pt"
            >

            <label for="lookup_tax_id">
                <strong>NIF PT</strong>
            </label>

            <div class="inline-fields">
                <input
                    type="text"
                    id="lookup_tax_id"
                    name="lookup_tax_id"
                    value="<?= e($manualLookupTaxId) ?>"
                    autocomplete="off"
                >

                <button type="submit">
                    Consultar
                </button>
            </div>
        </form>

        <?php if ($manualLookupValidation !== null) : ?>
            <h3>Validación local</h3>

            <?php var_dump($manualLookupValidation); ?>
        <?php endif; ?>

        <?php if (
            $manualLookupValidation !== null
            && $manualLookupValidation->isInvalid()
        ) : ?>
            <p class="error">
                No se realiza lookup porque el NIF no supera
                la validación local.
            </p>
        <?php endif; ?>

        <?php if ($manualLookup !== null) : ?>
            <p class="<?= lookupClass($manualLookup) ?>">
                <?= e(lookupLabel($manualLookup)) ?>
            </p>

            <?php if ($manualLookup->message !== null) : ?>
                <p>
                    <strong>Detalle:</strong>
                    <?= e($manualLookup->message) ?>
                </p>
            <?php endif; ?>

            <?php var_dump($manualLookup); ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

</body>
</html>
