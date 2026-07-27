<script setup lang="ts">
import { useI18n } from 'vue-i18n'
defineProps<{ title?: string }>()
const { t } = useI18n()
</script>

<template>
  <!-- Veřejný shell (login, setup, reset…): tmavá plocha app shellu, obsah plave uprostřed. -->
  <div class="min-h-screen flex flex-col bg-(--app-bg)">
    <header>
      <div class="max-w-6xl mx-auto px-6 py-5 flex items-center gap-3">
        <span class="w-9 h-9 rounded-lg bg-white flex items-center justify-center shrink-0">
          <img src="/styles/logo.svg" alt="MyInvoice" class="w-7 h-7" />
        </span>
        <div>
          <h1 class="text-lg font-semibold leading-tight text-white">
            My<span class="text-primary-300">Invoice</span><span class="text-white/50 font-normal">.cz</span>
          </h1>
          <p class="text-xs text-white/50 leading-tight">{{ title ?? t('app.subtitle') }}</p>
        </div>
      </div>
    </header>
    <main class="flex-1 flex items-center justify-center px-4 py-8">
      <slot />
    </main>
  </div>
</template>
