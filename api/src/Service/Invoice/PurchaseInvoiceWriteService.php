<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * FORK (beevee85) — sdílená zapisovací sekvence přijaté faktury.
 *
 * Sekvenci `createDraft → replaceItems → vat_overrides → recompute` si dosud skládal
 * každý volající sám: ruční akce, AI import, ISDOC mapper i scan-inbox. Čtyři kopie
 * téhož znamenaly, že oprava v jedné z nich se do ostatních nedostala (viz rozdíly
 * zafixované charakterizačními testy).
 *
 * STAV MIGRACE: zatím sem deleguje jen ruční cesta (`CreatePurchaseInvoiceAction`).
 * Importní cesty a úprava dokladu si sekvenci pořád skládají samy — jejich převedení
 * je samostatný krok, aby šla případná regrese najít bisectem.
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
     * @param array<string,mixed> $data payload dokladu včetně klíčů `items` a `vat_overrides`
     * @return int id založeného konceptu
     */
    public function createDraft(array $data, int $userId, int $supplierId): int
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
