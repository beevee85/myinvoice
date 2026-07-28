# DPH u záloh a daňový doklad k přijaté záloze (DDKPZ)

Rešerše pro implementaci typu dokladu „daňový doklad k přijaté záloze" v modulu
přijatých faktur. Právní stav: zákon č. 235/2004 Sb., o dani z přidané hodnoty
(ZDPH), ve znění účinném k 1. 1. 2026; Metodická informace GFŘ ke kontrolnímu
hlášení ve znění od 1. 1. 2024 (k 28. 7. 2026 poslední vydaná verze — platí
i pro 2026). Ověřeno 28. 7. 2026.

Poznámka ke zdrojům: zakonyprolidi.cz blokuje strojové stažení (HTTP 403),
doslovná znění byla proto ověřena ze zrcadel úplného znění k 1. 1. 2026
(podnikatel.cz / businesscenter.podnikatel.cz) a křížově z materiálů GFŘ.
Odkazy na zakonyprolidi.cz jsou uvedeny pro ruční ověření.

---

## 1. Kdy vzniká povinnost přiznat daň z přijaté úplaty — § 20a

- **§ 20a odst. 1:** povinnost přiznat daň vzniká ke dni uskutečnění
  zdanitelného plnění (DUZP).
- **§ 20a odst. 2:** „Je-li před uskutečněním zdanitelného plnění přijata
  úplata, vzniká povinnost přiznat daň z této úplaty ke dni jejího přijetí.
  **To neplatí, není-li zdanitelné plnění ke dni přijetí úplaty známo
  dostatečně určitě.**"
- **§ 20a odst. 3 — „dostatečná určitost":** plnění je známo dostatečně
  určitě, jsou-li známy alespoň:
  a) **zboží/služba**, které mají být dodány,
  b) **sazba daně** a
  c) **místo plnění**.
- Není-li některý z těchto údajů znám (poukazy na neurčené zboží, paušální
  zálohy na energie bez rozpadu sazeb apod.), daň se z přijaté úplaty
  **nepřiznává** a uplatní se až ke dni uskutečnění plnění — Informace GFŘ
  k § 20a, č. j. 41830/17/7100-20116-506729.

Zdroje: [§ 20a — úplné znění k 1. 1. 2026 (podnikatel.cz)](https://www.podnikatel.cz/zakony/zakon-c-235-2004-sb-o-dani-z-pridane-hodnoty/f2548574/) ·
[Informace GFŘ k § 20a (PDF)](https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Informace_GFR_k_20a.pdf) ·
ref. [zakonyprolidi.cz § 20a](https://www.zakonyprolidi.cz/cs/2004-235#p20a)

## 2. Lhůta pro vystavení daňového dokladu — § 28

- Povinnost vystavit daňový doklad při přijetí úplaty: **§ 28 odst. 1
  písm. d)** (přijetí úplaty, pokud před uskutečněním plnění vznikla povinnost
  přiznat daň ke dni přijetí úplaty).
- Lhůta: **§ 28 odst. 8** — doklad musí být vystaven **do 15 dnů ode dne, kdy
  vznikla povinnost přiznat daň** (tj. ode dne přijetí úplaty).
- ⚠ Pozor: v aktuálním znění je lhůta v **odst. 8** (starší články uvádějí
  odst. 5 — po novelách přečíslováno; ověřeno ve znění k 1. 1. 2026).
- § 28 odst. 11: povinnost vynaložit úsilí, aby se doklad dostal k příjemci
  ve lhůtě pro vystavení.

Zdroj: [Díl 5 — Daňové doklady, znění k 1. 1. 2026](https://businesscenter.podnikatel.cz/pravo/zakony/dph/f2548742/) ·
ref. [zakonyprolidi.cz § 28](https://www.zakonyprolidi.cz/cs/2004-235#p28)

## 3. Kdy se DDKPZ vystavovat nemusí

Zákon výslovnou výjimku neobsahuje; plyne z konstrukce lhůt v § 28:
**je-li plnění uskutečněno do 15 dnů od přijetí zálohy a v téže lhůtě vystaven
vyúčtovací (konečný) daňový doklad, který zálohu zúčtuje, je povinnost dle
§ 28 splněna jedním dokladem** a samostatný DDKPZ se nevystavuje.

- Potvrzení praxe: Portál POHODA — dojde-li k uskutečnění plnění do 15 dnů od
  přijetí zálohy, postačí jediný daňový doklad na celou transakci
  ([Jak na zálohy v DPH](https://portal.pohoda.cz/dane-ucetnictvi-mzdy/dph/jak-na-zalohy-v-dph/)).
- **Pozor:** sloučení dokladů nemění okamžik vzniku daňové povinnosti — daň
  z úplaty patří do období jejího přijetí (§ 20a odst. 2). Prakticky je
  jednodokladový postup bezproblémový, jen když záloha i plnění spadnou do
  téhož zdaňovacího období.
- Další případy, kdy se DDKPZ nevystavuje: plnění není dostatečně určité
  (§ 20a odst. 2 věta druhá) a **režim přenesení daňové povinnosti** (z přijaté
  zálohy se v RPDP daň nepřiznává, doklad se vystavuje až k plnění).

## 4. Zálohová faktura (proforma) není daňový doklad

- § 26 odst. 1: daňovým dokladem je písemnost splňující podmínky ZDPH;
  zálohová faktura nemá náležitosti § 29 (základ daně, sazbu, výši daně,
  DPPD) a zákon ji vůbec neupravuje — je to **jen výzva k platbě**.
- Nevstupuje do přiznání k DPH, do kontrolního hlášení ani do účetnictví;
  daňové účinky má až přijetí úplaty (→ DDKPZ) a uskutečnění plnění
  (→ vyúčtovací doklad).

## 5. § 37a — základ daně u vyúčtovacího (konečného) dokladu

- **Odst. 1:** základ daně při uskutečnění plnění = **rozdíl** mezi celkovým
  základem daně (§ 36 odst. 1) a **souhrnem základů daně z přijatých úplat**
  (§ 36 odst. 2). Na konečném dokladu se tedy daní jen doplatek (nebo
  přeplatek), nikdy hrubá celková částka.
- **Odst. 2 — sazba a kurz:**
  - rozdíl **kladný** (doplatek) → sazba a kurz platné **ke dni uskutečnění
    plnění** (písm. a);
  - rozdíl **záporný** (přeplatek, zálohy > konečná cena) → sazba a kurz,
    **které byly uplatněny při přiznání daně ze zálohy** (písm. b).
- **Odst. 3 — více záloh s různými sazbami** (jen u záporného rozdílu):
  použijí se sazby těch záloh, kterými vznikl/byl navýšen přeplatek. Výklad
  GFŘ: přeplatek se přiřazuje k zálohám **„od konce"** — vrací se nejdřív
  poslední zaplacená záloha (fikce), s její sazbou/kurzem.
- **Rozdíl = 0 (100% záloha):** celá daň byla přiznána už ze zálohy; z
  vyúčtovacího dokladu se nepřiznává nic a do přiznání ani KH nevstupuje
  (viz bod 6).
- Změny sazeb (např. konsolidační balíček od 1. 1. 2024, 15 %/10 % → 12 %):
  řeší přímo § 37a odst. 2–3 — doplatek novou sazbou, přeplatek historickou
  sazbou zálohy; daň přiznaná ze starých záloh se nepřepočítává (přechodná
  ustanovení čl. LXI zák. č. 349/2023 Sb.).

Zdroje: [§ 36–37a, úplné znění k 1. 1. 2026](https://www.podnikatel.cz/zakony/zakon-c-235-2004-sb-o-dani-z-pridane-hodnoty/f6436200/) ·
[Informace GFŘ k § 37a při změně sazeb, č. j. 2404/24/7100-30116-050822 (PDF, vč. číselných příkladů)](https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/INFORMACE-GFR_k_par37a_ZDPH-zaklad_dane_pri_zmene_sazeb.pdf) ·
ref. [zakonyprolidi.cz § 37a](https://www.zakonyprolidi.cz/cs/2004-235#p37a)

## 6. Kontrolní hlášení

Zdroj: **Metodická informace k vyplnění kontrolního hlášení DPH ke dni
1. 1. 2024**, č. j. 80390/23/7100-30118-012287
([PDF](https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Metodicka_informace_k_vyplneni_KH_20240101.pdf));
k 28. 7. 2026 nejnovější vydaná verze. Doplňkově
[Časté dotazy a odpovědi ke KH](https://financnisprava.gov.cz/cs/dane/dane/dan-z-pridane-hodnoty/kontrolni-hlaseni-dph/caste-dotazy-a-odpovedi)
(oddíly III, VI, VIII, XVI), na které Metodická informace u záloh výslovně
odkazuje.

| Situace | Doklad > 10 000 Kč vč. daně | Doklad ≤ 10 000 Kč vč. daně |
|---|---|---|
| Dodavatel — daň z přijaté zálohy (DDKPZ) | **A.4** | **A.5** (kumulativně) |
| Odběratel — odpočet z DDKPZ | **B.2** | **B.3** (kumulativně) |
| Vyúčtovací doklad (§ 37a) | jen rozdíl; limit z \|rozdílu\| → A.4/B.2 | jen rozdíl → A.5/B.3; rozdíl 0 → neuvádí se |

- **Limit 10 000 Kč:** hranice je **ostře „nad"** — doklad s hodnotou přesně
  10 000 Kč vč. daně patří do A.5/B.3 (FAQ III/6). Posuzuje se **celková
  hodnota dokladu včetně daně**, ne výše uplatněného odpočtu (FAQ III/4);
  u dobropisů/přeplatků v absolutní hodnotě.
- **Zálohy se posuzují samostatně** (Metodická informace, kap. 1.10): DDKPZ
  a vyúčtovací doklad jsou dva samostatné doklady, každý se svým limitem.
  FAQ XVI: „vyúčtovací faktura představuje samostatný daňový doklad, limit
  10 000 Kč se u ní posuzuje samostatně."
- **U konečné faktury se limit posuzuje z hodnoty rozdílu (doplatku /
  přeplatku) na dokladu, ne z hrubé ceny plnění** — FAQ VI/2: doplatek
  8 470 Kč z plnění za 60 500 Kč jde do A.5, protože „celková hodnota plnění
  na daňovém dokladu nepřesahuje 10 000 Kč"; přeplatek 15 730 Kč jde do A.4
  v negativní hodnotě.
- **DPPD u DDKPZ = den přijetí úplaty** (ne den vystavení) — oddíl A.4/B.2
  Metodické informace; u vyúčtovacího dokladu DPPD = DUZP.
- **Ev. číslo dokladu v B.2** se uvádí co nejpřesněji **podle dokladu
  dodavatele** (alfanumerické znaky ve správném pořadí) — tj. u nás sloupec
  `vendor_invoice_number`, ne interní číslo.
- **Rozdíl 0:** doklad s nulovým základem i daní se do KH neuvádí (nevzniká
  povinnost přiznat daň ani odpočet; dovozeno z kap. 1.1 a vazby KH na ř. 1/2
  resp. 40/41 přiznání — výslovně to pokyny neřeší, praxe jednotná).
- **Oprava chybného vykázání:** následné KH dle § 101f odst. 2 — do 5
  pracovních dnů od zjištění, podává se **kompletní znovu** (ne rozdílově).

## 7. DUZP vs. datum vystavení u DDKPZ

- Rozhodným dnem je **den přijetí úplaty** (§ 20a odst. 2) — běžně označovaný
  DPPD. U DDKPZ žádné DUZP neexistuje (plnění se ještě neuskutečnilo).
- Náležitost dokladu: § 29 odst. 1 — den vystavení (písm. g) a **den přijetí
  úplaty**, liší-li se od data vystavení (písm. h).
- Doklad smí být vystaven až 15 dnů po přijetí úplaty (i v dalším měsíci),
  ale daň i KH patří do období **přijetí úplaty**. Datum vystavení je pro
  zařazení do období irelevantní.

## 8. Nárok na odpočet u příjemce (odběratele) — § 72, § 73

- **Vznik nároku: § 72 odst. 5** (pozor, po novele č. 461/2024 Sb.
  přečíslováno z odst. 3) — nárok vzniká okamžikem, kdy nastaly skutečnosti
  zakládající povinnost daň přiznat, tj. u zálohy dnem, kdy dodavatel přijal
  úplatu.
- **Podmínka: § 73 odst. 1 písm. a)** — mít **daňový doklad** (DDKPZ).
  Samotné zaplacení zálohy nestačí.
- **Časově: § 73 odst. 2** — odpočet nejdříve za zdaňovací období, ve kterém
  má plátce doklad v držení.
- **Prekluze: § 73 odst. 3** — po novele 461/2024 Sb. nejpozději do konce
  **druhého kalendářního roku** následujícího po roce vzniku nároku
  (zkráceno z 3 let).
- V systému tomu odpovídá zařazení tuzemských přijatých dokladů do období
  podle `GREATEST(tax_date, issue_date)` (nárok až s dokladem).

## 9. Sazby DPH pro rok 2026 — § 47

- **Základní 21 %**, **snížená 12 %** (jediná snížená). Beze změny od
  1. 1. 2024 (zák. č. 349/2023 Sb.); pro 2026 žádná změna.

Zdroj: [Informace GFŘ ke změnám sazeb DPH od 1. 1. 2024 (PDF)](https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Informace_GFR_ke_zmenam_sazeb_DPH_od_1_1_2024.pdf) ·
ref. [zakonyprolidi.cz § 47](https://www.zakonyprolidi.cz/cs/2004-235#p47)

---

## Rozhodovací strom (předloha pro implementaci)

```
Přijata úplata (záloha) před uskutečněním plnění
│
├─ Plnění NENÍ ke dni úplaty dostatečně určité (§ 20a odst. 3)?
│    → daň se ze zálohy NEPŘIZNÁVÁ, DDKPZ se nevystavuje;
│      vše se daní až ke dni uskutečnění plnění (bez § 37a).
│
├─ Režim přenesení daňové povinnosti?
│    → z úplaty se daň nepřiznává, doklad až k plnění.
│
└─ Jinak: povinnost přiznat daň ke dni PŘIJETÍ úplaty (§ 20a odst. 2)
   │
   ├─ Do 15 dnů od přijetí úplaty uskutečněno plnění
   │  a vystaven vyúčtovací daňový doklad, který zálohu zúčtuje?
   │    → ANO: samostatný DDKPZ se nevystavuje (§ 28 splněn jedním
   │      dokladem); daň patří do období přijetí úplaty.
   │
   └─ NE: povinnost vystavit DDKPZ do 15 dnů (§ 28 odst. 1 písm. d,
      odst. 8)
      • DDKPZ: DPPD = den přijetí úplaty; vstupuje do přiznání
        (výstup ř. 1/2, vstup ř. 40/41) a do KH (A.4/A.5, B.2/B.3
        dle limitu 10 000 Kč vč. daně — ostře „nad").
      • Konečná faktura: základ daně = ROZDÍL dle § 37a; zúčtované
        zálohy se na dokladu uvedou (rekapitulačně, minusem), ale do
        přiznání ani KH podruhé nevstupují. Limit KH z |rozdílu|.
        Rozdíl 0 → doklad do přiznání/KH vůbec nevstupuje.
        Rozdíl < 0 → sazba zálohy (§ 37a odst. 2 písm. b, odst. 3
        — vracení „od konce").
```

---

## Exporty a výkazy — jak se DDKPZ propisuje (ověřeno 28. 7. 2026)

Aplikace sama kontrolní hlášení nepodává, správnost tedy stojí na exportech.
Níže je ověřený stav (testovací scénář: DDKPZ 20 000 Kč v 05/2026, DDKPZ
455 992 Kč v 06/2026, konečná faktura 475 992 Kč v 07/2026, dvě zálohové
faktury; XSD validace EPO i ISDOC prošly).

### Kontrolní hlášení a přiznání

| Doklad | KH | Přiznání |
|---|---|---|
| DDKPZ nad 10 000 Kč vč. daně | **B.2** — DIČ dodavatele, ev. číslo dokladu dodavatele, DPPD = den přijetí úplaty | ř. 40 (základ + daň) |
| DDKPZ do 10 000 Kč vč. daně | **B.3** kumulativně | ř. 40 |
| Konečná faktura, rozdíl § 37a ≠ 0 | B.2/B.3 podle **absolutní hodnoty rozdílu** | ř. 40 rozdílem |
| Konečná faktura, rozdíl § 37a = 0 | **neuvádí se** | nevstupuje |
| Zálohová faktura (`advance`) | nikdy | nikdy |

**Nulový rozdíl se do KH neuvádí.** Vyplývá to z konstrukce KH: do A.4/A.5 se
uvádí, co plátce přiznává na ř. 1/2, do B.2/B.3, z čeho uplatňuje odpočet na
ř. 40/41 (Metodická informace GFŘ ke KH, kap. 1.1 a 1.10). Číselně to potvrzují
[Časté dotazy FS, oddíl VI](https://financnisprava.gov.cz/cs/dane/dane/dan-z-pridane-hodnoty/kontrolni-hlaseni-dph/caste-dotazy-a-odpovedi):
u plnění za 60 500 Kč vč. daně jde do KH pouze doplatek 8 470 Kč (a to do A.5,
protože limit se posuzuje z hodnoty **doplatku**, ne z hodnoty plnění); přeplatek
se uvádí v záporné hodnotě. Nulový rozdíl proto nemá co vykázat.

⚠ **Pozor na výjimku:** tohle platí jen tam, kde byl na zálohu skutečně vystaven
daňový doklad. Pokud DDKPZ neexistuje (plnění nebylo při přijetí úplaty známo
dostatečně určitě — § 20a odst. 3), § 37a se nepoužije a do KH vstupuje **celá**
hodnota konečné faktury. Systém se řídí existencí spárovaného DDKPZ, ne vzhledem
tisku faktury.

### ISDOC

| Typ dokladu | `<DocumentType>` | `VATApplicable` |
|---|---|---|
| Faktura (i vyúčtovací) | 1 | true |
| Opravný doklad (dobropis) | 2 | true |
| **Zálohová faktura (nedaňový zálohový list)** | **4** | **false** |
| **Daňový doklad k přijaté záloze (daňový zálohový list)** | **5** | **true** |
| Dobropis k DZL | 6 | true |
| Zjednodušený daňový doklad | 7 | true |

Odpočet zdaněných záloh se v ISDOC **nevyjadřuje** přes `PaidDepositsAmount` —
ta patří výhradně **nedaňovým** zálohám a snižuje jen `PayableAmount`. Zdaněné
zálohy mají vlastní kolekci `<TaxedDeposits>/<TaxedDeposit>` (ID dokladu,
variabilní symbol, částka bez daně, částka s daní, sazba) a peněžně se projeví
trojicí `AlreadyClaimed*` v `TaxSubTotal` i `LegalMonetaryTotal`:

```
TaxableAmount              = celé plnění (předpis)
AlreadyClaimedTaxableAmount = základ ze zdaněných záloh
DifferenceTaxableAmount    = rozdíl podle § 37a  (obdobně TaxAmount / TaxInclusive)
```

Náš export konečné faktury s plně zúčtovanou zálohou tedy vypadá takto
(předpis 393 381,82 + 82 610,18, zálohy tytéž, rozdíl nula):

```xml
<TaxedDeposits>
  <TaxedDeposit><ID>ZD915260089</ID><VariableSymbol>815260087</VariableSymbol>
    <TaxableDepositAmount>16528.93</TaxableDepositAmount>
    <TaxInclusiveDepositAmount>20000.00</TaxInclusiveDepositAmount>
    <ClassifiedTaxCategory><Percent>21.00</Percent><VATCalculationMethod>0</VATCalculationMethod></ClassifiedTaxCategory>
  </TaxedDeposit>
  …
</TaxedDeposits>
<TaxSubTotal>
  <TaxableAmount>393381.82</TaxableAmount><TaxAmount>82610.18</TaxAmount>
  <AlreadyClaimedTaxableAmount>393381.82</AlreadyClaimedTaxableAmount>
  <AlreadyClaimedTaxAmount>82610.18</AlreadyClaimedTaxAmount>
  <DifferenceTaxableAmount>0.00</DifferenceTaxableAmount><DifferenceTaxAmount>0.00</DifferenceTaxAmount>
</TaxSubTotal>
```

Auto-odpočtové řádky § 37a se do `<InvoiceLines>` **nevypisují** (jinak by se
odpočet započítal dvakrát) a zálohová faktura (typ 4) nese nulovou rekapitulaci
DPH a žádné `TaxPointDate` — nedaňový doklad DUZP nemá.

### Pohoda XML

Pohoda v číselníku `invoiceTypeType` typ „přijatý daňový doklad k záloze" nemá.
DDKPZ proto jde jako **`receivedInvoice`** (daňový doklad na vstupu) — nikoli
jako `receivedAdvanceInvoice`, ta je vyhrazená nedaňové záloze. Rozpis DPH
(`homeCurrency`) nese DDKPZ v plné výši, konečná faktura s nulovým rozdílem
samé nuly. Zálohové faktury se exportují jako `receivedAdvanceInvoice`.

**Co Pohoda potřebuje pro správné zařazení do KH (ověřeno 28. 7. 2026):**

| Pole v XML | Význam pro KH | Stav |
|---|---|---|
| `typ:partnerIdentity/typ:dic` | bez DIČ dodavatele nelze doklad zařadit do **B.2** — spadl by do souhrnného **B.3** | doplňuje se z karty dodavatele, i když ho snapshot dokladu nemá |
| `inv:originalDocument` | pole „Doklad" = zdroj **evidenčního čísla daňového dokladu** pro B.2 | posílá se číslo dokladu dodavatele (ZD915260089) |
| `inv:symVar` | variabilní symbol platby (likvidace úhrad) | u přijatých se posílá **platební VS**, ne naše interní číslo |
| `typ:priceHigh` / `priceHighVAT` | základ a daň v 21% pásmu | dle dokladu |

Limit 10 000 Kč a rozdělení B.2/B.3 určuje **Pohoda sama** z hodnoty dokladu;
naším úkolem je dodat DIČ a evidenční číslo. Bez nich Pohoda doklad zařadí do
B.3 (souhrnně) a kontrolní hlášení se rozejde s dodavatelovým podáním.

⚠ **Pozor při hromadném exportu:** exportujete-li do jednoho balíčku zálohovou
fakturu i daňový doklad k záloze, vzniknou v Pohodě dva závazky (20 000 + 20 000).
Do závazků patří jen jeden z nich — Stormware pro tuto situaci doporučuje vést
DDKPZ jako interní doklad. Pro DPH a KH jsou obě varianty správně; jde o
platební stranu ([Stormware FAQ 2895](https://www.stormware.cz/podpora/faq/pohoda/157/?id=2895)).

### Praktická past při ručním pořízení DDKPZ

Kalkulátor počítá daň řádku ze sazby (16 528,93 × 21 % = **3 471,08**), doklad
dodavatele ale nese daň spočtenou shora z úplaty (20 000 × 21/121 = **3 471,07**).
Rozdíl haléře by se objevil v KH proti údaji dodavatele a rozbil párování na
finanční správě. Systém proto u DDKPZ používá rekapitulaci dle dokladu
(`vat_overrides`, § 73) a při párování § 37a přišpendlí odpočtovým řádkům
hodnoty **doslova z DDKPZ**; haléřový rozdíl se přesune na nejsilnější řádek
téže sazby, takže součet dokladu i výkazy zůstávají přesné. **Při ručním
pořízení DDKPZ vždy vyplňte rekapitulaci DPH přesně podle dokladu dodavatele.**

## Korekce vstupního zadání (zjištěno rešerší)

1. Lhůta 15 dnů je v § 28 **odst. 8** (ne odst. 5 — přečíslováno novelami).
2. Vznik nároku na odpočet je v § 72 **odst. 5** (ne odst. 3 — novela
   č. 461/2024 Sb.), prekluze odpočtu zkrácena na 2 kalendářní roky.
3. Limit KH: přesně 10 000 Kč vč. daně = ještě B.3/A.5 (hranice je ostrá).
4. U § 37a odst. 3 se u záporného rozdílu s více sazbami nepoužívá „nejvyšší
   sazba", ale sazby konkrétních záloh přiřazené „od konce".
