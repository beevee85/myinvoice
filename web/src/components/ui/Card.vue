<script setup lang="ts">
import { computed } from 'vue'

type Padding = 'none' | 'sm' | 'md'

const props = withDefaults(defineProps<{
  /** Tlumené pozadí — pro odlišení sekcí uvnitř bílé plochy. */
  muted?: boolean
  padding?: Padding
  /** Sémantický element (section, article…) — kvůli přístupné struktuře stránky. */
  as?: string
}>(), {
  muted: false,
  padding: 'md',
  as: 'div',
})

const PADDINGS: Record<Padding, string> = {
  none: 'p-0',
  sm:   'p-4',
  md:   'p-6',
}

const classes = computed(() => [
  'rounded-(--radius-card)',
  props.muted ? 'bg-(--surface-muted)' : 'bg-surface',
  PADDINGS[props.padding],
])
</script>

<template>
  <component :is="as" :class="classes">
    <slot />
  </component>
</template>
