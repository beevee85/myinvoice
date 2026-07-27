<script setup lang="ts">
import { RouterLink } from 'vue-router'

/**
 * Prázdný stav seznamů/detailů. API drží zpětnou kompatibilitu (title/message/cta/to,
 * používá ho 5 stránek); sloty #icon a #actions umožňují nahradit ilustraci resp. CTA.
 */

defineProps<{
  title: string
  message?: string
  cta?: string
  to?: string
}>()
</script>

<template>
  <div class="py-12 text-center">
    <slot name="icon">
      <!-- Stylizovaná faktura: barvy jen přes CSS proměnné + currentColor, aby fungoval dark mode -->
      <svg class="mx-auto mb-4" width="120" height="96" viewBox="0 0 120 96" fill="none" aria-hidden="true">
        <circle cx="60" cy="50" r="42" fill="currentColor" style="color: var(--color-neutral-100)" />
        <!-- zadní list (mírně natočený, jen obrys) -->
        <g transform="rotate(-8 52 47)">
          <rect
            x="30" y="19" width="44" height="56" rx="6"
            stroke="currentColor" stroke-width="2"
            style="color: var(--color-primary-300); fill: var(--color-primary-50)"
          />
        </g>
        <!-- přední list -->
        <rect
          x="44" y="22" width="46" height="58" rx="6"
          stroke="currentColor" stroke-width="2"
          style="color: var(--color-primary-300); fill: var(--color-primary-50)"
        />
        <!-- řádky textu -->
        <g stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color: var(--color-primary-300)">
          <path d="M52 34h22" />
          <path d="M52 42h30" />
          <path d="M52 50h30" />
          <path d="M52 58h16" />
        </g>
        <!-- „razítko" -->
        <circle cx="78" cy="67" r="8" stroke="currentColor" stroke-width="2" style="color: var(--color-primary-600)" />
        <circle cx="78" cy="67" r="3" fill="currentColor" style="color: var(--color-primary-600)" />
      </svg>
    </slot>

    <p class="text-base font-semibold text-neutral-800">{{ title }}</p>
    <p v-if="message" class="text-sm text-neutral-500 max-w-sm mx-auto mt-1">{{ message }}</p>

    <div v-if="$slots.actions || (cta && to)" class="mt-5">
      <slot name="actions">
        <RouterLink
          v-if="cta && to"
          :to="to"
          class="cursor-pointer inline-flex items-center h-10 px-5 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
        >
          {{ cta }}
        </RouterLink>
      </slot>
    </div>
  </div>
</template>
