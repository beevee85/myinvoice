# 40 · Karty návrhů

Seřazeno podle priority (Must → Should → Could → Won't). Persona: ✅✅ hlavní přínos,
✅ přínos, 🟡 okrajový. Klasifikace: **[LEG]** legislativní povinnost ČR ·
**[STD]** konkurenční standard (mají všichni tři) · **[2/3]** mají dva ze tří ·
**[NAD]** nadstandard (má max. jeden, byť třeba jen částečně).
Náročnost: S (hodiny–den), M (dny), L (týden+), XL (více týdnů).

Pozn.: karty respektují povahu projektu — self-hosted, MIT, bez povinných SaaS závislostí.
Vše síťové je navrženo jako volitelný modul s konfigurací.

---

### N-001 · Adopce hotových funkcí (provozní balíček, bez vývoje)

- **Doména:** průřezová
- **Persona:** Uživatel ✅✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** instance nevyužívá hotové funkce: banka (0 výpisů, 0 avíz),
  ceník (0 položek), pravidelné fakturace (0), e-mailové odesílací profily (0), účet pro účetní
  (žádný `accountant`/`readonly` uživatel), kategorie nákladů/tržeb (0); BEKRON nemá vyplněné
  zdaňovací období DPH, EUR měna nemá účet. Zdroj: DB instance, viz [50_fork_odchylky.md](50_fork_odchylky.md) § 5.
- **Jak to řeší konkurence:** SaaS konkurence tenhle problém řeší onboardingem (Fakturoid
  „Objevte": https://www.fakturoid.cz/podpora/nastaveni/obrazovka-objevte) — u nás to krátkodobě
  nahradí ruční konfigurační seance.
- **Proč to vadí:** největší část gapu vůči konkurenci je „vypnuto", ne „neexistuje" — ruční
  párování plateb, ruční psaní položek, účetní bez přístupu.
- **Návrh řešení:** konfigurační seance (žádný kód): 1) doplnit `vat_period` BEKRON; 2) založit
  účet účetní (role accountant + omezení na firmy dle dohody); 3) zapnout příjem výpisů Fio
  (GPC upload, do doby N-003); 4) naplnit ceník opakovanými položkami obou firem; 5) založit
  šablony pravidelné fakturace, kde dává smysl; 6) zvážit zapnutí poděkování za platbu;
  7) doplnit EUR účet.
- **Dopad na datový model:** žádný.
- **Kam v UI:** stávající obrazovky (Nastavení, Číselníky, Ceník, Uživatelé, Banka).
- **Náročnost:** S
- **Priorita:** **Must** — nejvyšší poměr přínos/náklad v celé analýze; odblokuje persony A i B.
- **Rizika a co nerozbít:** přístup účetní omezit na správné firmy (`user_supplier_access`);
  u výpisů nezapomenout na formát Fio GPC.
- **Akceptační kritéria:**
  - [ ] BEKRON má vyplněné zdaňovací období a kompletní EPO identitu
  - [ ] existuje aktivní účet role `accountant` s omezením na dohodnuté firmy a zapnutým 2FA
  - [ ] v Bance je aspoň jeden naimportovaný výpis a spárovaná platba
  - [ ] ceník obsahuje aspoň 5 reálně používaných položek na firmu


---

### N-002 · Automatický DDKPZ po úhradě zálohové faktury + hlídání 15denní lhůty **[LEG]**

- **Doména:** Prodej / DPH
- **Persona:** Uživatel ✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** DDKPZ na vydané straně existuje, ale jen jako ruční akce nad
  evidovanou platbou (`POST /api/invoices/{id}/payments/{paymentId}/tax-document`,
  `invoice_type='tax_document'`). Nic nehlídá lhůtu 15 dnů od přijetí úplaty (§ 28 odst. 1
  písm. d) a odst. 8 ZDPH), nic doklad nenabídne automaticky. Rozhodovací strom je hotový
  v [docs/dph-zalohy.md](../dph-zalohy.md).
- **Jak to řeší konkurence:** Fakturoid — 4 režimy zálohovky, DDKPZ vzniká automaticky ke každé
  platbě (https://www.fakturoid.cz/podpora/faktury/danovy-doklad-k-platbe) · Vyfakturuj — auto
  vystavení po úhradě (https://podpora.redbit.cz/navod/typy-dokladu-v-systemu-vyfakturuj-cz-a-jak-s-nimi-pracovat/) ·
  iDoklad — ručně, ale hlídá 15denní lhůtu s notifikací 3 dny předem
  (https://www.idoklad.cz/podpora/danove-doklady-k-platbe).
- **Proč to vadí:** zákonná povinnost plátce; nevystavený DDKPZ = riziko pokuty a problém
  odběratele s odpočtem (§ 73 odst. 1 — bez dokladu nemá nárok). Dnes závisí na tom, že si
  uživatel vzpomene na ruční akci.
- **Návrh řešení:** per-supplier nastavení chování zálohovky po úhradě (à la Fakturoid):
  a) nic (dnešní stav), b) nabídnout DDKPZ (výchozí — draft + notifikace), c) vystavit DDKPZ
  automaticky. + CRM action item „DDKPZ do X dnů" pro každou platbu zálohovky bez navázaného
  daňového dokladu, s eskalací barvy podle zbývající lhůty (pozor: lhůta § 28 odst. 8 je
  15 **kalendářních** dnů — `CzechWorkingDays` zde na rozdíl od termínů podání nepoužívat).
  Výjimky dle rozhodovacího stromu: RC režim
  a plnění uskutečněné do 15 dnů (vyúčtovací faktura vystavená v lhůtě) action item zavírají.
- **Dopad na datový model:** malý — příznak nastavení na `supplier`
  (`advance_tax_doc_mode ENUM('none','offer','auto')`), vazba platba→tax_document už existuje.
- **Kam v UI:** Nastavení → Prodej (režim); detail faktury/platby (tlačítko + stav); dashboard
  „Akce pro tebe" (lhůta).
- **Náročnost:** M
- **Priorita:** **Must** — jediná legislativní mezera nalezená v analýze; konkurence ji má
  vyřešenou celá.
- **Rizika a co nerozbít:** nevystavovat DDKPZ u RC záloh a u plnění bez dostatečné určitosti
  (§ 20a odst. 2) — proto výchozí režim „nabídnout", ne „auto"; nekolidovat s § 37a logikou
  vyúčtování (issue-final); číselná řada tax_documentů.
- **Akceptační kritéria:**
  - [ ] platba zaevidovaná k proformě v režimu „nabídnout" vytvoří action item s termínem +15 dnů
  - [ ] v režimu „auto" vznikne draft DDKPZ s DPPD = datum přijetí platby
  - [ ] u zálohy s RC klasifikací se DDKPZ nenabízí
  - [ ] vystavení vyúčtovací faktury v 15denní lhůtě action item uzavře
  - [ ] DDKPZ vstupuje do DP3 (ř. 1/2) a KH (A.4/A.5) v období přijetí platby — kryto testy


---

### N-003 · Napojení Fio banky (avíza + API fetcher) **[STD]**

- **Doména:** Finance / Banka
- **Persona:** Uživatel ✅✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** párování existuje (GPC/PDF výpisy, IMAP avíza s regex parsery,
  auto-match VS+částka, M-24/M-37), ale přímé API žádné banky není a v seedu avíz chybí Fio —
  banka obou firem. Reálně: 0 transakcí v systému, platby se klikají ručně.
- **Jak to řeší konkurence:** Fakturoid — Fio API přímo
  (https://www.fakturoid.cz/podpora/parovani/fio-api) + 12 bank přes avíza · Vyfakturuj — Fio
  API v tarifu Profi (https://www.vyfakturuj.cz/cenik/) · iDoklad — 13 bank přes e-mailové
  notifikace (https://www.idoklad.cz/podpora/nastaveni-banka).
- **Proč to vadí:** TM-A4/TM-B3 — nejbolestivější třecí místo cyklu; bez věrohodných úhrad
  nefunguje dobře nic navazujícího (upomínky, CRM, podklady účetní).
- **Návrh řešení:** dvě fáze. **(a)** přidat provider e-mailových avíz Fio do číselníku parserů
  (regex, vzor ČS/MONETA) — jen konfigurace/seed. **(b)** volitelný modul `FioApiFetcher`:
  osobní token v Nastavení → Banka (šifrovaně jako ostatní credentials), cron stahuje
  `/ib_api/rest/last/` transakce do `bank_transactions` (source: nový enum `api`), navazuje
  existující auto-matching. Žádná nová závislost (REST+JSON, curl). Kandidát pro upstream
  (viz IMPROVEMENTS.md).
- **Dopad na datový model:** enum `bank_transactions.source` += `'api'`; tabulka/sloupce pro
  token per účet (šifrovaný, vzor `anthropic_api_key_enc`).
- **Kam v UI:** Nastavení → Bankovní účty (token + test spojení); Banka (transakce se objeví
  ve stávajícím UI).
- **Náročnost:** (a) S · (b) M
- **Priorita:** **Must** (a) / **Should** (b) — (a) zprovozní párování hned, (b) odstraní
  závislost na e-mailech.
- **Rizika a co nerozbít:** Fio API limit (30 s mezi dotazy, token expiruje); nekolidovat
  s deduplikací transakcí (source_ref); merge s upstreamem — poslat jako PR.
- **Akceptační kritéria:**
  - [ ] příchozí platba na Fio účet se do systému dostane bez ručního zásahu (avízo nebo API)
  - [ ] platba s VS existující faktury fakturu automaticky označí (auto_exact)
  - [ ] token je uložen šifrovaně a lze ho otestovat tlačítkem
  - [ ] výpadek API/avíz neshodí cron (log + retry)


---

### N-008 · Dokončit DDKPZ na přijaté straně (rozdělaná migrace 0904) **[LEG]** — ✅ **HOTOVO 28. 7. 2026**

> **Uzavřeno týž den večer.** Dokončeno ve třech dávkách (commity 6506ca72 → db8dc163, migrace
> 0904, 0906, 0907, 0909, 0910, 0911): typ `tax_document`, § 37a vyúčtování s korektním
> zaokrouhlením (viditelný řádek „Zaokrouhlení § 37a"), sazba CZ-NA „Mimo DPH" pro zálohy mimo
> výkazy, invariant znamének, vyloučení záloh z nákladových agregací, opravy exportů (Pohoda:
> DIČ + evidenční číslo dodavatele + platební VS; ISDOC: `TaxedDeposits`/`AlreadyClaimed`),
> blokující rozpor v AI extrakci, CLI přepočet. **V provozu: 4 DDKPZ u BEKRONu.** Akceptační
> kritéria níže byla splněna a rozšířena; detaily v `CUSTOMIZATIONS.md` (3 dávky 2026-07-28).
> Zbývá jen provozní ověření před nabídnutím upstreamu (po podání KH za 05–07/2026).

Původní zadání karty (ponecháno pro dohledatelnost):

- **Doména:** Nákup / DPH
- **Persona:** Uživatel 🟡 · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** rozpracováno v pracovní kopii (nezacommitováno): migrace
  `0904_purchase_tax_document.sql` (document_kind `tax_document`, vazba na vyúčtovací fakturu,
  § 37a odpočtové řádky), `PurchaseSettlementService`, link/unlink akce, úpravy AI extrakce.
  Bez toho se přijatý DDKPZ ukládá jako běžná faktura → dvojí náklad a špatné období odpočtu.
- **Jak to řeší konkurence:** iDoklad sestavuje DPH přiznání i z DDKP
  (https://www.idoklad.cz/podpora/finance-dph); Vyfakturuj/Fakturoid přijatou stranu takto
  strukturovaně neřeší (evidují jen náklad) — tady fork míří NAD konkurenci.
- **Proč to vadí:** nárok na odpočet ze zaplacené zálohy vzniká jen s DDKPZ (§ 72 odst. 5,
  § 73 odst. 1) a konečná faktura vstupuje do DPH/KH jen rozdílem (§ 37a) — bez datové opory
  vzniká dvojí odpočet nebo odpočet ve špatném období. Instance má 17 přijatých záloh.
- **Návrh řešení:** dokončit rozdělanou větev dle [docs/dph-zalohy.md](../dph-zalohy.md)
  (rozhodovací strom je hotový), dopsat testy KH B.2/B.3 s limitem z |rozdílu| a nasadit.
- **Dopad na datový model:** migrace 0904 (už napsaná, idempotentní).
- **Kam v UI:** editor/detail přijaté faktury (druh dokladu, vazby), seznam s typem.
- **Náročnost:** M (zbývající část)
- **Priorita:** **Must** — legislativní korektnost odpočtů; práce je z většiny hotová.
- **Rizika a co nerozbít:** zpětná kompatibilita agregací nákladů (NOT EXISTS predikáty přes
  `advance_purchase_invoice_id`); nezdvojit náklad při vyúčtování; koordinovat s rozdělanou
  sessions — **nezasahovat do pracovní kopie souběžně**.
- **Akceptační kritéria:** (převzít z rozdělané větve; minimálně)
  - [ ] přijatý DDKPZ vstupuje do B.2/B.3 v období dle GREATEST(tax_date, issue_date)
  - [ ] konečná faktura s vazbou na DDKPZ vstupuje do výkazů jen rozdílem (§ 37a)
  - [ ] smazání konečné faktury neodstraní DDKPZ (ON DELETE SET NULL)
  - [ ] nákladové agregace zálohu s navázaným DDKPZ nezapočítávají dvakrát


---

### N-004 · Samoobslužný přístup pro účetní (pozvánka + průvodce) **[STD]**

- **Doména:** Daně a účetní / Systém
- **Persona:** Uživatel ✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** role `accountant`/`readonly` + fork omezení na firmy fungují,
  ale účet zakládá admin ručně vč. hesla (M-36); žádná pozvánka, žádný „první přihlášení"
  onboarding pro účetní.
- **Jak to řeší konkurence:** Fakturoid — pozvánka e-mailem s platností 10 dní, role účetní
  zdarma (https://www.fakturoid.cz/podpora/pro-ucetni/pristup-pro-ucetni) · Vyfakturuj —
  pozvánka 7 dní (https://podpora.redbit.cz/navod/ucetni-a-uzivatele/) · iDoklad — přidání
  uživatele s vlastním loginem (https://www.idoklad.cz/vlastnosti/doklady-pro-ucetni).
- **Proč to vadí:** TM-B1 — tření při zakládání je jeden z důvodů, proč účetní přístup dodnes
  nemá; admin nemá znát heslo účetní.
- **Návrh řešení:** tlačítko „Pozvat uživatele" (e-mail + role + povolené firmy) → token
  s expirací (vzor: password_resets), účetní si nastaví heslo + 2FA sama. Do manuálu kapitola
  „Průvodce pro účetní" (co role smí, kde jsou exporty a výkazy).
- **Dopad na datový model:** tabulka pozvánek (token, e-mail, role, supplier_ids, expires_at)
  nebo rozšíření password_resets.
- **Kam v UI:** Systém → Uživatelé (Pozvat); veřejná stránka přijetí pozvánky.
- **Náročnost:** M
- **Priorita:** **Should** — není Must, protože krátkodobě účet založí admin ručně (N-001);
  Should proto, že bez samoobsluhy se přístup účetní historicky vůbec nezřídil.
- **Rizika a co nerozbít:** bezpečnost tokenu (jednorázový, expirace, rate limit);
  `require_totp` musí platit i pro pozvané; SupplierScopeMiddleware.
- **Akceptační kritéria:**
  - [ ] pozvánka odejde e-mailem a po expiraci je neplatná
  - [ ] pozvaný účet dostane jen zvolenou roli a firmy; ostatní data nevidí (403)
  - [ ] admin nikdy nezná heslo účetní; 2FA vynuceno při prvním přihlášení


---

### N-005 · Zamykání období + hlídání změn v uzavřeném období **[NAD]** (plně jen Fakturoid; iDoklad částečně)

- **Doména:** Daně a účetní
- **Persona:** Uživatel ✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** vystavené doklady jsou immutable a fork má zámek jednotlivého
  dokladu s auditovaným odemčením — ale nic nebrání přidat/změnit doklad v období, za které už
  je podané přiznání, a nikdo se o tom nedozví. Uzávěrka ani zámek období neexistují (M-29
  mlčí; potvrzeno kódem).
- **Jak to řeší konkurence:** Fakturoid — zámky dokladů, hromadně při exportu, režim „zamykat
  smí jen účetní", notifikace při odemčení
  (https://www.fakturoid.cz/podpora/ucetnictvi/zamykani-dokladu) · iDoklad — zákaz úprav
  dokladů po exportu (https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy) ·
  Vyfakturuj — nemá (❌).
- **Proč to vadí:** TM-B4 — účetní nemůže věřit, že se předané období zpětně nezměnilo;
  dodatečná podání (která fork jako jediný umí vygenerovat) dnes nemají spouštěč „něco se
  změnilo v uzavřeném období".
- **Návrh řešení:** per-supplier zámek období (datum „uzavřeno do"; typicky se posouvá po
  podání DP3). Mutace dokladu s daňovým datem ≤ zámek → 409 s vysvětlením; admin override =
  existující force-edit flow (modal + audit). Nový doklad do uzavřeného období → varování +
  audit záznam + notifikace/action item „změna v uzavřeném období → zvaž dodatečné přiznání"
  (propojit s formou podání N/D). Nastavovat smí admin a účetní.
- **Dopad na datový model:** `supplier.vat_locked_until DATE NULL` (příp. tabulka historie
  zámků s uživatelem a časem).
- **Kam v UI:** stránka výkazů DPH (tlačítko „Uzavřít období po podání"), Nastavení; badge
  na dokladech v uzavřeném období.
- **Náročnost:** M–L
- **Priorita:** **Should** — spolu s N-006 jádro důvěryhodnosti pro personu B.
- **Rizika a co nerozbít:** nesmí rozbít párování plateb k dokladům v uzavřeném období
  (platba není mutace dokladu — po vzoru Fakturoidu zámek platby propouští); recurring cron
  nesmí generovat do minulosti; force-edit audit zachovat.
- **Akceptační kritéria:**
  - [ ] po uzavření období vrací editace dokladu s DUZP v období 409 s odkazem na odemčení
  - [ ] vytvoření dokladu do uzavřeného období vygeneruje action item s odkazem na dodatečné podání
  - [ ] platby lze párovat i na doklady v uzavřeném období
  - [ ] každé odemčení/uzavření je v audit logu s uživatelem


---

### N-006 · „Balíček období" — stav předání podkladů a kontrola úplnosti **[NAD]**

- **Doména:** Daně a účetní / Exporty
- **Persona:** Uživatel ✅ · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** hromadný export ZIP za měsíc/kvartál existuje (M-34) a je
  nejlepší na trhu, ale je bezstavový: nepamatuje si, co bylo předáno, nekontroluje úplnost
  a nemá adresáta.
- **Jak to řeší konkurence:** Fakturoid — sloupec „Poslední export" per klient a předvyplněné
  exporty (https://www.fakturoid.cz/podpora/pro-ucetni/fakturoid-pro-ucetni-funkce), kontrola
  povinných údajů při exportu (https://www.fakturoid.cz/podpora/pro-ucetni/exporty) · iDoklad —
  příznak přenesených dokladů a ikona změny po exportu
  (https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy). Plné workflow „chybí mi
  doklad" nemá nikdo — tady lze konkurenci přeskočit.
- **Proč to vadí:** TM-B2/TM-B7 — kontrola úplnosti a stav předání běží v hlavě účetní
  a v e-mailech; „nechce po mně ručně dohledávat chybějící doklady" je přímo zadání persony.
- **Návrh řešení:** nad hromadným exportem stavová vrstva „období": checklist úplnosti před
  exportem (nespárované platby, koncepty, přijaté bez příloh/DIČ, mezery v číselné řadě,
  nevystavené DDKPZ z N-002), stav období (otevřené → podklady připravené → předáno →
  zaúčtováno/zamčeno — návaznost na N-005), log „kdo kdy co exportoval" a tlačítko „Vyžádat
  doklad" (e-mail klientovi/majiteli s popisem chybějícího dokladu a odkazem na upload —
  navázat na N-007). Notifikace účetní při novém dokladu v už předaném období.
- **Dopad na datový model:** tabulka `period_handover` (supplier_id, period, stav, exported_at,
  exported_by, poznámka); žádost o doklad jako typ action itemu.
- **Kam v UI:** Hromadný export (rozšířit na „Podklady pro účetní"), dashboard účetní.
- **Náročnost:** L
- **Priorita:** **Should** — největší kus „modelu spolupráce s účetní"; stavět po N-001/N-004/N-005.
- **Rizika a co nerozbít:** nekomplikovat cestu uživatelům bez účetní (vrstva je volitelná);
  neduplikovat CRM action items — rozšířit je.
- **Akceptační kritéria:**
  - [ ] před exportem období vidím checklist úplnosti s proklikem na každý problém
  - [ ] po exportu je období označené „předáno" s časem, uživatelem a obsahem
  - [ ] nový/změněný doklad v předaném období vytvoří notifikaci pro účetní
  - [ ] „Vyžádat doklad" odešle e-mail a vytvoří sledovatelný úkol, který jde uzavřít


---

### N-007 · E-mailový inbox pro doklady **[2/3]** (iDoklad + Fakturoid; Vyfakturuj nemá)

- **Doména:** Nákup
- **Persona:** Uživatel ✅✅ · Účetní ✅
- **Jak je to dnes v MyInvoice:** „inbox" = sledovaný adresář na disku (M-17) — na serveru bez
  dalších nástrojů do něj běžný uživatel nic nedostane; cron ho skenuje naprázdno. E-mailem
  přijde většina dokladů dnes.
- **Jak to řeší konkurence:** iDoklad — unikátní e-mail adresa agendy, doklady tam posílá
  i dodavatel či účetní (https://www.idoklad.cz/podpora/inbox) · Fakturoid — Krabice
  s vlastní adresou + ranní souhrn (https://www.fakturoid.cz/podpora/naklady/krabice-na-naklady) ·
  Vyfakturuj — nemá (❌).
- **Proč to vadí:** každý přijatý doklad dnes vyžaduje: stáhnout z pošty → nahrát ručně;
  to je denní tření persony A a vstupní brána podkladů pro N-006.
- **Návrh řešení:** IMAP poller (infrastruktura existuje — bank-email-notices i e-mail profily
  mají IMAP klienta): vyhrazená schránka (např. doklady@…), cron stáhne přílohy
  (PDF/ISDOC/obrázky) do stávajícího inbox adresáře → existující pipeline (SHA-256 dedup,
  AI extrakce) je zpracuje; e-mail poznámkou do dokladu. Volitelně adresa per dodavatel firmy.
  Bez nové závislosti; schránku si hostuje uživatel (self-hosted duch).
- **Dopad na datový model:** konfigurace IMAP schránky (vzor `bank_email_imap_settings`),
  vazba zdrojového e-mailu na vzniklý doklad.
- **Kam v UI:** Nastavení → Přijaté doklady (schránka + test); seznam přijatých (zdroj
  „e-mail").
- **Náročnost:** M
- **Priorita:** **Should** — denní úspora práce obou person, ale existuje ruční obejití
  (upload v UI), takže ne Must.
- **Rizika a co nerozbít:** bezpečnost (jen přílohy povolených typů, limit velikosti, žádné
  spouštění); dedup proti ručnímu uploadu; neskenovat cizí složky.
- **Akceptační kritéria:**
  - [ ] PDF poslané na schránku se do 10 minut objeví jako koncept přijaté faktury s AI extrakcí
  - [ ] duplicitní příloha (stejný SHA-256) doklad nezdvojí
  - [ ] nepodporovaná příloha skončí v Dokumentech/karanténě s notifikací, ne tichým zahozením


---

### N-011 · Onboarding checklist + kontextová nápověda **[2/3]** (iDoklad + Fakturoid; u Vyfakturuj in-app nedoloženo)

- **Doména:** Systém a UX
- **Persona:** Uživatel ✅✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** plný manuál (42 kapitol, generovaný z `manual/*.md`) je na
  `/manual` (nginx → `manual/index.php`) a odkázaný z menu, ale z obrazovek na něj nevede
  žádný kontextový odkaz; po setup wizardu (M-06) nic neukazuje, co si zapnout (viz N-001 —
  důsledky). Premisa zadání „/help vrací 404" neplatí, ale kontextová nápověda chybí reálně.
- **Jak to řeší konkurence:** Fakturoid — obrazovka „Objevte" s nevyužitými funkcemi
  (https://www.fakturoid.cz/podpora/nastaveni/obrazovka-objevte) · iDoklad — rychlé tipy
  a nápověda z karet (https://www.idoklad.cz/podpora/rychle-tipy-launchpad) · Vyfakturuj —
  checklist v nápovědě (https://podpora.redbit.cz/navod/co-si-nastavit-nez-zacnete-fakturovat/).
- **Proč to vadí:** TM-A1 — funkce bez objevitelnosti neexistují; manuál má 42 kapitol, ale
  uživatel neví, která patří k obrazovce, na které stojí.
- **Návrh řešení:** 1) ikona „?" v hlavičce každé stránky → příslušná kapitola `/manual`
  (statická mapa routa→kapitola, ~40 položek); 2) karta „Objevte" na dashboardu: detekce
  nevyužitých funkcí z dat (0 položek ceníku, 0 výpisů, 0 šablon, vypnuté avízo…) s proklikem
  a možností skrýt. Bez externích služeb.
- **Dopad na datový model:** jen per-user dismissed tipy (vzor crm_action_item_dismissals).
- **Kam v UI:** hlavička stránek; dashboard.
- **Náročnost:** M (S pro samotné „?" odkazy)
- **Priorita:** **Should** — systémová prevence TM-A1 (nevyužité funkce); ne Must, protože
  jednorázově to za instanci vyřeší N-001.
- **Rizika a co nerozbít:** mapa kapitol se musí udržovat při přejmenování rout (test na
  existenci kotev); neotravovat — tipy musí jít trvale skrýt.
- **Akceptační kritéria:**
  - [ ] každá hlavní stránka má „?" vedoucí na správnou kapitolu manuálu
  - [ ] dashboard ukazuje max. 3 relevantní tipy odvozené z reálných dat instance
  - [ ] skrytý tip se nevrací


---

### N-020 · Dopočet rozdílů pro dodatečné přiznání DPH (§ 141 odst. 2 DŘ) **[NAD]**

> Karta doplněna 28. 7. večer — reaguje na změnu, která nastala až po ranní analýze.

- **Doména:** Daně a účetní
- **Persona:** Uživatel 🟡 · Účetní ✅✅
- **Jak je to dnes v MyInvoice:** formy podání pro DP3 byly zúženy na **B/O**; dodatečné formy
  **D/E jsou vypnuté** (commit 247867e4, `ReportFormParams` + `DphPriznaniBuilder`), protože
  builder pro ně generoval plné částky období místo rozdílu proti poslední známé dani — uživatel
  by jedním klikem stáhl věcně špatné XML ve svůj neprospěch. **Následné KH (N/E) funguje**,
  protože se podává kompletní. Detaily [50_fork_odchylky.md](50_fork_odchylky.md) § 2.2.
- **Jak to řeší konkurence:** nikdo — Fakturoid výslovně odkazuje na ruční opravu na portálu
  (https://www.fakturoid.cz/podpora/ucetnictvi/priznani-k-dph), iDoklad a Vyfakturuj to
  v nápovědě neřeší (❔). Jde tedy o **nadstandard**, ne o dohánění.
- **Proč to vadí:** oprava chyby v už podaném přiznání je běžná situace (typicky pozdě dodaný
  doklad); dnes ji účetní musí spočítat ručně a vyplnit na EPO. Zároveň jde o funkci, kterou
  analýza ráno uváděla jako naši konkurenční výhodu — dokud nebude dopočet hotový, výhoda neplatí.
- **Návrh řešení:** k období evidovat **poslední známou daň** (archiv podání `tax_submissions`
  už XML i souhrny obsahuje — dopočítat z posledního řádného/dodatečného podání za totéž období),
  builder pro D/E plnit **rozdíly** jednotlivých řádků (nová hodnota − poslední známá) a doplnit
  `d_zjist`. Náhled musí vedle sebe ukázat: poslední známou daň, novou daň a rozdíl, který se
  odesílá. Bez uzavřeného archivu podání za období formu nenabízet.
- **Dopad na datový model:** pravděpodobně žádné nové tabulky — využít `tax_submissions`
  (form_code, období, `summary_json`); případně doplnit sloupec s rozpisem řádků pro rychlé
  porovnání.
- **Kam v UI:** stránka DPH přiznání — selectbox „Forma podání" (vrátit volby D/E) + srovnávací
  panel v náhledu.
- **Náročnost:** M
- **Priorita:** **Should** — zákonnou povinnost lze splnit na EPO, takže ne Must; ale je to
  dluh vzniklý vypnutím funkce a zároveň příležitost mít něco, co nemá nikdo z konkurence.
- **Rizika a co nerozbít:** nikdy negenerovat D/E bez doloženého předchozího podání; nedotknout
  se řádných forem B/O ani následného KH; hlídat znaménka rozdílů (záporná daň = vratka);
  pokrýt testy scénář „dvě dodatečná přiznání po sobě" (druhé se počítá proti prvnímu).
- **Akceptační kritéria:**
  - [ ] dodatečné DP3 obsahuje rozdílové částky proti poslední známé dani, ne plné částky období
  - [ ] náhled ukazuje poslední známou daň, novou daň i odesílaný rozdíl
  - [ ] bez předchozího podání za období se forma D/E nenabízí
  - [ ] druhé dodatečné přiznání se počítá proti prvnímu (test)
  - [ ] řádné formy B/O a následné KH zůstávají beze změny (regresní testy zelené)

---

### N-019 · Rozšíření zadané feature „koš dokladů" (vazba na 0905) — ✅ **HOTOVO 28. 7. 2026**

> **Uzavřeno týž den** (větev `feature/document-trash` → merge a366c167, migrace 0905): koš
> u vydaných i přijatých dokladů se snapshoty, **retence s výchozími 30 dny + cron výsyp**
> (doporučení této karty), read-only guard, vyloučení z agregací, hromadné operace, DPH blokace
> u dokladů už zahrnutých ve výkazech a respektování vazeb DDKPZ v párovací cestě (commit
> 4507aae8). Manuál 9.7/17.9 „koš vs. storno vs. trvalé smazání". Zbývá provozní ověření
> před nabídnutím upstreamu.

Původní zadání karty (ponecháno pro dohledatelnost):

- **Doména:** Prodej / Systém
- **Persona:** Uživatel ✅ · Účetní ✅
- **Jak je to dnes v MyInvoice:** feature koše je rozdělaná mimo tuto analýzu (worktree
  `feature/document-trash`, migrace `0905_document_trash.sql`, `DocumentTrashService/Policy`)
  — analýza ji **neduplikuje**, jen doplňuje zjištění z konkurence, která její zadání rozšiřují.
- **Jak to řeší konkurence:** Fakturoid — koš 120 dní, výjimky pro doklady ze zálohovek
  (https://www.fakturoid.cz/podpora/faktury/kos) · iDoklad — koš volitelný přepínačem
  (https://www.idoklad.cz/podpora/nastaveni-aplikace-idoklad) · Vyfakturuj — koš nemá, ale
  má **vratné storno** a varuje před mezerou v řadě
  (https://podpora.redbit.cz/navod/smazani-storno-archivace-faktury/).
- **Proč to vadí:** bez retence koš roste donekonečna; smazání uprostřed řady tiše vyrobí
  mezeru, kterou pak účetní dohledává; provázané doklady (záloha↔DDKPZ↔vyúčtování) by po
  obnově mohly ukazovat na neexistující protistranu.
- **Návrh rozšíření zadání:** a) retence koše (např. 90–120 dní + cron výsyp — dnes v zadání
  není); b) při mazání/obnově hlídat konzistenci číselné řady a v UI zobrazit „vznikne mezera
  v řadě" (vzor Vyfakturuj); c) výjimky pro provázané doklady (DDKPZ ↔ platba ↔ vyúčtování —
  vzor Fakturoid: obnovit nelze, vazbu vrátit); d) report „mezery v číselných řadách" jako
  kontrola do N-006.
- **Dopad na datový model:** konfigurační hodnota retence + cron výsypu; ostatní sloupce řeší
  migrace 0905 (nic dalšího).
- **Kam v UI:** koš v seznamech dokladů (dle rozdělané feature) + varování v potvrzovacím
  dialogu mazání; Nastavení (retence).
- **Náročnost:** S (nad rámec rozdělané práce)
- **Priorita:** **Should** — zapracovat do probíhající feature, ne jako nový projekt; samostatně
  by nedávalo smysl.
- **Rizika a co nerozbít:** koordinace s worktree `/root/mi-trash` (nezasahovat souběžně);
  výsyp koše nesmí smazat doklad s aktivní vazbou (platba, DDKPZ, vyúčtování); mezery v řadě
  jen hlásit, ne „opravovat" přečíslováním (zpětné přečíslování je zakázané).
- **Akceptační kritéria:**
  - [ ] koš má definovanou retenci a automatický výsyp
  - [ ] smazání dokladu uprostřed řady zobrazí varování o mezeře
  - [ ] doklad s vazbou na DDKPZ/platbu nelze smazat bez řešení vazby


---

### N-009 · Cenové nabídky s online přijetím **[STD]**

- **Doména:** Prodej
- **Persona:** Uživatel ✅✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** neexistují (M-14 výslovně); nejbližší primitiva: proforma,
  schvalování výkazů zákazníkem přes e-mailový odkaz, veřejný odkaz na fakturu.
- **Jak to řeší konkurence:** iDoklad — samostatná agenda
  (https://www.idoklad.cz/podpora/cenove-nabidky) · Vyfakturuj — přijetí/odmítnutí tlačítkem na
  webfaktuře (https://podpora.redbit.cz/navod/cenove-nabidky/) · Fakturoid — Webnabídka
  (https://www.fakturoid.cz/podpora/faktury/webnabidka).
- **Proč to vadí:** jediný dokladový typ obchodního cyklu, který mají všichni tři a MyInvoice
  ne; bez nabídky se obchod domlouvá mimo systém a fakturace přepisuje ručně.
- **Návrh řešení:** typ dokladu `quote` mimo daňové výkazy: položky/ceny jako faktura, veřejný
  link s tlačítky Přijmout/Odmítnout (vzor: schvalování výkazů — token, e-mail notifikace),
  stavy koncept→odesláno→přijato/odmítnuto/expirováno, konverze na fakturu/proformu 1 klikem.
  Vlastní číselná řada. Kandidát na upstream issue (IMPROVEMENTS.md ho už navrhuje).
- **Dopad na datový model:** L — buď `invoices.invoice_type+='quote'` (nejmenší zásah, ale
  prosakuje do výkazových filtrů — nutné všude vyloučit), nebo vlastní tabulky (čistší).
  Rozhodnout v design fázi; + tokeny veřejného schválení.
- **Kam v UI:** Prodej → Nabídky (nová položka menu), quick menu „Vytvořit".
- **Náročnost:** L
- **Priorita:** **Could** — jde o konkurenční standard [STD], ale obchod této instance běží
  bez nabídek, takže velké L nemá oporu v reálné potřebě; správná cesta je issue autorovi
  (IMPROVEMENTS.md ho už eviduje) a převzetí updatem.
- **Rizika a co nerozbít:** nabídka nesmí nikdy vstoupit do DPH/KH/CRM tržeb; veřejný token
  zabezpečit jako u výkazů.
- **Akceptační kritéria:**
  - [ ] nabídka jde poslat e-mailem s veřejným odkazem; klient ji přijme bez přihlášení
  - [ ] přijetí vytvoří notifikaci a umožní konverzi na fakturu s přenosem položek
  - [ ] nabídky nefigurují v žádném daňovém výkazu ani tržbách


---

### N-013 · Připomínka před splatností + podklad pro penále

- **Doména:** Finance / Upomínky
- **Persona:** Uživatel ✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** upomínky až po splatnosti (výchozí +3 dny, M-25); úroky
  z prodlení výslovně nepočítá (stejně jako celá konkurence).
- **Jak to řeší konkurence:** Fakturoid — připomínka před splatností v automatice
  (https://www.fakturoid.cz/podpora/automatizace/upominky) · Vyfakturuj — načasování −1/0/+N
  dnů (https://podpora.redbit.cz/navod/nastaveni-upominek/) · penále: nikdo (iDoklad jen blog).
- **Proč to vadí:** předsplatnostní připomínka prokazatelně snižuje pozdní platby; penále je
  příležitost k odlišení **[NAD]** — výpočet (repo + 8 p. b. dle nař. vlády č. 351/2013 Sb.)
  je deterministický a self-hosted nástroj si ho může dovolit nabídnout jako podklad.
- **Návrh řešení:** a) do upomínkové automatiky volitelná připomínka X dní PŘED splatností
  (rozšíření stávajícího cronu + šablona); b) na detailu faktury po splatnosti informativní
  výpočet úroku z prodlení (sazba ČNB z číselníku daňových konstant) s tlačítkem „vystavit
  penalizační fakturu" (běžná faktura s předvyplněnou položkou, mimo DPH dle § 2 — plnění
  není předmětem daně).
- **Dopad na datový model:** sloupec pro pre-due offset v nastavení upomínek; repo sazby do
  tax_constants.
- **Kam v UI:** Nastavení → Upomínky; detail faktury po splatnosti.
- **Náročnost:** a) S · b) M
- **Priorita:** **Could** — hezké vylepšení inkasa, ale nic neblokuje a upomínková automatika
  po splatnosti už funguje.
- **Rizika a co nerozbít:** penále nesmí automaticky nic vystavovat (jen podklad); DPH režim
  úroku konzultovat v manuálu (osvobozeno vs. mimo předmět).
- **Akceptační kritéria:**
  - [ ] lze zapnout připomínku N dní před splatností, chodí jen jednou
  - [ ] u faktury 10+ dnů po splatnosti vidím orientační výpočet úroku s datem a sazbou
  - [ ] penalizační faktura vzniká jen ručním potvrzením


---

### N-012 · Rychlost editoru: inline našeptávání položek a další zkratky

- **Doména:** Prodej / UX
- **Persona:** Uživatel ✅ · Účetní —
- **Jak je to dnes v MyInvoice:** položky se vkládají přes „Přidat z ceníku" (vyhledávání
  v dialogu); jediná zkratka Ctrl+S (M-10).
- **Jak to řeší konkurence:** Vyfakturuj — našeptávač přímo v řádku, mezerník nabídne vše
  (https://podpora.redbit.cz/navod/naseptavac-radku-faktur/) · Fakturoid — Ctrl/Cmd+K paleta,
  Shift+? přehled zkratek (https://www.fakturoid.cz/podpora/nastaveni/tipy-ke-zrychleni) ·
  iDoklad — našeptávání položek z ceníku (https://www.idoklad.cz/podpora/karta-faktury).
- **Proč to vadí:** TM-A2 — nejčastější činnost v aplikaci; každý řádek navíc = dialog navíc.
- **Návrh řešení:** autocomplete přímo v poli názvu položky (prefix search nad price_list
  + naposledy použité položky), Enter doplní cenu/MJ/sazbu; zkratky: nová položka, uložit
  a odeslat, přepnutí DPH režimu; overlay se zkratkami (Shift+?).
- **Dopad na datový model:** žádný (API resolve existuje).
- **Kam v UI:** editor vydané i přijaté faktury.
- **Náročnost:** M
- **Priorita:** **Could** (hodnota roste s naplněným ceníkem — po N-001)
- **Rizika a co nerozbít:** nekolidovat s existujícím dialogem ceníku; a11y (aria-activedescendant
  vzor AppSelect z redesignu).
- **Akceptační kritéria:**
  - [ ] psaní v názvu položky nabízí shody z ceníku; výběr doplní cenu, MJ a sazbu
  - [ ] Shift+? zobrazí přehled zkratek; Ctrl+S dál funguje


---

### N-014 · Export Money S3 **[STD]**

- **Doména:** Daně a účetní / Exporty
- **Persona:** Uživatel 🟡 · Účetní ✅
- **Jak je to dnes v MyInvoice:** Pohoda XML, ISDOC, Stereo, CSV (M-15/M-18); Money jen nepřímo
  přes ISDOC.
- **Jak to řeší konkurence:** iDoklad — Money obousměrně
  (https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy) · Vyfakturuj — Money S3
  (https://podpora.redbit.cz/navod/exporty-do-ucetnich-programu/) · Fakturoid — Money S3 mezi
  9 formáty (https://www.fakturoid.cz/podpora/pro-ucetni/exporty).
- **Proč to vadí:** druhý nejrozšířenější účetní SW v ČR; pokud na něm jede účetní, je předání
  oklikou.
- **Návrh řešení:** NEJDŘÍV ověřit u účetní, jaký SW používá. Pokud Money: export XML Money S3
  (vydané + přijaté) po vzoru PohodaExport service; jinak zavřít jako nepotřebné (ISDOC stačí).
- **Dopad na datový model:** žádný.
- **Kam v UI:** Export vystavených/přijatých (nový formát).
- **Náročnost:** M
- **Priorita:** **Could** (podmíněná reálnou potřebou)
- **Rizika a co nerozbít:** správné mapování sazeb/klasifikací; testovat importem do Money.
- **Akceptační kritéria:**
  - [ ] vzorek 10 dokladů (vč. dobropisu a RC) se naimportuje do Money S3 bez ruční korekce


---

### N-015 · Štítky na dokladech **[STD]**

- **Doména:** Systém a UX
- **Persona:** Uživatel ✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** štítky jen v modulu Dokumenty
  ([M-26](https://myinvoice.cz/manual/26_Dokumenty.html), tabulky `document_tags` +
  `document_tag_map`); doklady mají kategorie tržeb/nákladů (v instanci nevyužité) a zakázky.
- **Jak to řeší konkurence:** Vyfakturuj — 20 barevných štítků na dokladech i nákladech
  (https://podpora.redbit.cz/navod/stitky/) · Fakturoid — štítky vč. statistik a hromadných
  akcí (https://www.fakturoid.cz/podpora/statistiky/statistiky) · iDoklad — štítky od tarifu
  Základní (https://www.idoklad.cz/cenik).
- **Proč to vadí:** volné třídění napříč typy dokladů (projektové/marketingové pohledy) dnes
  nejde; kategorie jsou 1:1 a účetně zabarvené.
- **Návrh řešení:** M:N štítky pro faktury a přijaté faktury (vzor document_tags), filtr
  v seznamech, štítek v CSV exportech.
- **Dopad na datový model:** tabulky invoice_tag_map / purchase_invoice_tag_map (sdílený
  číselník se štítky dokumentů, nebo vlastní).
- **Kam v UI:** detail + seznamy (filtr, chip), hromadná akce.
- **Náročnost:** M
- **Priorita:** **Could** — je to [STD], ale v malé instanci (13 klientů, 13 faktur) roli
  třídění zatím plní zakázky a kategorie; hodnota poroste s objemem dokladů.
- **Rizika a co nerozbít:** nezaměňovat s kategoriemi (účetní sémantika zůstává).
- **Akceptační kritéria:**
  - [ ] doklad může mít víc štítků; seznam umí filtrovat podle štítku; štítek je v CSV exportu


---

### N-010 · Platební brána jako volitelný modul (pay-by-link) **[2/3]** (Vyfakturuj + Fakturoid plně; iDoklad doloženo jen sekundárně)

- **Doména:** Prodej / Finance
- **Persona:** Uživatel ✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** jen QR platba a veřejná stránka faktury
  ([M-11](https://myinvoice.cz/manual/11_Faktura_PDF.html), routy `/api/public/invoice/{token}`)
  — bez tlačítka „Zaplatit".
- **Jak to řeší konkurence:** Vyfakturuj — 7 bran (https://podpora.redbit.cz/navod/platebni-brany/) ·
  Fakturoid — GoPay/PayPal v Na maximum
  (https://www.fakturoid.cz/podpora/automatizace/platebni-brana-gopay) · iDoklad — GoPay
  doloženo sekundárně (https://www.idoklad.cz/vlastnosti/propojeni-s-dalsimi-sluzbami).
- **Proč to vadí:** B2C/B2B klienti platí kartou rychleji; pro B2B fakturaci této instance
  (převody s VS) je přínos malý — proto nízká priorita navzdory „standardu".
- **Návrh řešení:** volitelný modul: adapter interface (create payment / webhook confirm),
  první implementace Comgate nebo GoPay, konfigurace v Nastavení → Integrace (klíče šifrovaně),
  tlačítko Zaplatit na veřejné stránce, potvrzení platby → invoice_payments. Bez konfigurace
  se nic nezobrazuje (žádná povinná SaaS závislost). Kandidát na upstream issue (IMPROVEMENTS
  ho už eviduje).
- **Dopad na datový model:** tabulka gateway_payments (doklad, session id, stav, částka).
- **Kam v UI:** Nastavení → Externí integrace; veřejná stránka faktury.
- **Náročnost:** L
- **Priorita:** **Could**
- **Rizika a co nerozbít:** webhook bezpečnost (podpisy), idempotence potvrzení, refundy mimo
  scope v1.
- **Akceptační kritéria:**
  - [ ] bez konfigurace se veřejná stránka nemění
  - [ ] testovací platba označí fakturu jako zaplacenou právě jednou (idempotentní webhook)


---

### N-017 · Webhooky **[2/3]** (Vyfakturuj + Fakturoid; iDoklad je jen plánuje)

- **Doména:** Systém / API
- **Persona:** Uživatel 🟡 · Účetní 🟡 (přínos hlavně pro integrátory)
- **Jak je to dnes v MyInvoice:** API je čistě pull (M-41 výslovně bez webhooků).
- **Jak to řeší konkurence:** Vyfakturuj — webhook při každé změně dokladu
  (https://podpora.redbit.cz/navod/webhooky/) · Fakturoid — události faktur/nákladů/kontaktů/skladu
  (https://www.fakturoid.cz/podpora/automatizace/webhooky) · iDoklad — jen „plánované"
  (https://api.idoklad.cz/Help/v3/cs/).
- **Proč to vadí:** Make/n8n integrace dnes musí pollovat; pro self-hosted nasazení je webhook
  přirozený (vlastní infrastruktura).
- **Návrh řešení:** tabulka webhook subscriptions (URL, události, secret), dispatch po commitu
  událostí (invoice.issued/paid, purchase.created, …) s retry a podpisem HMAC; admin UI se
  seznamem doručení.
- **Dopad na datový model:** webhooks + webhook_deliveries.
- **Kam v UI:** Systém → API tokeny (rozšířit na „API a webhooky").
- **Náročnost:** M–L
- **Priorita:** **Could** (v instanci se API zatím nepoužívá — 0 tokenů)
- **Rizika a co nerozbít:** neblokovat request cyklus (fronta přes cron), SSRF ochrana URL.
- **Akceptační kritéria:**
  - [ ] událost „faktura zaplacena" doručí podepsaný POST s retry při výpadku příjemce


---

### N-018 · Push notifikace (PWA Web Push) **[STD]** (všichni tři, ovšem přes nativní aplikace)

- **Doména:** Systém a UX
- **Persona:** Uživatel ✅ · Účetní 🟡
- **Jak je to dnes v MyInvoice:** PWA bez service workeru s pushem; notifikace jen e-mail
  a in-app „Akce pro tebe". IMPROVEMENTS.md to eviduje jako navazující nápad k PWA.
- **Jak to řeší konkurence:** všichni tři přes nativní aplikace (iDoklad
  https://www.idoklad.cz/podpora/nastaveni-uzivatel; Vyfakturuj
  https://podpora.redbit.cz/navod/notifikacni-centrum/; Fakturoid
  https://www.fakturoid.cz/podpora/nastaveni/upozorneni).
- **Proč to vadí:** „přišla platba / faktura po splatnosti / nový doklad v inboxu" na mobil —
  jediný kanál, kterým self-hosted PWA konkuruje nativním appkám; od iOS 16.4 funguje i na
  iPhonu.
- **Návrh řešení:** Web Push (VAPID klíče generované při instalaci, žádná externí služba),
  service worker rozšířit, per-user volby událostí; odběry v DB.
- **Dopad na datový model:** push_subscriptions (user, endpoint, klíče, volby).
- **Kam v UI:** Profil → Notifikace.
- **Náročnost:** M
- **Priorita:** **Could** — příjemné, ale e-mailové notifikace a in-app „Akce pro tebe"
  pokrývají stejné události; riziko service workeru (viz níže) ať nese až ověřená potřeba.
- **Rizika a co nerozbít:** SW cache verze (zaseknuté verze — důvod, proč fork SW dosud
  nechtěl); degradace bez HTTPS.
- **Akceptační kritéria:**
  - [ ] po povolení přijde push při spárování platby; odhlášení odběru funguje


---

### N-016 · Roční daně OSVČ: DPFO výpočet + přehledy ČSSZ/ZP — **Won't (pro tuto instanci)**

- **Doména:** Daně
- **Persona:** Uživatel 🟡 (jen OSVČ) · Účetní 🟡
- **Jak je to dnes v MyInvoice:** daň z příjmů jako „foundation" podklad + XML kostra
  (M-32); přehledy pojištění výslovně mimo rozsah (M-28).
- **Jak to řeší konkurence:** Fakturoid — DPFO XML + oba přehledy (Na lehko,
  https://www.fakturoid.cz/podpora/ucetnictvi/cssz-prehled) · iDoklad — DPFO s výpočtem
  (https://www.idoklad.cz/podpora/finance-dan-z-prijmu-fo) · Vyfakturuj — nic.
- **Náročnost:** L
- **Priorita:** **Won't** (pro tuto instanci) — obě firmy jsou s.r.o., DPFO/přehledy OSVČ se
  jich netýkají; roční závěrku dělá účetní v účetním SW. Pro upstream jde o legitimní Could
  (uvést v issue trackeru autora, ne ve forku).
