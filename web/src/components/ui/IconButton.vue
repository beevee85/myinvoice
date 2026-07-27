<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

type Size = 'sm' | 'md'
type Variant = 'default' | 'onDark'

const props = withDefaults(defineProps<{
  /** Povinný popisek — ikona sama nic neříká; jde do aria-label i title. */
  label: string
  size?: Size
  /** onDark = pro použití na tmavém/barevném podkladu (header, hero). */
  variant?: Variant
  disabled?: boolean
  /** Interní odkaz — místo <button> se renderuje RouterLink. */
  to?: string
  /** Externí odkaz — místo <button> se renderuje <a>. */
  href?: string
}>(), {
  size: 'md',
  variant: 'default',
  disabled: false,
})

const SIZES: Record<Size, string> = {
  sm: 'w-8 h-8',
  md: 'w-10 h-10',
}

const VARIANTS: Record<Variant, string> = {
  default: 'text-neutral-500 hover:bg-(--surface-muted) hover:text-neutral-800',
  onDark:  'text-white/80 hover:bg-white/10 hover:text-white',
}

const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))
const isLink = computed(() => !!props.to || !!props.href)

// odkazy nemají atribut disabled → u nich klikání blokuje pointer-events-none níže
const attrs = computed<Record<string, unknown>>(() => {
  if (props.to) return { to: props.to }
  if (props.href) return { href: props.href }
  return { type: 'button', disabled: props.disabled }
})

const classes = computed(() => [
  'inline-flex items-center justify-center rounded-full shrink-0',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40',
  SIZES[props.size],
  VARIANTS[props.variant],
  props.disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer',
  isLink.value && props.disabled ? 'pointer-events-none' : '',
])
</script>

<template>
  <component
    :is="tag"
    v-bind="attrs"
    :class="classes"
    :aria-label="label"
    :title="label"
    :aria-disabled="isLink && disabled ? 'true' : undefined"
  >
    <!-- ikona: svg w-5 h-5 -->
    <slot />
  </component>
</template>
