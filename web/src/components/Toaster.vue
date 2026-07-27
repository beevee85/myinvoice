<script setup lang="ts">
import { useToast } from '@/composables/useToast'
const { toasts, dismiss } = useToast()

// Plné tokenové barvy — v .dark se přemapují samy. Info záměrně primary
// (neutral-800 se v dark přepíná na světlou → bílý text by nedržel kontrast).
function bgClass(type: string): string {
  return ({
    success: 'bg-success-600 text-white',
    error:   'bg-danger-500 text-white',
    warning: 'bg-warning-500 text-white',
    info:    'bg-primary-600 text-white',
  } as Record<string, string>)[type] || 'bg-primary-600 text-white'
}

// Heroicons outline (stroke) podle typu toastu; fallback = info.
const ICONS = {
  success: 'M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  error:   'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  warning: 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z',
  info:    'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
} as const
function iconPath(type: string): string {
  return (ICONS as Record<string, string>)[type] ?? ICONS.info
}
</script>

<template>
  <teleport to="body">
    <div class="fixed top-4 right-4 z-[100] flex flex-col gap-2 max-w-sm">
      <transition-group
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 translate-x-4"
        enter-to-class="opacity-100 translate-x-0"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-for="t in toasts" :key="t.id"
          class="rounded-xl shadow-lg px-4 py-3 text-sm flex items-start gap-2.5 min-w-64 max-w-96 cursor-pointer"
          :class="bgClass(t.type)"
          @click="dismiss(t.id)">
          <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath(t.type)" />
          </svg>
          <span class="flex-1 whitespace-pre-line">{{ t.text }}</span>
          <span class="text-lg leading-none opacity-60">×</span>
        </div>
      </transition-group>
    </div>
  </teleport>
</template>
