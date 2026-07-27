<script setup lang="ts">
import { ref, watchEffect } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: boolean
  label?: string
  disabled?: boolean
  name?: string
  indeterminate?: boolean
}>(), {
  label: '',
  disabled: false,
  indeterminate: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'change': [value: boolean]
}>()

const input = ref<HTMLInputElement | null>(null)

// indeterminate nelze nastavit HTML atributem — jen JS vlastností na elementu
watchEffect(() => {
  if (input.value) input.value.indeterminate = props.indeterminate
})

function onChange(e: Event) {
  const checked = (e.target as HTMLInputElement).checked
  emit('update:modelValue', checked)
  emit('change', checked)
}
</script>

<template>
  <label :class="['inline-flex items-center gap-2 select-none', disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer']">
    <!-- nativní input zůstává v DOM (sr-only) — a11y i odeslání formuláře zadarmo -->
    <input
      ref="input"
      type="checkbox"
      class="peer sr-only"
      :checked="modelValue"
      :disabled="disabled"
      :name="name"
      @change="onChange"
    />
    <span
      :class="[
        'shrink-0 w-[18px] h-[18px] rounded-[6px] border inline-flex items-center justify-center',
        'peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500/40',
        (modelValue || indeterminate) ? 'bg-primary-600 border-primary-600' : 'bg-surface border-neutral-300',
      ]"
      aria-hidden="true"
    >
      <!-- indeterminate (pomlčka) má přednost před fajfkou -->
      <svg v-if="indeterminate" class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
      </svg>
      <svg v-else-if="modelValue" class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
      </svg>
    </span>
    <span v-if="$slots.default || label" class="text-sm text-neutral-700">
      <!-- slot má přednost před props.label -->
      <slot>{{ label }}</slot>
    </span>
  </label>
</template>
