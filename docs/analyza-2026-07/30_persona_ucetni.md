# 30 · Persona B — externí účetní

Průchod ročním cyklem externí účetní, která zpracovává agendu majitele instance (2 s.r.o.,
plátci DPH — Beta Servis kvartální období, Alfa Trade má období v nastavení nevyplněné, doplnit
v rámci N-001), případně obsluhuje více klientů. Stav: v instanci dnes
**žádný účet účetní neexistuje** — spolupráce běží mimo systém (e-mail, ruční předání), přestože
fork má role, per-user omezení na firmy i hromadné exporty hotové.

## Roční cyklus účetní v MyInvoice vs. konkurence

### 1. Převzetí přístupu

**MyInvoice:** admin založí uživatele s rolí `accountant` (doklady + výkazy, bez systémové
konfigurace) nebo `readonly`; fork navíc umí omezit uživatele na vybrané firmy — v multi-supplier
instanci s cizími subjekty zásadní (upstream to neumí, M-35 to uvádí jako limit). Chybí:
samoobslužná pozvánka e-mailem — účet zakládá admin ručně vč. hesla.

**Konkurence lépe:** Fakturoid — klient pošle pozvánku (platí 10 dní), účetní si sama založí
přihlášení; role účetní je **vždy zdarma** a nepočítá se do placených uživatelů
(https://www.fakturoid.cz/podpora/pro-ucetni/pristup-pro-ucetni). Vyfakturuj má pozvánku
e-mailem s platností 7 dní (https://podpora.redbit.cz/navod/ucetni-a-uzivatele/).

**Třecí místo TM-B1:** založení přístupu je admin-úkon, ne pozvánka → N-004 (pozvánka + průvodce
přístupem účetní; krátkodobě stačí účet založit ručně — N-001).

### 2. Kontrola úplnosti dokladů

**MyInvoice:** nic systémového. Nepřímé signály: CRM action items (nespárovaná banka,
rozpracované nákupy), stavový cyklus přijatých faktur, fulltext v Dokumentech. Žádný způsob, jak
říct klientovi „za červen mi chybí doklad k platbě X" jinak než e-mailem mimo systém.

**Konkurence lépe:**
- Fakturoid: Krabice — klient (i jeho dodavatelé) sype doklady na e-mail adresu, účetní ráno
  dostane souhrn a stáhne vše jedním ZIPem (https://www.fakturoid.cz/podpora/pro-ucetni/krabice-na-naklady);
  export nákladů navíc kontroluje povinné údaje (VS, čísla dokladů).
- iDoklad: Inbox s unikátní adresou agendy (https://www.idoklad.cz/podpora/inbox).
- Formální workflow „chybí mi doklad" ale nemá nikdo — jen Fakturoid se k němu blíží nepřímo.

**Třecí místo TM-B2 (jádro persony B):** úplnost podkladů se kontroluje ručně a mimo systém →
N-005/N-006; e-mailový příjem dokladů → N-007.

### 3. Párování banky

**MyInvoice:** import GPC/PDF výpisů, e-mailová avíza, auto-match VS+částka, částečné a sloučené
úhrady, doklad z odchozí platby (M-24) — funkčně slušné, ale v instanci **vypnuté** (0 výpisů,
0 transakcí). Účetní tak přebírá úhrady jen z toho, co majitel ručně naklikal.

**Konkurence:** všichni párují automaticky (viz TM-A4); pro účetní je podstatné, že úhradová
data jsou v systému důvěryhodná bez lidské disciplíny.

**Třecí místo TM-B3:** totéž co TM-A4 — bez banky v systému účetní nemůže věřit stavům úhrad →
N-001, N-003.

### 4. Uzávěrka měsíce

**MyInvoice:** žádná uzávěrka ani zámek období. Jistotu dává immutabilita vystavených dokladů
a (fork) zámek jednotlivého dokladu s auditovaným odemčením — ale nic nebrání klientovi zpětně
přidat/upravit doklad v už předaném období, a nic účetní neupozorní, že se to stalo.

**Konkurence lépe:**
- Fakturoid: zamykání dokladů vč. hromadného „zamknout exportované" přímo v okně exportu; režim
  „zamykat smí pouze účetní"; při cizím odemčení notifikace účetní
  (https://www.fakturoid.cz/podpora/ucetnictvi/zamykani-dokladu). Nejlepší řešení na trhu.
- iDoklad: volitelný zákaz úprav dokladů přenesených do účetního SW
  (https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy).
- Vyfakturuj: nic — doklady lze editovat kdykoli (pro účetní odstrašující příklad).

**Třecí místo TM-B4:** předané období není chráněné ani monitorované → N-005 (zámek období +
notifikace změn v uzavřeném období).

### 5. DPH + KH + SH

**MyInvoice:** tři výkazy z jedné klasifikace, preview s kontrolami, kniha DPH, archiv podání;
fork přidal formu podání (řádné/opravné/dodatečné/následné + datum zjištění), termíny podle
pracovních dnů a tvrdou validaci identifikace podatele. Objektivně nejsilnější nástroj ze všech
čtyř — konkurence generuje jen řádná podání a bez knihy DPH (Fakturoid ji nemá vůbec,
Vyfakturuj jen XML podklady).

**Třecí místo TM-B5:** žádné vůči konkurenci. Interní: výkazy může generovat jen uživatel
s přístupem — dnes je generuje majitel, ne účetní (viz TM-B1).

### 6. Opravy a dodatečná podání

**MyInvoice (fork):** dobropisy s korektními znaménky, dodatečné/následné podání s datem
zjištění — unikát (nikdo z konkurence negeneruje; Fakturoid výslovně „ručně na EPO").

**Třecí místo TM-B6:** žádné — konkurenční výhoda.

### 7. Roční závěrka

**MyInvoice:** daň z příjmů jen „foundation" podklad (M-32); pro s.r.o. se závěrka dělá
v účetním SW účetní — z MyInvoice potřebuje kompletní a uzamčená data za rok (→ TM-B2/B4)
a exporty (→ bod 8).

### 8. Předání dat do vlastního programu

**MyInvoice:** Pohoda XML (vydané i přijaté), ISDOC 6.0.2 (čtou Money, Helios, ABRA…), Stereo
XML, CSV, hromadný ZIP za období s prioritou originálů (M-15, M-18, M-34).

**Konkurence lépe:**
- Fakturoid: 9 dedikovaných formátů + předvyplněné exporty a sloupec „poslední export" per klient
  (https://www.fakturoid.cz/podpora/pro-ucetni/exporty).
- iDoklad: obousměrná synchronizace s Money S3 vč. zpětného zápisu úhrad
  (https://www.idoklad.cz/podpora/komunikace-s-ucetnimi-systemy).
- Vyfakturuj: předkontace a střediska v exportech
  (https://podpora.redbit.cz/navod/predkontace-a-cleneni-dph/).

**Třecí místo TM-B7:** pokud účetní nejede na Pohodě/ISDOC, je předání oklikou; a export nemá
paměť „co už bylo předáno" → N-006 (stav období), N-014 (Money S3 export, Could — nejdřív ověřit,
jaký SW účetní reálně používá).

## Model spolupráce s externí účetní — co má Fakturoid (detail `/podpora/pro-ucetni`)

Fakturoid je jediný, kdo z „účetní má login" udělal ucelený produkt. Architektura je dvouvrstvá:

1. **Role „účetní" v účtu klienta** — zdarma v každém placeném tarifu, nepočítá se do uživatelů.
   Práva: všude čtení + exporty (vč. ABO a DPFO XML), zamykání/odemykání dokladů, soukromé
   poznámky, úprava sekce Daně a účetnictví. Nesmí vystavovat doklady ani vytěžovat AI (zápis lze
   dokoupit jako běžný uživatel). Pozvánka od klienta e-mailem, platnost 10 dní; jedna adresa
   účetní může mít přístup k libovolnému počtu klientů; přístup si ruší sama.
   (https://www.fakturoid.cz/podpora/pro-ucetni/pristup-pro-ucetni,
   https://www.fakturoid.cz/podpora/nastaveni/uzivatel-ucetni)
2. **Účet „Fakturoid pro účetní"** — bezplatná nadstavba pro kancelář: seznam „Účtovaní klienti"
   (majitel vidí všechny, zaměstnanci jen přiřazené; do 10 zaměstnanců, klientů neomezeně),
   přepnutí do klienta jedním klikem, u každého klienta přímé odkazy na exporty (formulář
   předvyplněný podle minula) a sloupec **„Poslední export"** — de facto stav zpracování klienta.
   (https://www.fakturoid.cz/podpora/pro-ucetni/fakturoid-pro-ucetni,
   https://www.fakturoid.cz/podpora/pro-ucetni/fakturoid-pro-ucetni-funkce)

Okolo toho tři podpůrné mechanismy:

- **Zamykání dokladů** jako komunikační protokol „tohle už je zaúčtované": hromadný checkbox
  při exportu, přísný režim „zamykat smí pouze účetní", notifikace účetní při cizím odemčení,
  zámek jako filtr. (https://www.fakturoid.cz/podpora/pro-ucetni/zamykani-dokladu)
- **Krabice na náklady** jako sdílená schránka: vlastní e-mail adresa (lze dát i dodavatelům),
  ranní souhrn v 8:00, „Exportovat vše" → ZIP; soubor zmizí až převodem na náklad.
  (https://www.fakturoid.cz/podpora/pro-ucetni/krabice-na-naklady)
- **Exporty** do 9 účetních programů s kontrolou povinných údajů a historií exportů 30 dní.
  (https://www.fakturoid.cz/podpora/pro-ucetni/exporty)

**Poučení pro MyInvoice:** primitivy už existují (role accountant, readonly, multi-supplier,
per-user omezení firem, hromadný ZIP, zámek dokladu, audit log). Chybí tři vrstvy nad nimi:
(a) *protokol zpracování* — zámek období/dokladů jako signál „zaúčtováno" + notifikace při
porušení, (b) *stav předání* — kdo, kdy, co exportoval/předal za které období, (c) *kanál
podkladů* — e-mailový příjem dokladů a žádost o chybějící doklad. To je obsah návrhů N-004,
N-005, N-006 a N-007. Samoobslužný multi-klient účet à la „Fakturoid pro účetní" je u self-hosted
nástroje jiná situace (účetní může být uživatelem více instancí) — řeší se dokumentací a rolemi,
ne novým produktem.

## Souhrn třecích míst persony B

| # | Třecí místo | Vážnost | Řeší návrh |
|---|---|---|---|
| TM-B2 | Kontrola úplnosti podkladů běží ručně mimo systém | 🔴 vysoká | N-005, N-006, N-007 |
| TM-B4 | Uzavřené období není zamčené a změny v něm nikdo nehlásí | 🔴 vysoká | N-005 |
| TM-B1 | Účetní nemá přístup (a založení není samoobslužné) | 🔴 vysoká | N-001, N-004 |
| TM-B3 | Úhrady v systému nejsou důvěryhodné bez napojené banky | 🔴 vysoká | N-001, N-003 |
| TM-B7 | Export bez paměti stavu; formáty mimo Pohoda/ISDOC chybí | 🟠 střední | N-006, N-014 |
| TM-B5/B6 | Výkazy DPH vč. dodatečných — bez mezery | ✅ výhoda | — |
