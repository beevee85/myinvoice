<?php

declare(strict_types=1);

/**
 * FORK (beevee85) — nadstavba nad `ci-seed.php`: syntetičtí klienti a IČO tenanta.
 *
 *   php api/bin/test-seed-clients.php
 *
 * PROČ: `ci-seed.php` zakládá jen dodavatele (tenanty), měny a admina — žádné klienty
 * a tenantům nechává prázdné IČO. Kvůli tomu se z Integration suity přeskakuje 103
 * testů („Supplier #1 nemá žádné klienty", „Chybí client/user pro supplier.",
 * „Chybí supplier s IČO / user / měna v DB." …), tedy 5 % celé suity — a právě v okolí
 * přijatých faktur, dodavatelů a cross-tenant guardů, kam sahá dávkový import.
 * Po doplnění těchto dat klesne počet skipů ze 134 na 31 a přibude ~390 asercí.
 *
 * Data jsou VÝHRADNĚ syntetická: vymyšlené firmy, `example.invalid` e-maily a IČO,
 * která projdou kontrolní číslicí mod 11 (validátory je jinak odmítnou).
 *
 * Skript je idempotentní a chráněný `TestDatabaseGuard` — proti ostré ani sdílené
 * databázi se nespustí.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$guardFile = __DIR__ . '/../tests/Support/TestDatabaseGuard.php';
if (!is_file($guardFile)) {
    fwrite(STDERR, "Chybí api/tests/Support/TestDatabaseGuard.php — tenhle skript patří jen do vývojového stromu.\n");
    exit(1);
}
require_once $guardFile;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\TestDatabaseGuard;

TestDatabaseGuard::assertOrExit(Bootstrap::rootDir());

/** Kontrolní číslice IČO (mod 11) — ať fixture projde stejnou validací jako ostrá data. */
function fixtureIcoIsValid(string $ico): bool
{
    if (preg_match('/^\d{8}$/', $ico) !== 1) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $sum += (int) $ico[$i] * (8 - $i);
    }
    $remainder = $sum % 11;
    $check = match ($remainder) {
        0       => 1,
        1       => 0,
        default => 11 - $remainder,
    };
    return $check === (int) $ico[7];
}

$pdo = Bootstrap::buildApp()->getContainer()->get(Connection::class)->pdo();

$countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
$vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn()
    ?: $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn());
if ($countryId < 1 || $vatRateId < 1) {
    fwrite(STDERR, "Chybí číselníky (countries/vat_rates) — spusť nejdřív `php api/bin/migrate.php`.\n");
    exit(2);
}

/** @var list<int> $supplierIds */
$supplierIds = array_map('intval', $pdo->query('SELECT id FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
if ($supplierIds === []) {
    fwrite(STDERR, "V DB není žádný dodavatel — spusť nejdřív `php api/bin/ci-seed.php`.\n");
    exit(3);
}

// IČO tenantů (mod 11 OK). Doplňujeme jen tam, kde chybí — ostrá data nepřepisujeme.
$tenantIcos = ['45678901', '56789017', '67890123', '78901234'];

// [IČO, název, is_customer, is_vendor] pro prvního tenanta; dalším se přepočítá prefix.
$clientTemplates = [
    ['12345679', 'Fixture Odběratel s.r.o.', 1, 0],
    ['23456787', 'Fixture Dodavatel s.r.o.', 0, 1],
    ['34567895', 'Fixture Obojí a.s.',       1, 1],
];

/** Přepočítá kontrolní číslici pro daných 7 číslic základu. */
function fixtureIcoFromBase(string $base7): string
{
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $sum += (int) $base7[$i] * (8 - $i);
    }
    $remainder = $sum % 11;
    $check = match ($remainder) {
        0       => 1,
        1       => 0,
        default => 11 - $remainder,
    };
    return $base7 . $check;
}

$insertClient = $pdo->prepare(
    'INSERT INTO clients
        (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
         language, currency_default_id, vat_rate_default_id, is_customer, is_vendor, is_vat_payer)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$updateSupplierIc = $pdo->prepare(
    "UPDATE supplier SET ic = ?, dic = ? WHERE id = ? AND (ic IS NULL OR ic = '')"
);

$createdClients = 0;
$taggedTenants  = 0;
$skippedTenants = 0;

foreach ($supplierIds as $index => $supplierId) {
    $ico = $tenantIcos[$index] ?? null;
    if ($ico !== null && fixtureIcoIsValid($ico)) {
        $updateSupplierIc->execute([$ico, 'CZ' . $ico, $supplierId]);
        $taggedTenants += $updateSupplierIc->rowCount();
    }

    $existing = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id = {$supplierId}")->fetchColumn();
    if ($existing > 0) {
        $skippedTenants++;
        continue;
    }

    $currencyId = (int) $pdo->query(
        "SELECT id FROM currencies WHERE supplier_id = {$supplierId} AND code = 'CZK' LIMIT 1"
    )->fetchColumn();
    if ($currencyId < 1) {
        fwrite(STDERR, "Dodavatel #{$supplierId} nemá měnu CZK — přeskakuji.\n");
        continue;
    }

    foreach ($clientTemplates as $position => [$templateIco, $name, $isCustomer, $isVendor]) {
        // Unikátní IČO per tenant: první tenant bere šablonu, další posunou vedoucí číslici.
        $ic = $index === 0
            ? $templateIco
            : fixtureIcoFromBase(substr_replace(substr($templateIco, 0, 7), (string) (($index + 3) % 10), 0, 1));

        $insertClient->execute([
            $supplierId,
            $name . ' #' . $supplierId,
            $ic,
            'CZ' . $ic,
            'Testovací ' . ($position + 2),
            'Praha',
            '11000',
            $countryId,
            'fixture-client-' . $supplierId . '-' . $position . '@example.invalid',
            'cs',
            $currencyId,
            $vatRateId,
            $isCustomer,
            $isVendor,
        ]);
        $createdClients++;
    }
}

printf(
    "✓ Fixture klientů: %d nových klientů, %d tenantům doplněno IČO, %d dodavatelů přeskočeno (klienty už mají).\n",
    $createdClients,
    $taggedTenants,
    $skippedTenants,
);
