<script setup lang="ts">
type Kind = 'ok' | 'danger' | 'pending' | 'muted' | 'info'
type Size = 'sm' | 'md'

const props = withDefaults(defineProps<{
  kind: Kind
  /** Povinný popis stavu — jediný text komponenty (tooltip + aria-label). */
  title: string
  size?: Size
}>(), {
  size: 'md',
})

// kruh (pozadí + barva ikony) a path ikony pohromadě, aby kind nešel rozbít napůl
const KINDS: Record<Kind, { circle: string; path: string }> = {
  ok:      { circle: 'bg-success-50 text-success-600',  path: 'M5 13l4 4L19 7' },
  danger:  { circle: 'bg-danger-50 text-danger-500',    path: 'M6 18L18 6M6 6l12 12' },
  pending: { circle: 'bg-warning-50 text-warning-600',  path: 'M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
  muted:   { circle: 'bg-neutral-100 text-neutral-400', path: 'M5 12h14' },
  info:    { circle: 'bg-accent-50 text-accent-600',    path: 'M13 16h-1v-4h-1m1-4h.01' },
}

const CIRCLE_SIZES: Record<Size, string> = {
  sm: 'w-5 h-5',
  md: 'w-6 h-6',
}

const ICON_SIZES: Record<Size, string> = {
  sm: 'w-3 h-3',
  md: 'w-3.5 h-3.5',
}
</script>

<template>
  <span
    role="img"
    :aria-label="props.title"
    :title="props.title"
    :class="['inline-flex items-center justify-center rounded-full shrink-0', CIRCLE_SIZES[size], KINDS[kind].circle]"
  >
    <svg :class="ICON_SIZES[size]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="KINDS[kind].path" />
    </svg>
  </span>
</template>
