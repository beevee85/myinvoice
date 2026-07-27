<script setup lang="ts">
const props = withDefaults(defineProps<{
  modelValue: string | number | null
  value: string | number
  label?: string
  disabled?: boolean
  name?: string
}>(), {
  label: '',
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
  'change': [value: string | number]
}>()

function onChange() {
  emit('update:modelValue', props.value)
  emit('change', props.value)
}
</script>

<template>
  <label :class="['inline-flex items-center gap-2 select-none', disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer']">
    <!-- nativní input zůstává v DOM (sr-only) — a11y i odeslání formuláře zadarmo -->
    <input
      type="radio"
      class="peer sr-only"
      :checked="modelValue === value"
      :value="value"
      :disabled="disabled"
      :name="name"
      @change="onChange"
    />
    <span
      :class="[
        'shrink-0 w-[18px] h-[18px] rounded-full border bg-surface inline-flex items-center justify-center',
        'peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500/40',
        modelValue === value ? 'border-primary-600' : 'border-neutral-300',
      ]"
      aria-hidden="true"
    >
      <span v-if="modelValue === value" class="w-2.5 h-2.5 rounded-full bg-primary-600"></span>
    </span>
    <span v-if="$slots.default || label" class="text-sm text-neutral-700">
      <slot>{{ label }}</slot>
    </span>
  </label>
</template>
