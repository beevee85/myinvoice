<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onBeforeUnmount, useId } from 'vue'

/**
 * Plnohodnotná náhrada nativního <select> (pilulkový trigger + listbox panel).
 * Nasazuje se postupně místo ~200 nativních <select> — proto drží stejný datový
 * kontrakt (update:modelValue + change, hidden input pro formuláře přes `name`).
 * Čistý listbox bez search inputu (na hledání je SearchableSelect).
 * Fokus zůstává celou dobu na triggeru — panel není fokusovatelný, navigovaná
 * řádka se čtečkám hlásí přes aria-activedescendant.
 */

type Option = { value: string | number; label: string; disabled?: boolean }

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  options: Option[]
  placeholder?: string
  size?: 'sm' | 'md'
  disabled?: boolean
  /** Form fallback: vyrenderuje hidden input, aby submit poslal stejná data jako nativní select. */
  name?: string
  ariaLabel?: string
  /** Trigger nezabírá celou šířku (w-auto) — pro použití v toolbarech. */
  inline?: boolean
}>(), {
  placeholder: '—',
  size: 'md',
  disabled: false,
  inline: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
  'change': [value: string | number]
}>()

const root = ref<HTMLDivElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const listbox = ref<HTMLUListElement | null>(null)
const open = ref(false)
const activeIdx = ref(-1)

// id per instance → unikátní id options pro aria-activedescendant i při více selectech na stránce
const uid = useId()
const optionId = (i: number) => `${uid}-opt-${i}`

// null i '' = prázdno (nativní selecty ve formulářích používají '' jako „nevybráno")
const isEmpty = computed(() => props.modelValue === null || props.modelValue === undefined || props.modelValue === '')
const selected = computed(() => {
  if (isEmpty.value) return null
  return props.options.find(o => o.value === props.modelValue) ?? null
})

function firstEnabled() {
  return props.options.findIndex(o => !o.disabled)
}
function lastEnabled() {
  for (let i = props.options.length - 1; i >= 0; i--) {
    if (!props.options[i]?.disabled) return i
  }
  return -1
}

function setActive(i: number) {
  if (i < 0 || i >= props.options.length) return
  activeIdx.value = i
  nextTick(() => {
    const el = listbox.value?.querySelector<HTMLElement>(`[data-idx="${i}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  })
}

function moveActive(dir: 1 | -1) {
  let i = activeIdx.value + dir
  // přeskoč disabled options
  while (i >= 0 && i < props.options.length && props.options[i]?.disabled) i += dir
  if (i >= 0 && i < props.options.length) setActive(i)
}

function openPanel() {
  if (props.disabled) return
  open.value = true
  const selIdx = props.options.findIndex(o => o.value === props.modelValue)
  setActive(selIdx >= 0 ? selIdx : firstEnabled())
}

function close() {
  open.value = false
}

function toggle() {
  if (props.disabled) return
  if (open.value) close()
  else openPanel()
}

function selectOption(o: Option) {
  if (o.disabled) return
  emit('update:modelValue', o.value)
  emit('change', o.value)
  close()
}

// ─── type-ahead: prefix labelu, buffer se maže po 700 ms (chování nativního selectu) ───
let typeBuffer = ''
let typeTimer: ReturnType<typeof setTimeout> | null = null
function typeahead(ch: string) {
  if (typeTimer) clearTimeout(typeTimer)
  typeBuffer += ch.toLowerCase()
  typeTimer = setTimeout(() => { typeBuffer = '' }, 700)
  if (!open.value) openPanel()
  const idx = props.options.findIndex(o => !o.disabled && o.label.toLowerCase().startsWith(typeBuffer))
  if (idx >= 0) setActive(idx)
}

function onTriggerKey(e: KeyboardEvent) {
  if (props.disabled) return
  const key = e.key
  if (!open.value) {
    if (key === 'Enter' || key === ' ' || key === 'ArrowDown') {
      e.preventDefault()
      openPanel()
    }
  } else {
    if (key === 'ArrowDown') {
      e.preventDefault()
      moveActive(1)
    } else if (key === 'ArrowUp') {
      e.preventDefault()
      moveActive(-1)
    } else if (key === 'Home') {
      e.preventDefault()
      setActive(firstEnabled())
    } else if (key === 'End') {
      e.preventDefault()
      setActive(lastEnabled())
    } else if (key === 'Enter' || key === ' ') {
      e.preventDefault()
      const o = props.options[activeIdx.value]
      if (o) selectOption(o)
    } else if (key === 'Escape') {
      e.preventDefault()
      close()
      trigger.value?.focus()
    } else if (key === 'Tab') {
      // fokus odchází dál — panel zavřít, ale Tab nechat proběhnout
      close()
    }
  }
  if (key.length === 1 && key !== ' ' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    typeahead(key)
  }
}

function onClickOutside(e: MouseEvent) {
  if (!root.value) return
  if (!root.value.contains(e.target as Node)) close()
}

onMounted(() => {
  document.addEventListener('mousedown', onClickOutside)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onClickOutside)
  if (typeTimer) clearTimeout(typeTimer)
})
</script>

<template>
  <div ref="root" :class="inline ? 'relative inline-block' : 'relative'">
    <!-- Form fallback: submit pošle stejná data jako s nativním selectem -->
    <input v-if="name" type="hidden" :name="name" :value="modelValue ?? ''" />

    <button
      ref="trigger"
      type="button"
      :disabled="disabled"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-label="ariaLabel"
      :class="[
        'relative inline-flex items-center cursor-pointer text-left rounded-full border border-neutral-200 bg-surface px-4 pr-9',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
        'disabled:opacity-60 disabled:cursor-not-allowed',
        inline ? 'w-auto' : 'w-full',
        size === 'sm' ? 'h-8 text-[13px]' : 'h-(--control-h) text-sm',
      ]"
      @click="toggle"
      @keydown="onTriggerKey"
    >
      <span class="block w-full truncate" :class="isEmpty ? 'text-neutral-400' : ''">
        {{ selected?.label ?? placeholder }}
      </span>
      <svg
        class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400 pointer-events-none"
        :class="{ 'rotate-180': open }"
        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <ul
      v-if="open"
      ref="listbox"
      role="listbox"
      :aria-label="ariaLabel"
      :aria-activedescendant="activeIdx >= 0 ? optionId(activeIdx) : undefined"
      class="absolute z-50 mt-1 min-w-full bg-surface rounded-lg shadow-lg border border-neutral-200 py-1 max-h-64 overflow-y-auto"
    >
      <li
        v-for="(o, i) in options"
        :key="String(o.value)"
        :id="optionId(i)"
        :data-idx="i"
        role="option"
        :aria-selected="o.value === modelValue"
        :aria-disabled="o.disabled || undefined"
        :class="[
          'flex items-center justify-between gap-2 px-3.5 py-2 text-sm cursor-pointer',
          o.disabled ? 'opacity-40 pointer-events-none' : '',
          o.value === modelValue
            ? 'bg-primary-50 text-primary-700'
            : (i === activeIdx ? 'bg-(--surface-muted)' : ''),
        ]"
        @click="selectOption(o)"
        @mouseenter="!o.disabled && (activeIdx = i)"
      >
        <span class="truncate">{{ o.label }}</span>
        <svg
          v-if="o.value === modelValue"
          class="w-4 h-4 shrink-0"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
      </li>
    </ul>
  </div>
</template>
