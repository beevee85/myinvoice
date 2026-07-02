// Formátování čísla bankovního účtu pro zobrazení.
//
// Banka v GPC/ABO výpisech číslo účtu zero-paduje (např. `0000000123456789`,
// `000019-0000000123456789`). Pro zobrazení vodicí nuly ořízneme — v předčíslí
// i v základní části. Volitelně připojíme kód banky jako „ / 0300" (vodicí nula
// v kódu je významná → nikdy se neořezává). U IBAN účtu nic neměníme ani
// nepřipojujeme kód banky (banku už kóduje sám). Hodnota pro API/filtr se NEmění —
// jde jen o kosmetiku displeje (backend si účet stejně normalizuje).

export function formatAccountNumber(
  account: string | null | undefined,
  bankCode?: string | null,
): string {
  if (account == null) return ''
  const raw = String(account).trim()
  if (raw === '') return ''

  // IBAN (2 písmena + 2 číslice na začátku) — neořezávat ani nepřipojovat kód banky.
  if (/^[A-Z]{2}\d{2}/i.test(raw)) return raw

  // Odděl kód banky uvedený přímo v čísle za '/'.
  const slash = raw.indexOf('/')
  const numPart = slash === -1 ? raw : raw.slice(0, slash)
  const inlineBank = slash === -1 ? '' : raw.slice(slash + 1).trim()

  // Předčíslí-základ: vodicí nuly ořízni (lookahead drží aspoň jednu číslici).
  const segs = numPart.split('-').map(s => s.replace(/^0+(?=\d)/, ''))

  let num: string
  if (segs.length === 2) {
    // Celé předčíslí z nul (např. „000000") zahoď — zobraz jen základ.
    const prefix = segs[0] === '' || segs[0] === '0' ? '' : segs[0]
    num = prefix ? `${prefix}-${segs[1]}` : segs[1]
  } else {
    num = segs[0]
  }

  // Kód banky: přednost má ten uvedený přímo v čísle, jinak předaný parametr.
  const code = inlineBank || (bankCode != null ? String(bankCode).trim() : '')
  return code !== '' ? `${num} / ${code}` : num
}
