<script setup lang="ts">
import { computed } from 'vue'

type Color = 'neutral' | 'primary' | 'success' | 'danger' | 'warning' | 'accent' | 'purple' | 'amber'
type Size = 'sm' | 'md'

const props = withDefaults(defineProps<{
  color?: Color
  size?: Size
}>(), {
  color: 'neutral',
  size: 'md',
})

// soft styl: světlé pozadí + tmavší text stejného odstínu (neutral má sytější podklad, aby nezanikl)
const COLORS: Record<Color, string> = {
  neutral: 'bg-neutral-100 text-neutral-600',
  primary: 'bg-primary-50 text-primary-700',
  success: 'bg-success-50 text-success-700',
  // danger/warning/accent nemají -700 token (upstream @theme) → -600 (AA na -50 tintu)
  danger:  'bg-danger-50 text-danger-600',
  warning: 'bg-warning-50 text-warning-600',
  accent:  'bg-accent-50 text-accent-600',
  purple:  'bg-purple-50 text-purple-700',
  amber:   'bg-amber-50 text-amber-700',
}

const SIZES: Record<Size, string> = {
  sm: 'text-[11px] px-2 py-0.5',
  md: 'text-xs px-2.5 py-1',
}

const classes = computed(() => [
  'inline-flex items-center rounded-full font-medium whitespace-nowrap',
  COLORS[props.color],
  SIZES[props.size],
])
</script>

<template>
  <span :class="classes">
    <slot />
  </span>
</template>
