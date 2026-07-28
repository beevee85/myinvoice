# 90 · Zdroje

**Datum snapshotu všech zdrojů: 2026-07-28.** Nápovědy konkurence se mění — před implementací
konkrétního návrhu ověřit aktuálnost odkazované stránky. Vše v analýze je parafrázováno; přímé
citace max. 1 krátká na návrh. Stavy ❌ u konkurence znamenají „aktivně hledáno a nenalezeno
k datu snapshotu", ❔ „nedohledáno".

## Interní zdroje (fork a instance)

- `/opt/myinvoice` git (větev `custom`, HEAD e63767a3) — porovnání s `upstream/master` (0 pozadu), vlastní commity, migrace 0900–0905.
- Databáze instance (read-only dotazy, MariaDB) — reálná konfigurace dodavatelů, číselné řady, uživatelé/role, počty dokladů, crony, aktivita.
- `/opt/myinvoice/CUSTOMIZATIONS.md`, `IMPROVEMENTS.md`, `CHANGELOG.md`, `manual/*.md` (42 kapitol lokálně, vč. fork sekcí 10.10, 24.7, 36.2.3, 29+).
- `/opt/myinvoice/docs/dph-zalohy.md` — hotová rešerše DDKPZ (§ 20a, § 28, § 37a, § 72–73, KH) s rozhodovacím stromem; právní podklad návrhů N-002/N-008.
- `api/src/Routes.php`, `web/src/components/layout/AppLayout.vue`, `web/src/i18n/cs.json` — routy, menu, popisky.
- Pracovní podklady analýzy (vytěžené poznámky, ~110 kB): scratchpad `podklady/{myinvoice_manual,idoklad,vyfakturuj,fakturoid,fork_stav}.md` + kopie `openapi.yaml`.

## MyInvoice.cz (publikovaný manuál + API)

Zpracováno všech 42 kapitol z rozcestníku https://myinvoice.cz/manual/index.html (skupiny
INSTALACE A START, PRODEJ, NÁKUP, FINANCE, DOKUMENTY, DANĚ, SYSTÉM, REFERENCE). Jednořádková
shrnutí kapitol:

- 01_Uvod — self-hosted CZ fakturace, PHP 8.5 + Vue 3 + MariaDB, MIT; cílovky freelancer/OSVČ/účetní kancelář.
- 02–04 (Quickstart, Docker, Nativní) — tři cesty instalace; GHCR image, jediný stateful volume, bundle pro hosting.
- 05_Po_instalaci — CLI, crony (zálohy/banka/upomínky), šifrování záloh AES-256, monitoring úloh.
- 06_Setup_wizard — 3 kroky: admin → dodavatel z ARES (mod-11 kontrola účtu) → volitelná testovací data.
- 07_Prihlaseni — TOTP 2FA, eskalující lockouty, reset hesla.
- 08_Prehled — KPI dlaždice per měna, po splatnosti s akcemi.
- 09_Faktury — 9 stavů, filtry/fulltext, hromadné akce, storno vs. dobropis, řada YYMMNNN.
- 10_Faktura_editor — ceník s cenami per klient, sleva, netto/brutto zaokrouhlení, zálohy↔daňové doklady, RC, kurzy ČNB, výkazy se schvalováním, Ctrl+S.
- 11_Faktura_PDF — immutable PDF/A-3b, QR SPAYD/SEPA, veřejný odkaz s evidencí zobrazení, S/MIME, jazyk dle klienta.
- 12_Pravidelne_fakturace — intervaly M/Q/H/Y, 3 režimy, „otevřené koncepty".
- 13_Klienti — ARES/VIES, kontakty dle účelu, per-klient výchozí hodnoty, archivace.
- 14_Zakazky — rozpočty, sazby, výkazy, veřejný sledovací odkaz; výslovně BEZ nabídek/objednávek/dodacích listů.
- 15_Exporty — PDF/ZIP, ISDOC 6.0.2, Pohoda XML, Stereo XML.
- 16_Importy — Pohoda XML/ISDOC/PDF-A-3/ZIP; API iDoklad + Fakturoid vč. banky; dry-run, dedup dle VS.
- 17_Prijate_faktury — PDF/foto/ISDOC, stavový cyklus, inbox adresář (SHA-256 dedup), párování záloh, QR úhrada.
- 18_Export_prijatych — „naše PDF", ISDOC, Pohoda XML, ZIP s prioritou originálů.
- 19_AI_extrakce — Anthropic Claude BYOK, sanity checky 2 %/50 %, RC klasifikace.
- 20_Platebni_prikazy — ABO/KPC/CSV/PDF, „Předáno k úhradě", CRPDPH ověření účtů.
- 21_CRM — KPI trendy, aging obou stran, DSO/DPO, churn/late risk, action items.
- 22_Trzby / 23_Naklady — run-rate scénáře, histogramy, kategorie, TOP protistrany.
- 24_Banka — GPC/ABO (KB, Fio, ČSOB, RB, ČS, mBank…), PDF výpisy, auto-párování VS+částka, doklad z odchozí platby.
- 25_Upominky — ruční/hromadné/cron, eskalace tónu, vypnutí na 3 úrovních; úroky výslovně ne.
- 26_Dokumenty — složky/tagy/vazby, fulltext v obsahu, ZFO datovky, koš, zálohy 30D+12M.
- 27_Kniha_jizd — vozidla vč. EV, tankování z faktur, roční souhrny, XLSX/PDF.
- 28_Fakturujeme — daňový průvodce (plátce/IO, sazby, RC, OSS); vymezení mimo rozsah (pojištění, IOSS).
- 29_Vykazy_DPH — DPHDP3 + DPHKH1 XML, klasifikace s overridem; bez dodatečných podání (upstream) a zámku období.
- 30_Kniha_DPH — měsíční žurnál dle řádků přiznání; „není podání".
- 31_Souhrnne_hlaseni — DPHSHV, kódy plnění, identifikované osoby.
- 32_Dan_z_prijmu — orientační HV, XML kostra DPFDP5/DPPDP9; výslovně „foundation".
- 33_Danovy_optimalizator — paušální daň vs. paušály vs. skutečné výdaje, hlídání limitů.
- 34_Hromadny_export — měsíční/kvartální ZIP podkladů pro účetní, konzistentní s výkazy.
- 35_Multi_supplier — neomezeně firem, izolace dat; upstream neumí omezit uživatele na firmu (fork ano).
- 36_Nastaveni — role, Twig šablony e-mailů, SMTP profily + DKIM, ceník, activity log.
- 37_Bankovni_ucty — účty per měna, IMAP avíza s parsery, zůstatky.
- 38_Elektronicke_podpisy — PAdES B/T, S/MIME, P12/PFX profily.
- 39_Bezpecnost — bcrypt+pepper, TOTP, IP allowlist, RBAC, audit každé mutace.
- 40_Aktualizace — update z UI/CLI, watcher, rollback tagem.
- 41_API — PAT tokeny, scopes, 600 req/min, bez webhooků.
- 99_Reseni_problemu — troubleshooting 9 okruhů.
- https://myinvoice.cz/api/openapi.yaml — OpenAPI 3.1: 185 cest / 235 operací / 20 tagů (marketingové „101 endpointů" zastaralé).
- https://myinvoice.cz/api/docs — Swagger UI (potvrzení oficiální API dokumentace).
- https://myinvoice.cz/ — marketing; použit jen orientačně, čísla neaktuální (deklaruje 25 kapitol manuálu, reálně 42).

## iDoklad.cz

Hlavní použité stránky (kompletní seznam ~45 URL v pracovních podkladech):

- https://www.idoklad.cz/cenik — tarify Zdarma / Základní / Oblíbený / Prémiový; AI kredity; slevy pro účetní 50 %.
- https://www.idoklad.cz/podpora (+ /podpora/kategorie/vse) — rozcestník, ~47 článků v 7 kategoriích.
- https://www.idoklad.cz/podpora/ovladani-aplikace — ovládání: lišta, seznamy, hromadné akce.
- https://www.idoklad.cz/podpora/karta-faktury — editor: řady, slevy, PDP/OSS, QR, čtečka kódů, semafor plátce DPH.
- https://www.idoklad.cz/podpora/zalohove-faktury + /danove-doklady-k-platbe — zálohy, vyúčtování více záloh; DDKP ručně s hlídáním 15denní lhůty.
- https://www.idoklad.cz/podpora/pravidelne-faktury · /dobropisy · /faktury-vydane — automatizace prodeje, dobropis s povinným důvodem a zápočtem.
- https://www.idoklad.cz/podpora/inbox — Inbox s unikátní e-mail adresou + AI vytěžení (3 zdarma, 100 ks/500 Kč).
- https://www.idoklad.cz/podpora/nastaveni-banka · /finance-banka — párování: 13 bank přes e-mailové notifikace, haléřové vyrovnání.
- https://www.idoklad.cz/podpora/finance-dph · /finance-dan-z-prijmu-fo — XML DPH/KH/SH pro Moje daně; DPFO podklady vč. ČSSZ.
- https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy — Money S3/S4/S5 obousměrně, Pohoda, Vario; zákaz úprav po exportu.
- https://www.idoklad.cz/podpora/nastaveni-e-maily-a-komunikace — auto upomínky (max 10), poděkování za platbu, vlastní SMTP/Gmail/O365.
- https://www.idoklad.cz/podpora/nastaveni-aplikace-idoklad · /nastaveni-uzivatel — koš, GDPR anonymizace, 2FA, push.
- https://www.idoklad.cz/podpora/rychle-tipy-launchpad · -grid · -detail — in-app tipy, přepínač firem, filtry.
- https://www.idoklad.cz/jak-zacit-s-idokladem — onboarding průvodce ve 4 kapitolách.
- https://www.idoklad.cz/vlastnosti/doklady-pro-ucetni · /prehledy · /propojeni-s-dalsimi-sluzbami — přístup účetní, hlídání obratu DPH, integrace.
- https://www.idoklad.cz/registrace-ucetniho — program pro účetní (sleva 50 %).
- https://www.idoklad.cz/aplikace-idoklad + https://rozsireni.idoklad.cz/produkt/mobilni-aplikace-idoklad/ — nativní app se skenováním a 2FA.
- https://api.idoklad.cz/Help/v3/cs/ + https://developer.idoklad.cz/ — API v3, bez webhooků.
- https://www.make.com/en/integrations/idoklad — nativní Make modul.
- Blog: /blog/co-delat-kdyz-si-smazete-fakturu-nastavte-si-kos (koš), /blog/jak-na-vymahani-faktur-po-splatnosti… (penále jen edukativně), /blog/prichazi-3-4-vlna-eet… (EET historicky).
- Nedohledáno: oficiální článek k online platbám GoPay, audit log, kniha DPH, kurzové rozdíly, DSO.

## Vyfakturuj.cz

Hlavní použité stránky (kompletní seznam ~60 URL v pracovních podkladech; nápověda je společná
s produktem SimpleShop na podpora.redbit.cz):

- https://www.vyfakturuj.cz/cenik/ — tarify Zdarma (1 rok) / Mini 175 / Ideal 299 / Profi 620 Kč.
- https://www.vyfakturuj.cz/funkce/ · /napojeni/ · /api/ · /changelog/ — přehled funkcí, 7 platebních bran, banky, API, novinky (2FA 6/2026).
- https://podpora.redbit.cz/navod/typy-dokladu-v-systemu-vyfakturuj-cz-a-jak-s-nimi-pracovat/ — typologie dokladů a auto-návaznosti (proforma→faktura, zálohovka→DDKPZ).
- https://podpora.redbit.cz/navod/jak-vyuctovat-zalohovou-fakturu/ — vyúčtování záloh, hromadné koncové faktury.
- https://podpora.redbit.cz/navod/cislovani-faktur/ · /vlastni-ciselne-rady/ — formáty, roční reset, mezery po smazání.
- https://podpora.redbit.cz/navod/smazani-storno-archivace-faktury/ — smazání nevratné (bez koše), vratné storno, archiv.
- https://podpora.redbit.cz/navod/parovani-plateb-s-bankou/ — párování z e-mailových avíz, ~13 bank; Fio API jen Profi.
- https://podpora.redbit.cz/navod/nastaveni-upominek/ — 3 auto upomínky, i před splatností.
- https://podpora.redbit.cz/navod/priznani-k-dph/ · /kontrolni-hlaseni-dph/ · /souhrnne-hlaseni/ · /export-podkladu-k-dph/ — XML podklady pro Moje daně, vyřazování vadných dokladů.
- https://podpora.redbit.cz/navod/exporty-do-ucetnich-programu/ (+ /pohoda-export-dokladu/) — Pohoda, Money S3, DUEL; CSV/XML/JSON/ISDOC.
- https://podpora.redbit.cz/navod/ucetni-a-uzivatele/ — role účetní/uživatel, pozvánka 7 dní, dashboard účetní se seznamem firem.
- https://podpora.redbit.cz/navod/predkontace-a-cleneni-dph/ · /strediska/ · /stitky/ · /projektove-identity/ — účetní metadata a organizace.
- https://podpora.redbit.cz/navod/cenove-nabidky/ · /zobrazeni-webfaktury/ — nabídky s online přijetím, webfaktura.
- https://podpora.redbit.cz/navod/platebni-brany/ — 7 bran (Comgate, GoPay, ThePay, PayPal, Stripe, GP, Pays).
- https://podpora.redbit.cz/navod/webhooky/ · /api-ve-vyfakturuj-cz-a-simpleshopu/ · /make-integromat-napojeni/ — webhooky (Profi), API + PHP SDK, Make.
- https://podpora.redbit.cz/navod/notifikacni-centrum/ · /dvoufazove-overeni-2fa/ · /gdpr-a-vyfakturuj-cz-casto-kladene-dotazy/ — notifikace, 2FA, GDPR.
- https://podpora.redbit.cz/navod/mobilni-aplikace-vyfakturuj-cz-instalace-a-nastaveni/ · /mobilni-aplikace-vyfakturuj-uctenky/ — mobilní app, focení účtenek, QR sken (bez OCR).
- https://podpora.redbit.cz/navod/naseptavac-radku-faktur/ · /sablony-faktur/ · /evidence-nakladu/ · /neuhrazeny-doklad-v-systemu/ · /graf-a-prehledy-prijmu/ — editor, náklady, úhrady, přehledy.
- Fakturopedie: /fakturopedie/dodaci-list/ · /zaokrouhlovani-dph-na-fakturach/ · /fakturace-penale-za-pozdni-uhradu-faktury/ · /jak-na-eet-v-roce-2022/ — edukační obsah (dodací list, zaokrouhlení, penále bez funkce, EET historicky).
- Nedohledáno: klávesové zkratky, dodatečné přiznání, detaily retence dat; /eet/ vrací 404.

## Fakturoid.cz

Projito všech 12 témat podpory; sekce `/podpora/pro-ucetni` kompletně (hlavní zdroj persony B).
Hlavní použité stránky (kompletní seznam ~60 URL v pracovních podkladech):

- https://www.fakturoid.cz/cenik — tarify Zdarma / Na lehko / Na každý den / Na maximum; role účetní zdarma v placených tarifech.
- https://www.fakturoid.cz/podpora — rozcestník 12 sekcí.
- **Pro účetní:** /podpora/pro-ucetni/pristup-pro-ucetni (pozvánka 10 dní, práva, zdarma) · /pro-ucetni/fakturoid-pro-ucetni (+ -funkce) (bezplatný multi-klientský účet, „Účtovaní klienti", poslední export) · /pro-ucetni/zamykani-dokladu (zámky, režim „jen účetní", notifikace odemčení) · /pro-ucetni/krabice-na-naklady (ZIP export, ranní souhrn) · /pro-ucetni/exporty (9 účetních programů, kontroly, historie 30 dní) · /podpora/nastaveni/uzivatel-ucetni (detail práv) · /podpora/ucetnictvi/jak-pozvat-ucetni (pohled klienta).
- https://www.fakturoid.cz/podpora/faktury/zalohova-faktura · /danovy-doklad-k-platbe — 4 režimy zálohovky, automatický DDKPZ (vzor pro N-002).
- https://www.fakturoid.cz/podpora/faktury/kos — koš 120 dní, výjimky pro doklady ze zálohovek.
- https://www.fakturoid.cz/podpora/nastaveni/ciselne-rady (+ /vice-ciselnych-rad) — návrh čísla dle posledních 10, hospodářský rok.
- https://www.fakturoid.cz/podpora/automatizace/parovani-plateb-s-bankou + /podpora/parovani/fio-api — párování (12 bank, avíza), přímé Fio API (vzor pro N-003).
- https://www.fakturoid.cz/podpora/automatizace/upominky · /pravidelne-faktury · /webhooky · /mcp-server — automatizace; MCP server pro AI zdarma.
- https://www.fakturoid.cz/podpora/naklady/vytezovani-dokladu · /krabice-na-naklady · /abo-export · /pravidelne-naklady — AI vytěžení (kredity), Krabice, ABO příkazy, opakované náklady.
- https://www.fakturoid.cz/podpora/ucetnictvi/priznani-k-dph · /kontrolni-hlaseni-dph · /souhrnne-hlaseni-dph · /moss · /zamykani-dokladu · /danova-evidence · /pausalni-dan · /cssz-prehled · /zdravotni-pojistovna-prehled · /priznani-k-dani-z-prijmu-fo — daňový servis (DPFO + přehledy = vzor pro OSVČ; dodatečná podání nemá).
- https://www.fakturoid.cz/podpora/faktury/vytvoreni-faktury · /webfaktura · /nabidky · /webnabidka · /storno-faktury · /opravny-danovy-doklad · /castecne-platby · /poznamka · /hromadne-akce-faktur · /qr-kod-na-fakture · /zjednoduseny-danovy-doklad — prodejní cyklus.
- https://www.fakturoid.cz/podpora/nastaveni/tipy-ke-zrychleni · /obrazovka-objevte · /aplikace-na-mobilu · /upozorneni · /dvoufazove-overeni · /prechod-k-fakturoidu · /uzivatele-a-opravneni — UX, onboarding „Objevte" (vzor pro N-011), mobilní app, migrace.
- https://www.fakturoid.cz/podpora/statistiky/statistiky · /cashflow · /vyhledavani · /karticky-pro-filtrovani · /hlidani-obratu-dph · /historie-exportu — přehledy a hledání.
- https://www.fakturoid.cz/podpora/kontakty/klientsky-portal · /informace-o-spolehlivosti-kontaktu · /automaticka-aktualizace-kontaktu · /dlouho-nefakturovani — kontakty, portál, spolehlivost.
- https://www.fakturoid.cz/podpora/obchody/jak-pracovat-s-obchody (+ /dalsi-kroky) — mini-CRM s úkoly.
- https://www.fakturoid.cz/podpora/sklad/sklad-ve-fakturoidu · /cenik — sklad a ceník.
- https://www.fakturoid.cz/api + /api/v3 — OAuth2, moduly, limity, webhooky.
- https://www.fakturoid.cz/katalog-aplikaci — integrace (Make ano, Zapier ne).
- https://www.fakturoid.cz/bezpecnost-vasich-dat — zálohy denně, HTTPS, bcrypt.
- Blog: /blog/2025/11/06/lepsi-zaokrouhlovani (zaokrouhlení), /blog/2014/06/04/zaznamy-zmen (události účtu).
- Nedohledáno: ceny s/bez DPH jednoznačně, náklady v nejnižších tarifech, retence po zrušení účtu, explicitní roční reset řad.

## Poznámky ke spolehlivosti

- Konkurenční nápovědy vytěženy přes WebFetch — tarifní matice (zejména Vyfakturuj a přesné
  ceny iDokladu dle frekvence platby) doporučuji před citováním v ceníkových srovnáních ověřit
  ručně; pro účely této analýzy („je funkce v základu, nebo placená") je granularita dostatečná.
- Publikovaný manuál MyInvoice odpovídá upstream v4.51.0; fork má navíc vlastní sekce
  (viz [50_fork_odchylky.md](50_fork_odchylky.md) § 3).
