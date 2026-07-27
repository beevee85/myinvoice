<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'

type Option = { value: string | number; label: string }

const props = withDefaults(defineProps<{
  modelValue: string | number
  options: Option[]
  size?: 'sm' | 'md'
  disabled?: boolean
}>(), {
  size: 'md',
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const root = ref<HTMLDivElement | null>(null)

// když modelValue není v options, fokusovatelný zůstane první segment (roving tabindex)
const activeIdx = computed(() => {
  const i = props.options.findIndex(o => o.value === props.modelValue)
  return i === -1 ? 0 : i
})

function select(value: string | number) {
  if (props.disabled || value === props.modelValue) return
  emit('update:modelValue', value)
}

// šipky přepínají sousední segment s wrap-around + přesouvají fokus (vzor radiogroup)
function onKey(e: KeyboardEvent) {
  if (props.disabled) return
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
  e.preventDefault()
  const dir = e.key === 'ArrowRight' ? 1 : -1
  const idx = (activeIdx.value + dir + props.options.length) % props.options.length
  const next = props.options[idx]
  if (!next) return
  emit('update:modelValue', next.value)
  nextTick(() => {
    root.value?.querySelectorAll<HTMLButtonElement>('button[role="radio"]')[idx]?.focus()
  })
}
</script>

<template>
  <div
    ref="root"
    role="radiogroup"
    :class="[
      'inline-flex items-center gap-1 p-1 rounded-full bg-(--surface-muted)',
      size === 'sm' ? 'h-8' : 'h-10',
      disabled ? 'opacity-60' : '',
    ]"
    @keydown="onKey"
  >
    <button
      v-for="(o, i) in options"
      :key="String(o.value)"
      type="button"
      role="radio"
      :aria-checked="o.value === modelValue"
      :tabindex="i === activeIdx ? 0 : -1"
      :disabled="disabled"
      :class="[
        'cursor-pointer h-full rounded-full px-4 text-sm font-medium disabled:cursor-not-allowed',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
        o.value === modelValue ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:text-neutral-900',
      ]"
      @click="select(o.value)"
    >
      {{ o.label }}
    </button>
  </div>
</template>
