<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: boolean
  label?: string
  disabled?: boolean
  name?: string
  size?: 'sm' | 'md'
}>(), {
  label: '',
  disabled: false,
  size: 'md',
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'change': [value: boolean]
}>()

const trackClass = computed(() => props.size === 'sm' ? 'w-9 h-5' : 'w-11 h-6')
const knobClass = computed(() => props.size === 'sm' ? 'w-4 h-4' : 'w-5 h-5')
// posun = šířka tracku − knob − 2×2px inset
const knobOnClass = computed(() => props.size === 'sm' ? 'translate-x-4' : 'translate-x-5')

function onChange(e: Event) {
  const checked = (e.target as HTMLInputElement).checked
  emit('update:modelValue', checked)
  emit('change', checked)
}
</script>

<template>
  <label :class="['inline-flex items-center gap-2 select-none', disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer']">
    <!-- nativní checkbox zůstává v DOM (sr-only) — a11y i odeslání formuláře zadarmo -->
    <input
      type="checkbox"
      role="switch"
      class="peer sr-only"
      :checked="modelValue"
      :disabled="disabled"
      :name="name"
      @change="onChange"
    />
    <span
      :class="[
        'relative shrink-0 rounded-full transition-colors',
        'peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500/40',
        trackClass,
        modelValue ? 'bg-primary-600' : 'bg-neutral-300',
      ]"
      aria-hidden="true"
    >
      <span
        :class="[
          'absolute top-0.5 left-0.5 rounded-full bg-white shadow-sm transition-transform',
          knobClass,
          modelValue ? knobOnClass : 'translate-x-0',
        ]"
      ></span>
    </span>
    <span v-if="$slots.default || label" class="text-sm text-neutral-700">
      <slot>{{ label }}</slot>
    </span>
  </label>
</template>
