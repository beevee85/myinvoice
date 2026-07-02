import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné, takže je tu zrcadlíme ručně podle režimu.
 * POZOR: hodnoty musí odpovídat tokenům v styles/main.css (.dark scope) — při změně palety
 * srovnej i tady. Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
// Kategorická paleta pro grafy (rozlišení kategorií, ne sémantika). V dark posunutá do
// světlejších indigo tónů, aby nejtmavší segmenty nesplývaly s tmavým pozadím.
// FORK (beevee85): sladěno s custom-theme.css (indigo/slate) — při změně palety srovnej oba soubory.
const CHART_PALETTE_LIGHT = ['#3730A3', '#4F46E5', '#6366F1', '#818CF8', '#A5B4FC', '#C7D2FE', '#E0E7FF', '#F4A261', '#E8A547', '#4CAF7A']
const CHART_PALETTE_DARK = ['#A5B4FC', '#818CF8', '#C7D2FE', '#939CF9', '#E0E7FF', '#6366F1', '#DBE0FE', '#F4A261', '#E8A547', '#5FBF8E']

const chartColors = computed(() =>
  isDark.value
    ? { border: '#171F33', tick: '#A6B0C6', grid: '#253147', tooltipBg: '#2A3550', primary: '#6366F1', primarySoft: '#A5B4FC', palette: CHART_PALETTE_DARK }
    : { border: '#FFFFFF', tick: '#64748B', grid: '#E2E8F0', tooltipBg: '#0F172A', primary: '#4F46E5', primarySoft: '#A5B4FC', palette: CHART_PALETTE_LIGHT },
)

export function useChartColors() {
  return chartColors
}
