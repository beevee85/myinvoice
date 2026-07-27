<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'

type Tab = { value: string | number; label: string; count?: number }

const props = defineProps<{
  modelValue: string | number
  tabs: Tab[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const root = ref<HTMLDivElement | null>(null)

// když modelValue není v tabs, fokusovatelný zůstane první tab (roving tabindex)
const activeIdx = computed(() => {
  const i = props.tabs.findIndex(t => t.value === props.modelValue)
  return i === -1 ? 0 : i
})

// šipky přepínají sousední tab s wrap-around + přesouvají fokus
function onKey(e: KeyboardEvent) {
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
  e.preventDefault()
  const dir = e.key === 'ArrowRight' ? 1 : -1
  const idx = (activeIdx.value + dir + props.tabs.length) % props.tabs.length
  const next = props.tabs[idx]
  if (!next) return
  emit('update:modelValue', next.value)
  nextTick(() => {
    root.value?.querySelectorAll<HTMLButtonElement>('button[role="tab"]')[idx]?.focus()
  })
}
</script>

<template>
  <div
    ref="root"
    role="tablist"
    class="flex gap-6 border-b border-neutral-200 overflow-x-auto"
    @keydown="onKey"
  >
    <button
      v-for="(tab, i) in tabs"
      :key="String(tab.value)"
      type="button"
      role="tab"
      :aria-selected="tab.value === modelValue"
      :tabindex="i === activeIdx ? 0 : -1"
      :class="[
        'cursor-pointer relative pb-2.5 text-[15px] font-semibold whitespace-nowrap inline-flex items-center gap-1.5',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
        tab.value === modelValue ? 'text-primary-700' : 'text-neutral-500 hover:text-neutral-800',
      ]"
      @click="tab.value !== modelValue && emit('update:modelValue', tab.value)"
    >
      {{ tab.label }}
      <span
        v-if="tab.count !== undefined"
        :class="[
          'text-xs px-1.5 rounded-full',
          tab.value === modelValue ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600',
        ]"
      >{{ tab.count }}</span>
      <!-- spodní linka aktivního tabu -->
      <span
        v-if="tab.value === modelValue"
        class="absolute bottom-0 left-0 h-0.5 w-full rounded-full bg-primary-600"
        aria-hidden="true"
      ></span>
    </button>
  </div>
</template>
