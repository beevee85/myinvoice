<script lang="ts">
// Čisté pomocné funkce mimo setup — testovatelné bez mountu komponenty.
// Záměrně žádné UTC: new Date('YYYY-MM-DD') parsuje jako UTC půlnoc a v CZ
// zóně posouvá den, proto se všude pracuje se stringy a new Date(y, m, d).

export type DateParts = { y: number; m: number; d: number } // m = 1–12

const pad2 = (n: number): string => String(n).padStart(2, '0')

/** Kontrola reálného data — new Date normalizuje přetečení (32.1. → 1.2.), proto zpětné porovnání. */
export function isRealDate(p: DateParts): boolean {
  const dt = new Date(p.y, p.m - 1, p.d)
  return dt.getFullYear() === p.y && dt.getMonth() === p.m - 1 && dt.getDate() === p.d
}

export function partsToIso(p: DateParts): string {
  return `${p.y}-${pad2(p.m)}-${pad2(p.d)}`
}

export function isoToParts(iso: string): DateParts | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  if (!m || !m[1] || !m[2] || !m[3]) return null
  const p: DateParts = { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) }
  return isRealDate(p) ? p : null
}

/** ISO → české zobrazení 'dd.MM.yyyy'; nevalidní vstup → ''. */
export function formatCzechDate(iso: string): string {
  const p = isoToParts(iso)
  return p ? `${pad2(p.d)}.${pad2(p.m)}.${p.y}` : ''
}

/** Ruční zápis uživatele: '27.7.2026', '27. 7. 2026' i '2026-07-27' → ISO, jinak null. */
export function parseDateInput(text: string): string | null {
  const t = text.trim()
  let m = /^(\d{1,2})\s*\.\s*(\d{1,2})\s*\.\s*(\d{4})$/.exec(t)
  if (m && m[1] && m[2] && m[3]) {
    const p: DateParts = { y: Number(m[3]), m: Number(m[2]), d: Number(m[1]) }
    return isRealDate(p) ? partsToIso(p) : null
  }
  m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t)
  if (m && m[1] && m[2] && m[3]) {
    const p: DateParts = { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) }
    return isRealDate(p) ? partsToIso(p) : null
  }
  return null
}

/** ISO stringy se řadí lexikograficky shodně s časovou osou → stačí porovnání stringů. */
export function isoInRange(iso: string, min?: string, max?: string): boolean {
  if (min && iso < min) return false
  if (max && iso > max) return false
  return true
}

export type DayCell = { iso: string; day: number; inMonth: boolean }

/** 42 buněk (6 týdnů, pondělí první) — stabilní výška popoveru napříč měsíci. */
export function buildMonthGrid(year: number, month0: number): DayCell[] {
  const offset = (new Date(year, month0, 1).getDay() + 6) % 7 // getDay: 0=neděle → posun na pondělí
  const cells: DayCell[] = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(year, month0, 1 - offset + i)
    cells.push({
      iso: partsToIso({ y: d.getFullYear(), m: d.getMonth() + 1, d: d.getDate() }),
      day: d.getDate(),
      inMonth: d.getMonth() === month0,
    })
  }
  return cells
}

export function todayIso(): string {
  const d = new Date()
  return partsToIso({ y: d.getFullYear(), m: d.getMonth() + 1, d: d.getDate() })
}

/** Intl vrací české měsíce/dny malým písmenem → kapitalizace pro hlavičku kalendáře. */
export function capitalizeFirst(s: string, locale?: string): string {
  return s.charAt(0).toLocaleUpperCase(locale) + s.slice(1)
}
</script>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  /** ISO 'YYYY-MM-DD' nebo '' — stejný datový kontrakt jako nativní <input type="date"> */
  modelValue: string
  min?: string
  max?: string
  disabled?: boolean
  placeholder?: string
  /** Form fallback: vyrenderuje <input type="hidden"> se stejnou ISO hodnotou */
  name?: string
  size?: 'sm' | 'md'
  ariaLabel?: string
  todayLabel?: string
  clearLabel?: string
  prevMonthLabel?: string
  nextMonthLabel?: string
}>(), {
  disabled: false,
  placeholder: 'dd.mm.rrrr',
  size: 'md',
  todayLabel: 'Dnes',
  clearLabel: 'Vymazat',
  prevMonthLabel: 'Předchozí měsíc',
  nextMonthLabel: 'Další měsíc',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'change': [value: string]
}>()

const { locale } = useI18n()

const root = ref<HTMLDivElement | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
const open = ref(false)
const text = ref('')

const now = new Date()
const viewYear = ref(now.getFullYear())
const viewMonth = ref(now.getMonth()) // 0-based
const todayStr = ref(todayIso())

// Zobrazovací text drží krok s modelValue (i při externí změně přes v-model).
watch(() => props.modelValue, (v) => {
  text.value = v ? formatCzechDate(v) : ''
  const p = isoToParts(v)
  if (p) {
    viewYear.value = p.y
    viewMonth.value = p.m - 1
  }
}, { immediate: true })

const monthLabel = computed(() => capitalizeFirst(
  new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' })
    .format(new Date(viewYear.value, viewMonth.value, 1)),
  locale.value,
))

const weekdayLabels = computed(() => {
  const fmt = new Intl.DateTimeFormat(locale.value, { weekday: 'short' })
  // 5.1.2026 je pondělí → referenční týden pro pořadí Po–Ne
  return Array.from({ length: 7 }, (_, i) =>
    capitalizeFirst(fmt.format(new Date(2026, 0, 5 + i)), locale.value))
})

const cells = computed(() => buildMonthGrid(viewYear.value, viewMonth.value))
const todayInRange = computed(() => isoInRange(todayStr.value, props.min, props.max))

function dayDisabled(iso: string): boolean {
  return !isoInRange(iso, props.min, props.max)
}

function emitValue(iso: string) {
  text.value = iso ? formatCzechDate(iso) : ''
  if (iso !== props.modelValue) {
    emit('update:modelValue', iso)
    emit('change', iso)
  }
}

/** Blur/Enter: validní zápis → emit ISO; prázdný → emit ''; nevalidní → návrat k poslední platné hodnotě. */
function commitText() {
  const t = text.value.trim()
  if (t === '') {
    emitValue('')
    return
  }
  const iso = parseDateInput(t)
  // Datum mimo min/max bereme jako nevalidní — stejný záměr jako disabled dny v kalendáři.
  if (iso && isoInRange(iso, props.min, props.max)) {
    emitValue(iso)
  } else {
    text.value = props.modelValue ? formatCzechDate(props.modelValue) : ''
  }
}

function openPopover() {
  if (props.disabled) return
  todayStr.value = todayIso()
  // Kalendář otevíráme na rozepsaném (ještě nepotvrzeném) datu, jinak na hodnotě / dnešku.
  const base = parseDateInput(text.value) ?? (props.modelValue || null)
  const p = base ? isoToParts(base) : null
  if (p) {
    viewYear.value = p.y
    viewMonth.value = p.m - 1
  } else {
    const n = new Date()
    viewYear.value = n.getFullYear()
    viewMonth.value = n.getMonth()
  }
  open.value = true
}

function closePopover(focusBack = false) {
  open.value = false
  if (focusBack) inputEl.value?.focus()
}

function togglePopover() {
  if (open.value) closePopover()
  else openPopover()
}

function shiftMonth(delta: number) {
  const d = new Date(viewYear.value, viewMonth.value + delta, 1)
  viewYear.value = d.getFullYear()
  viewMonth.value = d.getMonth()
}

function selectDay(iso: string) {
  emitValue(iso)
  closePopover(true)
}

function pickToday() {
  if (!todayInRange.value) return
  emitValue(todayStr.value)
  closePopover(true)
}

function clearValue() {
  emitValue('')
  closePopover(true)
}

function onInputKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowDown' && !open.value) {
    e.preventDefault()
    openPopover()
  } else if (e.key === 'Enter') {
    commitText()
    if (open.value) {
      // otevřený popover: Enter jen potvrdí zápis, nesmí odeslat formulář
      e.preventDefault()
      closePopover(true)
    }
  }
}

// Na rootu, aby Escape/PageUp/PageDown fungovaly i s fokusem uvnitř popoveru.
function onRootKeydown(e: KeyboardEvent) {
  if (!open.value) return
  if (e.key === 'Escape') {
    e.stopPropagation() // ať Escape nezavře i nadřazený modal
    closePopover(true)
  } else if (e.key === 'PageUp') {
    e.preventDefault()
    shiftMonth(-1)
  } else if (e.key === 'PageDown') {
    e.preventDefault()
    shiftMonth(1)
  }
}

function onDocMousedown(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) {
    open.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onDocMousedown))
onUnmounted(() => document.removeEventListener('mousedown', onDocMousedown))
</script>

<template>
  <div ref="root" class="relative" @keydown="onRootKeydown">
    <div class="relative">
      <input
        ref="inputEl"
        v-model="text"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-label="ariaLabel"
        :class="[
          'w-full rounded-(--radius-input) border border-neutral-200 bg-surface px-3 pr-9 tabular-nums',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
          'disabled:bg-neutral-50 disabled:text-neutral-400',
          size === 'sm' ? 'h-8 text-[13px]' : 'h-(--control-h) text-sm',
        ]"
        @keydown="onInputKeydown"
        @blur="commitText"
      />
      <button
        type="button"
        :disabled="disabled"
        :aria-label="ariaLabel ?? 'Kalendář'"
        :aria-expanded="open"
        aria-haspopup="dialog"
        class="cursor-pointer absolute right-2 top-1/2 -translate-y-1/2 inline-flex h-6 w-6 items-center justify-center rounded-full text-neutral-400 hover:text-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:cursor-default disabled:opacity-50"
        @click="togglePopover"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
        </svg>
      </button>
    </div>

    <!-- form fallback — stejná ISO hodnota jako u nativního date inputu -->
    <input v-if="name" type="hidden" :name="name" :value="modelValue" />

    <div
      v-if="open"
      role="dialog"
      :aria-label="ariaLabel ?? 'Kalendář'"
      class="absolute left-0 z-50 mt-1 w-72 rounded-(--radius-card) border border-neutral-200 bg-surface p-3 shadow-lg"
    >
      <!-- hlavička: měsíc + navigace -->
      <div class="flex items-center justify-between">
        <button
          type="button"
          :aria-label="prevMonthLabel"
          class="cursor-pointer inline-flex h-8 w-8 items-center justify-center rounded-full text-neutral-500 hover:bg-(--surface-muted) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
          @click="shiftMonth(-1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <div class="text-sm font-medium">{{ monthLabel }}</div>
        <button
          type="button"
          :aria-label="nextMonthLabel"
          class="cursor-pointer inline-flex h-8 w-8 items-center justify-center rounded-full text-neutral-500 hover:bg-(--surface-muted) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
          @click="shiftMonth(1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
          </svg>
        </button>
      </div>

      <!-- záhlaví dnů (pondělí první) -->
      <div class="mt-2 grid grid-cols-7 justify-items-center text-xs text-neutral-500">
        <div v-for="w in weekdayLabels" :key="w" class="flex h-7 w-9 items-center justify-center">{{ w }}</div>
      </div>

      <!-- dny -->
      <div class="grid grid-cols-7 justify-items-center">
        <button
          v-for="c in cells"
          :key="c.iso"
          type="button"
          :disabled="dayDisabled(c.iso)"
          :class="[
            'cursor-pointer inline-flex h-9 w-9 items-center justify-center rounded-full text-sm',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
            'disabled:cursor-default disabled:opacity-30',
            c.iso === modelValue ? 'bg-primary-600 text-white' : 'hover:bg-(--surface-muted)',
            c.iso !== modelValue && !c.inMonth ? 'text-neutral-300' : '',
            c.iso !== modelValue && c.iso === todayStr ? 'ring-1 ring-primary-400' : '',
          ]"
          @click="selectDay(c.iso)"
        >{{ c.day }}</button>
      </div>

      <!-- patička -->
      <div class="mt-2 flex items-center justify-between">
        <button
          type="button"
          :disabled="!todayInRange"
          class="cursor-pointer rounded text-xs text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:pointer-events-none disabled:opacity-40"
          @click="pickToday"
        >{{ todayLabel }}</button>
        <button
          type="button"
          class="cursor-pointer rounded text-xs text-neutral-500 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
          @click="clearValue"
        >{{ clearLabel }}</button>
      </div>
    </div>
  </div>
</template>
