# TaxID 1.0.0

**Tax identifier validation for PHP, with optional external lookups.**

TaxID validates country-specific tax identifiers locally and keeps that validation separate from optional third-party information lookups. It is designed to be extended with validators and providers for additional countries.

- **Version:** 1.0.0
- **PHP:** `>=8.4.27 <8.5 || >=8.5.12 <8.6` (with `ext-json`). PHP 8.4.0-8.4.26, PHP 8.5.0-8.5.11 and PHP 8.6+ are not supported.
- **Composer package:** [`absolutoffice/taxid`](https://packagist.org/packages/absolutoffice/taxid)
- **Source repository:** [absolutoffice/taxid](https://github.com/absolutoffice/taxid)

> **NOTE:** A locally valid tax identifier matches the rules implemented by its validator. It does **not** prove that the identifier has been issued, belongs to a specific person or organization, or remains active.

## Supported countries

| Country | Code | Local validation | Optional external lookup |
| --- | :---: | --- | --- |
| Spain | `ES` | DNI, NIE and entity NIF | Not included |
| Portugal | `PT` | NIF | `nif.pt` (requires a separate API key) |
| Andorra | `AD` | NRT | Not included |

**Want to help add another country?** See [Contributing country support](#contributing-country-support) below. Contributions implementing additional country rules are welcome, subject to the project's licensing and contribution terms.

## Installation

### Option 1: Composer (recommended)

In the root directory of your PHP application, run:

```bash
composer require absolutoffice/taxid
```

Then use the application's Composer autoloader:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use TaxId\TaxIdFactory;

$taxId = TaxIdFactory::create();
$result = $taxId->validate('ES', '12345678Z');

if ($result->isValid()) {
    echo 'Valid format and control characters';
}
```

No `.env` file or external service is needed for local validation.

### Option 2: Download a ZIP from GitHub

1. Visit [the TaxID GitHub repository](https://github.com/absolutoffice/taxid).
2. Choose **Code → Download ZIP**, then extract the library into your application, for example `libs/taxId/`.
3. Load `config/environment.php` from the extracted directory. It registers a lightweight `TaxId\` autoloader if the library's Composer autoloader is unavailable.

```php
<?php

require_once __DIR__ . '/libs/taxId/config/environment.php';

use TaxId\TaxIdFactory;

$taxId = TaxIdFactory::create();
$result = $taxId->validate('PT', '501442600');

var_dump($result->status->value);
```

Adjust `libs/taxId/` to the directory where you extracted the ZIP. This alternative does not require a separate Composer installation inside the library.

## Local validation and external lookup

Local validation and external lookups are **different operations**:

```php
$validation = $taxId->validate('PT', '501442600');
$lookup = $taxId->lookup('PT', '501442600');
```

| Method | What it does | Possible statuses |
| --- | --- | --- |
| `validate($countryCode, $taxId)` | Checks local format and control rules. Does not query an external service. | `VALID`, `INVALID`, `NOT_SUPPORTED` |
| `lookup($countryCode, $taxId)` | Requests associated information from a configured provider. | `FOUND`, `NO_DATA`, `RATE_LIMITED`, `ERROR`, `NOT_SUPPORTED` |

With no provider configured, a lookup returns `NOT_SUPPORTED`; local validation still works. `NO_DATA` means the provider returned no associated entity information, **not** that the identifier is invalid.

## Optional `nif.pt` configuration

The Portuguese lookup uses an external service, [nif.pt](https://www.nif.pt/). It is optional and requires a separate API key. Its availability, quota and returned data are outside TaxID's local-validation rules.

The location of your `.env` file is **chosen by each installation**. For example, it can be placed outside the web-accessible directory:

```text
[BaseDir]/../.private/taxid/.env
```

Use [`.env.example`](.env.example) as a template for the file contents:

```dotenv
NIF_PT_API_KEY=YOUR_API_KEY
NIF_PT_BASE_URL=https://www.nif.pt/
NIF_PT_TIMEOUT=5
```

Pass the **full path to the `.env` file**, not just the containing directory:

```php
<?php

// Load the relevant autoloader first (Composer or the GitHub ZIP example).
use TaxId\TaxIdFactory;

$baseDir = __DIR__; // Adjust to your application's base directory.
$envFile = $baseDir . '/../.private/taxid/.env';

if (!is_readable($envFile)) {
    throw new RuntimeException('TaxID .env file was not found or is unreadable.');
}

$taxId = TaxIdFactory::fromEnvironment($envFile);
$lookup = $taxId->lookup('PT', '501442600');
```

**Important:** `fromEnvironment()` accepts a file path, not a directory path. If the file is missing, the library ignores it; the explicit `is_readable()` check above is recommended when your application requires that configuration file. Process environment variables take precedence over values in the `.env` file.

Do not commit credentials or place private configuration in a publicly accessible web directory. You can also use `TaxIdFactory::create()` with an explicit configuration array instead of a `.env` file.

## Public contracts and extension points

TaxID's country-specific implementations use two contracts, which remain unchanged in version 1.0.0:

| Interface | Required methods | Purpose |
| --- | --- | --- |
| [`TaxIdValidatorInterface`](src/TaxId/Contract/TaxIdValidatorInterface.php) | `countryCode(): string`, `validate(string $taxId): ValidationResult` | Local, country-specific validation |
| [`TaxIdProviderInterface`](src/TaxId/Contract/TaxIdProviderInterface.php) | `countryCode(): string`, `lookup(string $taxId): LookupResult` | Optional external data lookup |

Implementations are registered through [`TaxIdRegistry`](src/TaxId/TaxIdRegistry.php) and exposed through [`TaxIdService`](src/TaxId/TaxIdService.php). The default [`TaxIdFactory`](src/TaxId/TaxIdFactory.php) includes the ES, PT and AD validators and registers the `nif.pt` provider only when a non-empty API key is configured.

**Do not change these interfaces to add support for a country.** New countries are added by implementing the existing contracts and registering the implementation.

## Contributing country support

**Help us bring TaxID to more countries.** We welcome proposals, corrections and pull requests from developers who know the tax-identification rules in their country.

To propose a new validator:

1. [Open an issue](https://github.com/absolutoffice/taxid/issues) describing the country (ISO 3166-1 alpha-2 code), identifier types, and the official specification or authoritative references.
2. Implement `TaxIdValidatorInterface` under an appropriate `src/TaxId/Country/<CODE>/` namespace. Keep validation local: check format and control rules without remote requests.
3. Return `ValidationResult` with the appropriate `ValidationStatus`; do not confuse an unrecognized identifier with an unsupported country.
4. Add automated tests covering valid values, invalid values, normalization, boundaries and control digits. Avoid real personal identifiers in tests when synthetic values suffice.
5. Update the supported-country table and the documentation. Submit a [pull request](https://github.com/absolutoffice/taxid/pulls) for review.

External lookups are optional and separate: where an appropriate data source exists, implement `TaxIdProviderInterface` and document its access requirements, errors, quotas and privacy considerations. **Never include API keys, credentials or private data in a contribution.**

Contributions must comply with the repository's applicable license and contribution terms; check them before submitting third-party code or rules.

## User manuals (PDF)

Detailed installation instructions, examples, contract descriptions, status tables and troubleshooting are available in four languages:

| Language | Manual |
| --- | --- |
| Español (`es`) | [Manual de usuario TaxID 1.0.0](docs/manual-usuario-taxid.pdf) |
| Português (`pt-PT`) | [Manual do Utilizador TaxID 1.0.0](docs/manual-do-utilizador-taxid.pdf) |
| English (`en`) | [TaxID 1.0.0 User Manual](docs/taxid-user-manual.pdf) |
| Français (`fr`) | [Manuel utilisateur TaxID 1.0.0](docs/manuel-utilisateur-taxid.pdf) |

The PDFs are stored in [`docs/`](docs/) in the GitHub repository.

## For maintainers

Run the included regression tests from the project root:

```bash
php tests/index.php
```

For release verification, run the strict two-runtime matrix with **real** PHP 8.4.27+ (below 8.5) and PHP 8.5.12+ (below 8.6) executables:

```bash
bash scripts/test-php-matrix.sh /path/to/php-8.4 /path/to/php-8.5
```

Replace the sample paths with the actual PHP CLI executables. If the commands `php8.4` and `php8.5` are on `PATH`, arguments can be omitted. The runner verifies the real runtime version, checks syntax for every PHP file, and executes the five regression tests separately with **all PHP warnings, notices and deprecations treated as failures**. It does not require an API key, and it does not make live `nif.pt` requests.

By default, results are written to `var/test-results/php-8.4.log`, `php-8.5.log` and `summary.txt`. This directory is ignored by Git and excluded from the Composer archive. **If the repository is hosted beneath a public Apache document root**, keep test logs outside the served directory instead, for example:

```bash
TAXID_TEST_RESULTS_DIR=/home/Apache24/taxid-test-results \
  bash scripts/test-php-matrix.sh /path/to/php-8.4 /path/to/php-8.5
```

The directory must be writable by the CLI user. The matrix does not claim success for a missing or unsupported interpreter. GitHub Actions also runs the suite for both PHP branches and uploads its logs as workflow artifacts. `config.platform.php` targets 8.4.27 for Composer dependency resolution, but real PHP runtime compatibility must be tested separately.

The browser-based manual test interface at `tests/index.php` is for local development only. Do not expose it on a public server, particularly when a `nif.pt` API key is configured: its lookup form performs real requests and may consume API credits.

### Final release-quality audit (PHP 8.4 and 8.5)

After the runtime compatibility matrix passes, run the additional **Composer, PHPStan, PHP-CS-Fixer and independent-consumer checks** with both real PHP interpreters. This requires Composer 2, Git, tar and internet access for downloading development dependencies and checking security advisories.

```bash
TAXID_TEST_RESULTS_DIR=/home/Apache24/taxid-test-results \
  bash scripts/audit-release-matrix.sh php8.4 php8.5
```

The audit reuses a Composer download cache at `<results-dir>/.composer-cache` across PHP 8.4 and 8.5; set `TAXID_COMPOSER_CACHE_DIR` to override it. If GitHub returns HTTP 429 while downloading a development tool as a ZIP, the audit retries the installation once using Git source checkouts. A second failure remains a failure; no checks are silently skipped. Keep the results/cache directory outside public Apache document roots where possible.

The audit runs `composer validate --strict --no-check-lock`, installs development tools in an isolated temporary directory, checks actual platform requirements, performs `composer audit`, runs PHPStan and PHP-CS-Fixer in checking mode, creates a `git archive` distribution with the configured exclusions, and installs that exported distribution into an independent Composer consumer. The source working tree is **not modified**.

Results are written to `php-8.4-quality.log`, `php-8.5-quality.log` and `summary-quality.txt` in the specified results directory; the original PHP regression logs are also refreshed. A missing tool, unavailable dependency/security feed, static-analysis finding or failing consumer installation causes **FAIL**; no check is silently reported as a success. To select a specific Composer PHAR, set `TAXID_COMPOSER=/absolute/path/to/composer.phar`. **Do not create the Git tag until both quality audits pass** and their logs have been reviewed.

The individual tools can also be run manually when development dependencies are installed:

```bash
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer check --using-cache=no
```

The [PHPDoc](src/) describes the public API, and `phpdoc.dist.xml` contains the configuration for generating HTML API documentation.

## License

Copyright © 2026 Absolut Office.

TaxID is licensed under the
[GNU LGPL-3.0-only](LICENSE), which incorporates the
[GNU GPLv3 terms](COPYING).

Both license texts are included in this repository.
