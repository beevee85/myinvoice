<?php

declare(strict_types=1);

// Composer autoloader (stejně jako vendor/autoload.php — sjednocuje vstupní bod)
require __DIR__ . '/../vendor/autoload.php';

// Bypass `final class` u tříd, které potřebujeme mockovat v unit testech
// (PurchaseInvoiceRepository, Connection a další). PHPUnit 13 nepodporuje
// mockování final tříd nativně; dg/bypass-finals to runtime přepíše.
//
// MUSÍ zůstat PRVNÍ: přepis probíhá při načtení souboru, takže každá třída
// načtená dřív si `final` ponechá a přestane jít mockovat (guard níže sahá
// na Config, což bez tohohle pořadí shodí 123 unit testů).
\DG\BypassFinals::enable();

// FORK (beevee85): pojistka proti běhu proti ostré / sdílené databázi.
// Integrační testy zapisují a mažou reálné řádky a na nativní instalaci je
// `cfg.php` v kořeni repa zároveň produkční konfigurací. Guard čte jen
// konfiguraci (žádné DB spojení) a při nesouladu běh ukončí s návodem.
// Chybějící cfg.php propustí — testy se skipnou samy.
\MyInvoice\Tests\Support\TestDatabaseGuard::assertOrExit(dirname(__DIR__, 2));
