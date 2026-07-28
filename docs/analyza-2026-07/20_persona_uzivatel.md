# 20 · Persona A — běžný uživatel (majitel malého s.r.o. / OSVČ)

Průchod ročním cyklem fakturace v MyInvoice (stav fork ⟳ 4.52.1, instance faktury.betka.eu)
a srovnání s tím, jak stejný krok řeší iDoklad, Vyfakturuj a Fakturoid. Konkrétní kontext
instance: 2 firmy (BEKRON, PROPSOL — obě s.r.o., plátci DPH), fakturuje jednatel sám,
platby označuje ručně (banka nenapojena), účetnictví zpracovává externí účetní.

## 1. Onboarding a první spuštění

**MyInvoice:** silné — 3krokový wizard s načtením firmy z ARES a volitelnými testovacími daty
(M-06). Slabé: po wizardu žádný „checklist adopce" — nic uživatele nedovede k zapnutí párování
banky, upomínek, ceníku nebo číselných řad. Přesně tyhle funkce v instanci zůstaly nevyužité
(banka 0 výpisů, ceník 0 položek, opakované faktury 0), což je nejlepší důkaz, že mezera je reálná.

**Konkurence lépe:**
- Fakturoid: obrazovka „Objevte" trvale ukazuje nevyužité funkce tarifu s proklikem k aktivaci
  (https://www.fakturoid.cz/podpora/nastaveni/obrazovka-objevte) — řeší přesně náš problém.
- iDoklad: strukturovaný webový průvodce „Jak začít" ve 4 kapitolách vč. kapitoly Automatizace
  (https://www.idoklad.cz/jak-zacit-s-idokladem).

**Třecí místo TM-A1:** hotové funkce se bez průvodce nikdy nezapnou → návrh N-001 (adopce) a
N-011 (onboarding checklist / „Objevte").

## 2. První faktura

**MyInvoice:** editor se vším podstatným — ARES, ceník, slevy, zaokrouhlení netto/brutto, QR,
immutable PDF/A-3b, e-mail s ISDOC (M-10, M-11). Jediná zkratka Ctrl+S; položky se vybírají
z ceníku vyhledáváním, ale bez ceníku (0 položek v instanci) se každý řádek píše ručně.

**Konkurence lépe:**
- Fakturoid: paleta příkazů Ctrl/Cmd+K, overlay zkratek Shift+?, tlačítko „Vytvořit a…"
  (https://www.fakturoid.cz/podpora/nastaveni/tipy-ke-zrychleni).
- Vyfakturuj: našeptávač řádků aktivovaný mezerníkem, funguje i v mobilu
  (https://podpora.redbit.cz/navod/naseptavac-radku-faktur/).

**Třecí místo TM-A2:** rychlost opakovaného vyplňování položek stojí a padá s (nenaplněným)
ceníkem; chybí inline našeptávání do řádku → N-001 (naplnit ceník), N-012 (našeptávání + zkratky).

## 3. Opakovaný klient

**MyInvoice:** pravidelné fakturace s režimy koncept/vystavit/vystavit+odeslat a unikátním
„otevřeným konceptem" pro paušál + vícepráce (M-12); klonování i hromadné „vystavit znovu".
V instanci nevyužito (0 šablon) — obě firmy zjevně fakturují ručně i opakující se položky.

**Konkurence:** funkčně srovnatelné (vždy v placeném tarifu). Vyfakturuj navíc umí zastavit
opakování, když klient neplatí (https://podpora.redbit.cz/navod/pravidelne-faktury/).

**Třecí místo TM-A3:** žádné — funkce existuje, jde o adopci (N-001).

## 4. Nezaplaceno

**MyInvoice:** dashboard zvýrazní po splatnosti, CRM počítá aging, DSO i risk skóre klienta
(M-08, M-21) — analyticky nejsilnější ze všech čtyř. ALE: bez napojené banky se úhrady evidují
ručně, takže stav „nezaplaceno" je jen tak čerstvý, jak poctivě se klikají platby. V instanci
je párování mrtvé (0 transakcí) a Fio nemá ani parser e-mailových avíz (seed má jen ČS).

**Konkurence lépe:**
- Všichni tři párují automaticky z e-mailových notifikací bank (iDoklad 13 bank
  https://www.idoklad.cz/podpora/nastaveni-banka; Vyfakturuj ~13 bank
  https://podpora.redbit.cz/navod/parovani-plateb-s-bankou/; Fakturoid 12 bank
  https://www.fakturoid.cz/podpora/automatizace/parovani-plateb-s-bankou).
- Fakturoid a Vyfakturuj (Profi) mají navíc přímé Fio API
  (https://www.fakturoid.cz/podpora/parovani/fio-api).
- iDoklad: haléřové vyrovnání — rozdíl do 1 Kč spáruje automaticky.

**Třecí místo TM-A4 (nejbolestivější v celém cyklu):** ruční evidence plateb u banky, kterou
MyInvoice umí číst (GPC výpisy Fio) a párovat — jen to není zapojené; a chybí pohodlná cesta
(Fio API / avíza) → N-003 (Fio), N-001 (adopce).

## 5. Upomínka

**MyInvoice:** automatické upomínky cronem s eskalací tónu, hromadné, vypnutí na 3 úrovních
(M-25); v instanci zapnuté (3 dny po splatnosti). Poděkování za platbu existuje, je vypnuté.

**Konkurence:** iDoklad až 10 upomínek na fakturu; Fakturoid má i připomínku před splatností
(https://www.fakturoid.cz/podpora/automatizace/upominky); Vyfakturuj časuje i před splatností.
Penále/úroky z prodlení nepočítá nikdo (MyInvoice i Fakturoid to říkají výslovně).

**Třecí místo TM-A5:** malé — chybí připomínka před splatností; penále je příležitost
k odlišení, ne dohánění → N-013 (před-splatnostní připomínka, penalizační podklad jako Could).

## 6. Konec měsíce (plátce DPH)

**MyInvoice:** nejsilnější část produktu — kniha DPH ke kontrole, DPHDP3/KH XML s klasifikacemi,
predikce DPH z konceptů, archiv podání; fork navíc: forma podání vč. dodatečných, hlídání termínů
podle pracovních dnů, validace EPO identity (M-29, M-30 + CUSTOMIZATIONS). Instance to reálně
používá (156 podání v archivu).

**Konkurence:** všichni generují XML jen v nejvyšších tarifech; Vyfakturuj jen „podklady"
(část řádků ručně), Fakturoid bez vývozu/dovozu, dodatečná podání nikdo.

**Třecí místo TM-A6:** žádné vůči konkurenci (jsme napřed). Interní: BEKRON nemá vyplněné
zdaňovací období (`vat_period`) — drobnost s dopadem na výkazy → N-001.

## 7. Podklady pro účetní

**MyInvoice:** hromadný měsíční ZIP (vydané PDF/ISDOC, přijaté s originály, výpisy, kniha DPH)
konzistentní s výkazy (M-34) — to konkurence v této podobě nemá. Ale předání je „člověk pošle
soubor"; účetní nemá vlastní přístup (v instanci žádný účet s rolí účetní), nic jí neřekne,
že podklady jsou připravené, a nic nehlídá, že je měsíc kompletní.

**Konkurence lépe:** viz [30_persona_ucetni.md](30_persona_ucetni.md) — hlavně Fakturoid
(role účetní zdarma + zámky + Krabice).

**Třecí místo TM-A7:** předání podkladů je jednosměrný ruční úkon → N-004 (přístup účetní),
N-006 (balíček období), N-005 (zamykání).

## 8. Konec roku

**MyInvoice:** daň z příjmů jen jako orientační podklad + XML kostra (M-32, výslovně
„foundation"); daňový optimalizátor srovná režimy (M-33) — pro OSVČ. Pro tuto instanci
(2× s.r.o.) je roční závěrka tak jako tak práce účetní; přehledy ČSSZ/ZP zde nejsou relevantní.

**Konkurence lépe (jen pro OSVČ):** Fakturoid generuje DPFO XML i oba přehledy pojištění
(https://www.fakturoid.cz/podpora/ucetnictvi/cssz-prehled); iDoklad DPFO s výpočtem.

**Třecí místo TM-A8:** pro tuto instanci nízká priorita (s.r.o.); pro obecný fork Could
(N-016 v backlogu).

## Souhrn třecích míst persony A

| # | Třecí místo | Vážnost | Řeší návrh |
|---|---|---|---|
| TM-A4 | Platby se evidují ručně, banka nenapojená (Fio) | 🔴 vysoká | N-001, N-003 |
| TM-A1 | Hotové funkce bez průvodce zůstávají vypnuté | 🔴 vysoká | N-001, N-011 |
| TM-A7 | Předání podkladů účetní = ruční ZIP bez zpětné vazby | 🔴 vysoká | N-004, N-005, N-006 |
| TM-A2 | Pomalé vyplňování položek (prázdný ceník, bez inline našeptávání) | 🟠 střední | N-001, N-012 |
| TM-A5 | Bez připomínky před splatností; penále neexistuje (nikde) | 🟡 nízká | N-013 |
| TM-A8 | Roční daně jen podklad (pro s.r.o. OK) | 🟡 nízká | N-016 |
| — | DDKPZ u přijaté zálohy od klienta se nevystaví sám a lhůtu nikdo nehlídá | 🔴 vysoká (legislativní) | N-002 |

Poslední řádek patří formálně do prodeje (kap. 2–3 cyklu): jakmile klient zaplatí zálohovou
fakturu, běží 15denní lhůta na vystavení DDKPZ (§ 28 odst. 8 ZDPH). Vyfakturuj i Fakturoid
doklad vystaví samy, iDoklad lhůtu aspoň hlídá notifikací — MyInvoice má jen ruční akci
nad platbou, bez hlídání. Pro plátce DPH je to největší legislativní riziko v celém cyklu.
