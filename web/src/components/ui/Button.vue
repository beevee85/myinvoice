<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

type Variant = 'primary' | 'cta' | 'secondary' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg'

const props = withDefaults(defineProps<{
  variant?: Variant
  size?: Size
  type?: 'button' | 'submit'
  disabled?: boolean
  loading?: boolean
  /** Interní odkaz — místo <button> se renderuje RouterLink. */
  to?: string
  /** Externí odkaz — místo <button> se renderuje <a>. */
  href?: string
  /** Roztáhnout na celou šířku rodiče (w-full). */
  block?: boolean
}>(), {
  variant: 'primary',
  size: 'md',
  type: 'button',
  disabled: false,
  loading: false,
  block: false,
})

const VARIANTS: Record<Variant, string> = {
  primary:   'bg-primary-600 hover:bg-primary-700 text-white',
  cta:       'bg-(--accent-cta) hover:bg-success-700 text-white',
  secondary: 'border-[1.5px] border-primary-600 text-primary-700 hover:bg-primary-50 bg-transparent',
  ghost:     'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900',
  danger:    'bg-danger-500 hover:bg-danger-600 text-white',
}

const SIZES: Record<Size, string> = {
  sm: 'h-8 px-3.5 text-[13px]',
  md: 'h-10 px-4 text-sm',
  lg: 'h-11 px-5 text-sm',
}

// loading blokuje interakci stejně jako disabled (žádné dvojité odeslání během requestu)
const isDisabled = computed(() => props.disabled || props.loading)

const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))
const isLink = computed(() => !!props.to || !!props.href)

// odkazy nemají atribut disabled → u nich klikání blokuje pointer-events-none níže
const attrs = computed<Record<string, unknown>>(() => {
  if (props.to) return { to: props.to }
  if (props.href) return { href: props.href }
  return { type: props.type, disabled: isDisabled.value }
})

const classes = computed(() => [
  'inline-flex items-center justify-center gap-2 font-medium whitespace-nowrap rounded-full',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
  SIZES[props.size],
  VARIANTS[props.variant],
  props.block ? 'w-full' : '',
  isDisabled.value ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer',
  isLink.value && isDisabled.value ? 'pointer-events-none' : '',
])
</script>

<template>
  <component
    :is="tag"
    v-bind="attrs"
    :class="classes"
    :aria-disabled="isLink && isDisabled ? 'true' : undefined"
    :aria-busy="loading || undefined"
  >
    <svg v-if="loading" class="w-4 h-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
      <path class="opacity-75" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2z" />
    </svg>
    <slot />
  </component>
</template>
