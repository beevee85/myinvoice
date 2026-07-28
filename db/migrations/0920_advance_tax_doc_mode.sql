-- MyInvoice.cz (fork) — režim daňového dokladu k přijaté platbě (DDKPZ) po úhradě zálohy
--
-- Stav před touto migrací: DDKPZ na vydané straně se vystaví ručně (POST
-- /api/invoices/{id}/payments/{paymentId}/tax-document) a navíc automaticky
-- v bankovních cestách při ČÁSTEČNÉ úhradě zálohové faktury (StatementMatcher,
-- BankStatementAction). Při PLNÉ úhradě vzniká místo toho koncept vyúčtovací
-- faktury (FinalFromProformaCreator) — tedy jednodokladový postup dle § 28.
--
-- Co ale nikdo nehlídá: lhůtu § 28 odst. 1 písm. d) a odst. 8 ZDPH — doklad musí
-- být vystaven do 15 dnů ode dne přijetí úplaty. Když uživatel koncept vyúčtovací
-- faktury v té lhůtě nevystaví, povinnost zůstane nesplněná a nic ho neupozorní.
--
-- Volba chování per dodavatele:
--   • 'none'  — nic navíc (volba pro neplátce DPH; automatiky bank cest zůstávají)
--   • 'offer' — VÝCHOZÍ: připomínka v „Akce pro tebe" s termínem = úplata + 15 dnů,
--               dokud k platbě neexistuje DDKPZ ani vystavená vyúčtovací faktura
--   • 'auto'  — navíc se koncept DDKPZ založí rovnou i mimo bankovní cesty
--               (ruční úhrada, „označit jako zaplacenou")
--
-- Proč 'auto' NEzasahuje do plné úhrady: tam vzniká koncept vyúčtovací faktury,
-- jejíž odpočet zálohy se počítá jen z NEkonceptových DDKPZ (FinalFromProformaCreator).
-- Koncept DDKPZ vedle konceptu finálu by tak dal fakturu bez odpočtů § 37a a po
-- vystavení obojího by se táž úplata zdanila dvakrát. U plné úhrady proto zůstává
-- jednodokladový postup a lhůtu hlídá připomínka.
--
-- Výchozí 'offer' je nejmírnější aktivní volba: sama nic nevystaví (doklad vzniká
-- až vědomým krokem uživatele), jen upozorní na běžící zákonnou lhůtu. Neplátcům
-- DPH se připomínka negeneruje ani v režimu 'offer' (řeší aplikační logika podle
-- supplier.is_vat_payer), takže jim není nutné nastavení měnit.
--
-- Číslování: fork-only řada od 0900. Čísla 0912–0919 jsou vědomě ponechána volná
-- pro souběžně vyvíjený dávkový import (viz docs/batch-import/PLAN.md, „nové tabulky
-- dostanou čísla 0912+") — migrace se řadí abecedně podle názvu, mezera nevadí.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS advance_tax_doc_mode ENUM('none','offer','auto')
        NOT NULL DEFAULT 'offer'
        COMMENT 'Po úhradě zálohové faktury: none = nic, offer = připomínka na lhůtu 15 dnů (§ 28/8 ZDPH), auto = založit i koncept DDKPZ'
        AFTER embed_isdoc;
