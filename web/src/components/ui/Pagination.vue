<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppSelect from './AppSelect.vue'

/**
 * Stránkování seznamů: kruhová tlačítka (první/předchozí/okno čísel/další/poslední)
 * + volitelný výběr počtu položek na stránku (AppSelect vpravo).
 * Nevykresluje se vůbec, když je jediná stránka a per-page nedává smysl.
 */

const props = withDefaults(defineProps<{
  page: number
  pages: number
  perPage?: number
  perPageOptions?: number[]
  /** Default: zobrazit jen když je perPage definované. */
  showPerPage?: boolean
  disabled?: boolean
}>(), {
  perPageOptions: () => [25, 50, 100],
})

const emit = defineEmits<{
  'update:page': [value: number]
  'update:perPage': [value: number]
}>()

const { t } = useI18n()

// okno max 5 čísel, aktuální stránka uprostřed, na krajích se okno „opře" o hranici
const windowPages = computed(() => {
  const count = Math.min(5, props.pages)
  let start = props.page - Math.floor(count / 2)
  start = Math.max(1, Math.min(start, props.pages - count + 1))
  return Array.from({ length: count }, (_, i) => start + i)
})

// bez perPage není co vybírat, i kdyby showPerPage bylo true
const showSelect = computed(() => props.perPage !== undefined && props.showPerPage !== false)

const perPageOpts = computed(() =>
  props.perPageOptions.map(n => ({ value: n, label: String(n) })),
)

function go(p: number) {
  if (props.disabled) return
  const clamped = Math.min(Math.max(1, p), props.pages)
  if (clamped !== props.page) emit('update:page', clamped)
}

function onPerPage(v: string | number) {
  // AppSelect je obecný (string|number), per-page je vždy číslo
  emit('update:perPage', Number(v))
}

const btnBase = 'cursor-pointer w-10 h-10 rounded-full inline-flex items-center justify-center tabular-nums text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:opacity-40 disabled:pointer-events-none'
const btnIdle = 'text-neutral-600 hover:bg-(--surface-muted)'
</script>

<template>
  <div v-if="pages > 1 || perPage !== undefined" class="flex items-center justify-between gap-3 flex-wrap">
    <div v-if="pages > 1" class="flex items-center gap-1">
      <button
        type="button"
        :class="[btnBase, btnIdle]"
        :disabled="disabled || page <= 1"
        :aria-label="t('common.page_first')"
        @click="go(1)"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="m18.75 4.5-7.5 7.5 7.5 7.5m-6-15L5.25 12l7.5 7.5" />
        </svg>
      </button>
      <button
        type="button"
        :class="[btnBase, btnIdle]"
        :disabled="disabled || page <= 1"
        :aria-label="t('common.page_prev')"
        @click="go(page - 1)"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
        </svg>
      </button>

      <button
        v-for="p in windowPages"
        :key="p"
        type="button"
        :class="[btnBase, p === page ? 'bg-primary-600 text-white' : btnIdle]"
        :disabled="disabled"
        :aria-current="p === page ? 'page' : undefined"
        @click="go(p)"
      >
        {{ p }}
      </button>

      <button
        type="button"
        :class="[btnBase, btnIdle]"
        :disabled="disabled || page >= pages"
        :aria-label="t('common.page_next')"
        @click="go(page + 1)"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
      </button>
      <button
        type="button"
        :class="[btnBase, btnIdle]"
        :disabled="disabled || page >= pages"
        :aria-label="t('common.page_last')"
        @click="go(pages)"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="m5.25 4.5 7.5 7.5-7.5 7.5m6-15 7.5 7.5-7.5 7.5" />
        </svg>
      </button>
    </div>

    <div v-if="showSelect" class="ml-auto flex items-center gap-2">
      <span class="text-sm text-neutral-500">{{ t('common.per_page') }}</span>
      <AppSelect
        :model-value="perPage ?? null"
        :options="perPageOpts"
        size="sm"
        inline
        :disabled="disabled"
        :aria-label="t('common.per_page')"
        @update:model-value="onPerPage"
      />
    </div>
  </div>
</template>
