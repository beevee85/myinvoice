import type { VatRate } from '@/api/codebooks'

/**
 * Popisek sazby DPH — JEDINÝ zdroj pravdy pro celý frontend.
 *
 * Zdrojem je číselník (`vat_rates.label_cs` / `label_en`), ne dopočet z procenta:
 * jinak by dvě různé 0% sazby vyšly stejně. Konkrétně „Osvobozeno" (CZ-0) a
 * „Mimo DPH" (CZ-NA) jsou daňově ROZDÍLNÉ věci — osvobozené plnění se vykazuje
 * v přiznání, plnění mimo předmět daně ne — a v selectu musejí jít rozlišit.
 *
 * Fallback (číselník bez popisku) zůstává jen jako pojistka pro starší data.
 */
export function vatRateLabel(rate: VatRate, locale: string, t?: (key: string) => string): string {
  const prefix = rate.country && rate.country !== 'CZ' ? `${rate.country} ` : ''
  const label = locale.startsWith('en') ? rate.label_en : rate.label_cs
  if (label && label.trim() !== '') {
    return `${prefix}${label}`
  }
  // Fallback — číselník bez popisku (legacy záznam).
  if (Number(rate.rate_percent) > 0) return `${prefix}${rate.rate_percent} %`
  if (rate.is_reverse_charge) return `${prefix}${t ? t('invoice.vat_rate_label.reverse_charge') : 'Reverse charge'}`
  return `${prefix}${t ? t('invoice.vat_rate_label.exempt') : '0 %'}`
}

/**
 * Popisek sazby pro místa, kde máme jen kód a procento z uloženého dokladu
 * (položky, rozpis DPH) — např. `vat_code='CZ-NA'`, `vat_rate_snapshot=0`.
 * Vrací popisek z dokladu (join na číselník), jinak „X %".
 */
export function vatRateSnapshotLabel(
  ratePercent: number | string | null | undefined,
  locale: string,
  labelCs?: string | null,
  labelEn?: string | null,
): string {
  const label = locale.startsWith('en') ? labelEn : labelCs
  const pct = Number(ratePercent ?? 0)
  if (pct > 0) {
    // U nenulových sazeb je procento jednoznačné a čitelnější než text číselníku.
    return `${pct} %`
  }
  return label && label.trim() !== '' ? label : '0 %'
}
