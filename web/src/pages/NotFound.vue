<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4 text-center bg-neutral-50">
    <div>
      <div class="text-6xl font-semibold text-primary-600 mb-2">404</div>
      <p class="text-neutral-500 mb-6">{{ t('errors.not_found') }}</p>
      <RouterLink to="/" class="text-primary-600 hover:underline">{{ t('nav.dashboard') }}</RouterLink>
    </div>
  </div>
</template>
