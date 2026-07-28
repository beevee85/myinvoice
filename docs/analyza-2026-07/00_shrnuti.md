# 00 · Shrnutí — srovnávací analýza fakturačních systémů (červenec 2026)

Porovnal jsem MyInvoice (publikovaný manuál 42 kapitol + API a **reálný stav tvého forku**
včetně databáze instance) s veřejnými nápovědami iDokladu, Vyfakturuj a Fakturoidu
(vč. celé sekce pro účetní). Všechna tvrzení o konkurenci mají URL ve
[feature matici](10_feature_matrix.md); co se dohledat nepodařilo, je poctivě ❔.

> **⟳ Aktualizace 28. 7. večer.** Analýza vznikla ráno nad verzí 4.51.1. Během dne padlo
> 49 commitů a tři věci se změnily natolik, že jsem dokumenty srovnal s realitou:
> **(1)** upstream přijal náš PR #245 (opravy DPH výkazů) — vydáno ve v4.52.0, fork je na
> **4.52.1**; **(2)** dva Must návrhy jsou hotové a nasazené — **N-008** (DDKPZ na přijaté
> straně vč. § 37a, 4 doklady už v provozu) a **N-019** (koš dokladů s retencí 30 dní);
> **(3)** dodatečná přiznání DP3 (formy D/E) byla vypnuta, protože generovala plné částky místo
> rozdílů dle § 141/2 DŘ — **přestala tedy platit jedna z ranních konkurenčních výhod** a vznikl
> nový návrh **N-020**. Adopce (banka, ceník, účet účetní, `vat_period` BEKRONu) se nezměnila.

## Tři hlavní zjištění

**1. MyInvoice funkčně nezaostává — v daních a nákupu je nejsilnější ze všech čtyř.**
Kniha DPH, archiv podání, následné KH, platební příkazy s ověřením účtu v registru plátců,
AI extrakce bez kreditů, aging/DSO/cash-flow forecast, elektronické podpisy PDF a ⟳ nově DDKPZ
i na přijaté straně vč. § 37a — nic z toho konkurence v této úplnosti nemá, a co má, tak
zamčené v nejdražších tarifech. Fork není pozadu za upstreamem a ⟳ jeho opravy DPH výkazů
autor přijal (PR #245 → v4.52.0). Jediná výjimka: dodatečná DP3 jsou dočasně vypnutá (N-020),
takže tam zatím napřed nejsme.

**2. Největší reálný gap není v kódu, ale v adopci.** Instance nevyužívá hotové funkce, které
konkurence prodává jako hlavní přednosti: párování s bankou (0 výpisů — platby klikáš ručně),
ceník (0 položek), pravidelné fakturace (0), a hlavně **účetní nemá žádný přístup**, přestože
role, omezení na firmy i hromadné exporty jsou hotové. Půlka „konkurenčních výhod" iDokladu se
dá dohnat konfigurační seancí bez řádku kódu (návrh N-001).

**3. Skutečné funkční mezery jsou tři, a mají jasné vzory u konkurence:**
- **Automatický daňový doklad k přijaté platbě** — zákonná povinnost plátce (15 dnů, § 28
  ZDPH). Vyfakturuj i Fakturoid ho vystavují samy, iDoklad hlídá lhůtu; MyInvoice má na vydané
  straně jen ruční akci bez hlídání (N-002). ⟳ Přijatá strana (N-008) je od 28. 7. hotová.
- **Napojení banky bez ručních výpisů** — všichni tři párují z e-mailových avíz či Fio API;
  fork má celý párovací aparát, chybí jen Fio vstup (N-003).
- **Model spolupráce s účetní** — Fakturoid ukazuje, kam to dotáhnout: role účetní zdarma,
  zamykání dokladů s notifikací, Krabice na doklady, stav „posledního exportu" per klient.
  MyInvoice má lepší primitivy (multi-supplier + per-user omezení firem z forku), ale žádné
  workflow nad nimi (N-004, N-005, N-006, N-007).

Naopak **neřešit**: sklad, objednávky, interní doklady, EET, plné účetnictví — buď to nemá
smysl pro povahu produktu (vědomě zavrženo), nebo to nemá ani konkurence. Přehledy ČSSZ/ZP
a DPFO výpočet jsou pro tvoje dvě s.r.o. irelevantní (N-016 = Won't).

## TOP 10 návrhů podle poměru přínos/náklad

| # | Návrh | Proč právě tohle | Náročnost |
|---|---|---|---|
| 1 | **N-001 Adopce hotových funkcí** (banka, ceník, účet účetní, vat_period BEKRON) | Nula kódu, odblokuje persony A i B; bez toho nemá smysl stavět dál | S |
| 2 | **N-002 Auto DDKPZ + hlídání 15denní lhůty** (vydaná strana) | Jediná legislativní mezera; konkurence ji má celá vyřešenou | M |
| 3 | **N-003 Fio banka** (avíza hned, API pak) | Nejbolestivější třecí místo cyklu (ruční platby); matching aparát už existuje | S+M |
| ~~4~~ | ~~**N-008 DDKPZ přijatá strana**~~ | ✅ ⟳ **HOTOVO 28. 7.** — tři dávky, migrace 0904/0906–0911, 4 doklady v provozu | — |
| 5 | **N-005 Zamykání období** | Bez něj účetní nemůže věřit předaným datům; Fakturoid = hotový vzor | M–L |
| 6 | **N-004 Pozvánka pro účetní** | Odstraní tření vzniku přístupu (dnes admin zakládá ručně vč. hesla) | M |
| 7 | **N-007 E-mailový inbox dokladů** | Denní úspora práce; IMAP infrastruktura v kódu existuje, chybí jen napojení na doklady | M |
| 8 | **N-011 „Objevte" + kontextová nápověda** | Systémová prevence problému č. 2 (nevyužité funkce); manuál existuje, stačí ho propojit s UI | M (S pro první etapu) |
| ~~9~~ | ~~**N-019 Rozšíření koše**~~ | ✅ ⟳ **HOTOVO 28. 7.** — koš 0905 vč. retence 30 dní, cronu výsypu a hlídání vazeb DDKPZ | — |
| 10 | **N-006 Balíček období** (stav předání + kontrola úplnosti + „chybí doklad") | Největší kus modelu spolupráce s účetní; nejobjemnější položka TOP 10 a staví na N-005 — proto poslední | L |
| ⟳ nově | **N-020 Dopočet rozdílů pro dodatečné DP3** (§ 141/2 DŘ) | Vrací vypnutou funkci a je to nadstandard — neumí to nikdo z konkurence; nastupuje na uvolněné místo v Should | M |

Proč zrovna těchto deset: řadím podle (a) legislativní povinnosti, (b) odstranění denního
ručního tření, (c) hotovosti podkladů v kódu. Cenové nabídky (N-009) jsou jediný „konkurenční
standard", který v TOP 10 není — obchod této instance běží bez nabídek, takže velké L nemá
oporu v reálné potřebě; v backlogu jsou jako Could a kandidát na issue autorovi (převzetí
updatem je levnější než fork implementace). Platební brány, webhooky, push a štítky jsou
Could — přínosné, ale nic z nich neblokuje persony a vše má levnější alternativu (QR, API
pull, e-mail). Pozn. k CSV: u návrhů s dělenou náročností (např. N-003 avíza S / API M)
uvádí backlog konzervativní vyšší odhad.

## Jak číst zbytek

- [10_feature_matrix.md](10_feature_matrix.md) — srovnání ~70 schopností × 5 sloupců s URL
- [20_persona_uzivatel.md](20_persona_uzivatel.md) / [30_persona_ucetni.md](30_persona_ucetni.md)
  — roční cyklus a třecí místa (TM-A*, TM-B*)
- [40_navrhy.md](40_navrhy.md) — 19 karet návrhů s akceptačními kritérii
- [50_fork_odchylky.md](50_fork_odchylky.md) — fork vs. upstream vs. manuál, stav instance
- [60_backlog.csv](60_backlog.csv) — strojově zpracovatelný backlog (19 položek)
- [90_zdroje.md](90_zdroje.md) — všechny použité URL s datem snapshotu

Analýza nic neimplementuje; rozdělaných prací se nedotýká — jen na ně navazuje. ⟳ Obě
(DDKPZ přijatá strana N-008, koš dokladů N-019) byly během 28. 7. dokončeny a nasazeny.
