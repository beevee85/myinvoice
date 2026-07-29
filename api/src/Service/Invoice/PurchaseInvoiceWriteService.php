<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * FORK (beevee85) — sdílená zapisovací sekvence přijaté faktury.
 *
 * Sekvenci `createDraft → replaceItems → vat_overrides → recompute` si dosud skládal
 * každý volající sám a kopií je v repu **sedm**, ne pár — oprava v jedné se do
 * ostatních nedostala (viz rozdíly zafixované charakterizačními testy):
 *
 *   1. `Action/PurchaseInvoice/CreatePurchaseInvoiceAction`  — PŘEVEDENO sem
 *   2. `Action/PurchaseInvoice/UpdatePurchaseInvoiceAction`  — kroky 2–4 (+ setRounding, reprefixVarsymbol)
 *   3. `Action/Bank/BankStatementAction:1296`                — doklad z bankovního výpisu
 *   4. `Service/Import/AiPdfExtractor:713`
 *   5. `Service/Import/IsdocToPurchaseInvoiceMapper:129`     — používá ji i scan-inbox a bundle import
 *   6. `Service/Import/IdokladImportService:666` a `:864`
 *   7. `Service/Import/FakturoidImportService:464`
 *
 * (`PurchaseInvoiceInboxScanner` vlastní kopii NEMÁ — deleguje na mapper.)
 *
 * PŘI PŘEVÁDĚNÍ DALŠÍCH CEST POZOR: iDoklad i Fakturoid volají `replaceItems`
 * podmíněně (`if (!empty($items))`), tahle služba bezpodmínečně. Na zakládání je to
 * jedno (mazat není co), ale kdyby se stejná cesta použila na ÚPRAVU dokladu,
 * prázdné `items` by tiše smazala všechny položky.
 *
 * POŘADÍ KROKŮ JE VÝZNAMOVÉ, ne náhodné:
 *   1. `createDraft` — hlavička; vrací id, na kterém stojí zbytek,
 *   2. `replaceItems` — položky (sama si na začátku smaže staré),
 *   3. `setVatOverrides` — ruční rekapitulace DPH dle dokladu (§ 73 ZDPH). MUSÍ být
 *      PŘED přepočtem, aby ji kalkulátor zapekl do řádkových totálů; jinak by se
 *      doklad rozešel s papírem dodavatele o haléře,
 *   4. `recompute` — dopočet řádkových i hlavičkových součtů.
 *
 * Tahle třída je ZATÍM čistý přesun beze změny chování — konkrétně **není v transakci**,
 * stejně jako dosud. Obalení transakcí je samostatný krok, aby šlo případnou regresi
 * najít bisectem.
 */
final class PurchaseInvoiceWriteService
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
    ) {}

    /**
     * Založí koncept přijaté faktury i s položkami a přepočtem.
     *
     * Výjimky se ZÁMĚRNĚ nechytají — překládá je volající.
     *
     * POZOR na rozsah překladu u volajícího: dřív obaloval `try/catch` jen krok 1
     * (INSERT hlavičky), takže platilo „HTTP 400 ⇒ v DB nevzniklo nic". Delegací se
     * catch roztáhl přes všechny čtyři kroky. Dnes je to prokazatelně bez dopadu —
     * kroky 2–4 žádnou `InvalidArgumentException` nevyhazují (repozitář ji má jen
     * v `createDraft`/`updateDraft`, kalkulátor hází `RuntimeException`) a jejich
     * `PDOException` neprojde heuristikou na varsymbol, protože `purchase_invoice_items`
     * žádný unikát se slovem „varsymbol" nemá. Až přibude transakce, invariant
     * „chyba ⇒ v DB nevzniklo nic" bude platit pro celou sekvenci sám od sebe.
     *
     * Jméno je ZÁMĚRNĚ jiné než `PurchaseInvoiceRepository::createDraft()` — ta zapisuje
     * jen hlavičku. Kdyby se obě jmenovaly stejně, v diffu ani při merge nepoznáš,
     * která vrstva se volá.
     *
     * @param array<string,mixed> $data payload dokladu včetně klíčů `items` a `vat_overrides`
     * @return int id založeného konceptu
     */
    public function createWithItems(array $data, int $userId, int $supplierId): int
    {
        $id = $this->repo->createDraft($data, $userId, $supplierId);
        $this->applyItemsAndTotals($id, $data, $supplierId);

        return $id;
    }

    /**
     * Kroky 2–4 sekvence. Sdílené, aby budoucí cesta „úprava dokladu" nemusela pořadí
     * (a komentář o § 73) opisovat potřetí.
     *
     * @param array<string,mixed> $data
     */
    private function applyItemsAndTotals(int $id, array $data, int $supplierId): void
    {
        $this->repo->replaceItems($id, (array) ($data['items'] ?? []));

        // Ruční rekapitulace DPH dle dokladu (§ 73) — uložit PŘED recompute, aby ji
        // kalkulátor zapekl do řádkových totálů.
        // `array_key_exists`, ne `isset`: explicitní `null` má rekapitulaci ZRUŠIT.
        if (array_key_exists('vat_overrides', $data)) {
            $this->repo->setVatOverrides(
                $id,
                $supplierId,
                is_array($data['vat_overrides']) ? $data['vat_overrides'] : null,
            );
        }

        $this->calc->recompute($id);
    }
}
