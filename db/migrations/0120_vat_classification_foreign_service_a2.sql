-- 0120: Přijatá zahraniční služba v reverse charge (§ 9 odst. 1) patří v kontrolním
--        hlášení do oddílu A.2, NE do B.1 (issue #164).
--
-- KH oddíl A.2 = "Přijatá zdanitelná plnění, u kterých je povinen přiznat daň příjemce
-- podle § 108 odst. 1 písm. b) a c) (§ 24, § 25)" — tj. VŠECHNA přeshraniční samovyměřená
-- plnění: přijetí služby od osoby neusazené v tuzemsku (§ 24 — EU i 3. země) a pořízení
-- zboží z JČS (§ 25). Oddíl B.1 je naopak jen pro TUZEMSKÝ režim přenesení (§ 92a–92e),
-- kde portál Moje daně vyžaduje české číselné DIČ dodavatele.
--
-- Seed dosud nesl A.2 jen u kódu 23 (pořízení zboží z JČS). Kódy přijatých služeb
--   • 24  — služba ze 3. země / od neusazené osoby (ř.12), a
--   • 24e — služba z JČS / EU (ř.5),
-- měly kh_section = NULL + is_reverse_charge = 1. KontrolniHlaseniBuilder::collectSections()
-- proto (A.2 = false, RC = true) směroval oba kódy do B.1 → VetaB1 s ořezaným/neplatným
-- DIČ a kódem tuzemského PDP, což portál odmítne.
--
-- Náprava: přiřadit kódům 24 a 24e kh_section = 'A.2'. kh_section čte VatLedgerService
-- živě podle kódu, takže oprava se okamžitě promítne i do již zaúčtovaných dokladů
-- (žádný per-doklad backfill). DPHDP3 (řádky 5/12 + zrcadlo 43) se nemění — řídí se
-- dphdp3_line, ne kh_section.
--
-- POZN. kód 25 (dovoz zboží ze 3. země, ř.7/8) ZŮSTÁVÁ kh_section = NULL — dovoz zboží
-- se do KH nevykazuje (jen DPHDP3 ř.7/8 + odpočet ř.43/44); jeho vyloučení z B.1 řeší
-- úprava routeru v KontrolniHlaseniBuilder.
--
-- Idempotentní: UPDATE jen tam, kde sekce ještě není A.2. Sahá pouze na globální seed
-- (supplier_id IS NULL).

SET NAMES utf8mb4;

UPDATE vat_classifications
   SET kh_section = 'A.2'
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code IN ('24', '24e')
   AND (kh_section IS NULL OR kh_section <> 'A.2');
