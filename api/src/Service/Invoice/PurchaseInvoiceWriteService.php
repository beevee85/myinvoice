<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Log\LoggerInterface;

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
 * CELÁ SEKVENCE BĚŽÍ V JEDNÉ TRANSAKCI. Dřív jel každý krok v autocommitu, takže pád
 * uprostřed nechal v databázi trvale rozpracovaný doklad — hlavičku s nulovými součty
 * a osiřelé položky bez přepočtu. Účetní doklad je buď celý, nebo žádný.
 *
 * Transakce je RE-ENTRANTNÍ (`$started = !$pdo->inTransaction()`): když už volající
 * transakci drží, služba si vlastní nezakládá a nechá commit na něm. Je to nutné —
 * `PurchaseSettlementService` (vyúčtování záloh, § 37a) volá `setVatOverrides`
 * i `recompute` uvnitř své transakce a vlastní `beginTransaction()` by ji rozbil.
 * Stejný vzor používá i `FinalFromProformaCreator`.
 */
final class PurchaseInvoiceWriteService
{
    /** Prefix hlášky ve stínovém režimu — jediný záchytný bod pro agregaci z logu. */
    public const SHADOW_LOG_PREFIX = 'shadow-validation:';

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Založí koncept přijaté faktury i s položkami a přepočtem.
     *
     * Buď projde celá sekvence, nebo se nezapíše nic — viz transakce níže.
     *
     * Výjimky se ZÁMĚRNĚ nechytají, jen se po nich vrátí transakce zpět; překládá je
     * volající (`InvalidArgumentException` → 400, kolize varsymbolu → 409). Díky
     * transakci teď platí i pro kroky 2–4 invariant „chybová odpověď ⇒ v DB nevzniklo nic",
     * který dřív garantoval jen INSERT hlavičky.
     *
     * Jméno je ZÁMĚRNĚ jiné než `PurchaseInvoiceRepository::createDraft()` — ta zapisuje
     * jen hlavičku. Kdyby se obě jmenovaly stejně, v diffu ani při merge nepoznáš,
     * která vrstva se volá.
     *
     * @param array<string,mixed> $data payload dokladu včetně klíčů `items` a `vat_overrides`
     * @param string $source odkud zápis přišel — jen pro stínovou validaci (viz `recordShadowValidation`)
     * @return int id založeného konceptu
     */
    public function createWithItems(array $data, int $userId, int $supplierId, string $source = 'unknown'): int
    {
        $id = $this->inTransaction(function () use ($data, $userId, $supplierId): int {
            $newId = $this->repo->createDraft($data, $userId, $supplierId);
            $this->applyItemsAndTotals($newId, $data, $supplierId);

            return $newId;
        });

        // AŽ PO commitu: záznam odkazuje na id dokladu, takže musí existovat. Kdyby
        // se logovalo uvnitř transakce, ukazovaly by nálezy po rollbacku do prázdna.
        $this->recordShadowValidation($data, $supplierId, $id, $source);

        return $id;
    }

    /**
     * STÍNOVÝ REŽIM (pravidlo V76) — spustí sdílenou validaci a jen ZAZNAMENÁ nálezy.
     * Nic neodmítne a chování zápisu nijak neovlivní.
     *
     * PROČ: `PurchaseInvoiceValidation::invoice()` dnes hlídá jen ruční pořízení; importní
     * cesty ji nikdy nevolaly a mají vlastní, slabší kontroly. Než se validace na importy
     * vynutí, potřebujeme vědět, KOLIK dnešních dokladů by neprošlo a proč — vynutit ji
     * naslepo by mohlo zablokovat běžný provoz.
     *
     * Zapisuje se jen do logu (žádná tabulka, žádná migrace) a jen když nálezy JSOU.
     * Jmenovatel pro procenta viz `docs/batch-import/SHADOW-VALIDATION.md`.
     *
     * ⚠️ DO LOGU NESMÍ TÉCT OBSAH DOKLADU. Logy se rotují, kopírují a někdy posílají
     * do agregátorů — je to jiná bezpečnostní zóna než databáze. Záznam proto nese jen
     * METADATA: identifikátor pravidla (normalizovaná cesta k poli), severity, typ
     * dokladu, zdroj zápisu, tenanta a id dokladu pro dohledání. Žádné částky, IČO,
     * názvy firem, jména ani čísla dokladů. Hlídá to `ShadowValidationTest`.
     *
     * Selhání záznamu nesmí shodit zápis dokladu — telemetrie není důležitější než data.
     *
     * @param array<string,mixed> $data
     */
    private function recordShadowValidation(array $data, int $supplierId, int $purchaseInvoiceId, string $source): void
    {
        try {
            $errors = PurchaseInvoiceValidation::invoice($data, $this->repo->vatRateMap());
            if ($errors === []) {
                return;
            }

            $this->logger->warning(
                self::SHADOW_LOG_PREFIX . ' doklad by neprošel sdílenou validací (zápis proběhl)',
                [
                    'source'              => $source,
                    'supplier_id'         => $supplierId,
                    'purchase_invoice_id' => $purchaseInvoiceId,
                    'document_kind'       => (string) ($data['document_kind'] ?? 'invoice'),
                    'severity'            => 'error',
                    'rules'               => self::ruleIds($errors),
                    'rule_count'          => count($errors),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(self::SHADOW_LOG_PREFIX . ' stínovou validaci se nepodařilo provést', [
                'source'              => $source,
                'supplier_id'         => $supplierId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'error_class'         => $e::class,
            ]);
        }
    }

    /**
     * Identifikátor pravidla = cesta k poli s vynulovaným indexem řádku.
     * `items.3.quantity` → `items.*.quantity`, aby šly nálezy agregovat napříč doklady.
     *
     * Texty hlášek se do logu ZÁMĚRNĚ nedávají — na agregaci nejsou potřeba a jen by
     * zvětšovaly plochu, kudy by mohla uniknout hodnota z dokladu.
     *
     * @param array<string,string[]> $errors
     * @return list<string>
     */
    public static function ruleIds(array $errors): array
    {
        $rules = array_map(
            static fn (string $field): string => (string) preg_replace('/\.\d+\./', '.*.', $field),
            array_keys($errors),
        );
        $rules = array_values(array_unique($rules));
        sort($rules);

        return $rules;
    }

    /**
     * Spustí callback v transakci — a to jen tehdy, když ji ještě nikdo nedrží.
     *
     * Vnořené volání (typicky z `PurchaseSettlementService`, který si transakci otevírá
     * sám) commit ani rollback NEDĚLÁ; nechá rozhodnutí na vlastníkovi transakce.
     * Bez toho by vnitřní `commit()` předčasně potvrdil cizí rozdělanou práci.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $pdo     = $this->db->pdo();
        $started = !$pdo->inTransaction();

        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $result = $work();

            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            // `inTransaction()` znovu: některé chyby (deadlock, ztráta spojení) transakci
            // ukončí samy a `rollBack()` by pak hodil vlastní výjimku přes tu původní.
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
