<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type ActionItemsResult } from '@/api/crm'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const actionItems = ref<ActionItemsResult | null>(null)
const openMenuIdx = ref<number | null>(null)

function toggleMenu(idx: number) {
  openMenuIdx.value = openMenuIdx.value === idx ? null : idx
}

async function dismissItem(itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') {
  try {
    await crmApi.dismissActionItem(itemType, mode)
    openMenuIdx.value = null
    actionItems.value = await crmApi.actionItems()
    toast.success(t('crm.action_items.dismissed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function restoreAllDismissed() {
  try {
    const r = await crmApi.restoreAllActionItems()
    actionItems.value = await crmApi.actionItems()
    toast.success(t('crm.action_items.restored_n', { n: r.restored }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  try {
    actionItems.value = await crmApi.actionItems()
  } catch {
    // tichý fail — widget se prostě nezobrazí
  }
})
</script>

<template>
  <!-- ═══ Action items widget (daily TODO) — karty s ikonou v kruhu ═══ -->
  <section v-if="actionItems && actionItems.total > 0" class="space-y-3">
    <header class="flex items-center justify-between gap-3 flex-wrap">
      <h2 class="flex items-center gap-2">
        {{ t('crm.action_items.title') }}
        <span class="px-2 py-0.5 bg-primary-600 text-white rounded-full text-xs font-medium tabular-nums">{{ actionItems.total }}</span>
      </h2>
      <button v-if="actionItems.dismissed_count > 0 && auth.canWrite" type="button" @click="restoreAllDismissed"
        class="cursor-pointer text-xs text-neutral-500 hover:text-primary-600 underline decoration-dotted">
        {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
      </button>
    </header>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-(--grid-gap)">
      <div v-for="(item, idx) in actionItems.items" :key="idx"
        class="relative bg-(--surface-muted) rounded-(--radius-card) p-4 hover:bg-neutral-100 transition-colors">
        <RouterLink :to="item.link" class="flex items-start gap-3 min-w-0 pr-8">
          <!-- Ikona v kruhu dle závažnosti -->
          <span class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
            :class="item.severity === 'high' ? 'bg-danger-50 text-danger-500' :
                    item.severity === 'medium' ? 'bg-warning-50 text-warning-600' : 'bg-neutral-100 text-neutral-500'">
            <svg v-if="item.severity === 'high'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <svg v-else-if="item.severity === 'medium'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9-3.75h.008v.008H12V8.25z"/>
            </svg>
          </span>
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-800">{{ item.title }}</div>
            <div class="text-xs text-neutral-500 mt-0.5">{{ item.hint }}</div>
          </div>
        </RouterLink>
        <div v-if="auth.canWrite" class="absolute top-3 right-3">
          <button type="button" @click.stop="toggleMenu(idx)"
            class="cursor-pointer inline-flex items-center justify-center w-7 h-7 rounded-full text-neutral-400 hover:text-neutral-700 hover:bg-surface"
            :title="t('crm.action_items.dismiss')">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
          </button>
          <div v-if="openMenuIdx === idx"
            class="absolute right-0 top-9 z-20 bg-surface border border-neutral-200 rounded-xl shadow-lg py-1 w-[280px]"
            @click.stop>
            <div class="px-3 py-1.5 text-xs text-neutral-500 font-semibold border-b border-neutral-100">
              {{ t('crm.action_items.dismiss_title') }}
            </div>
            <button type="button" @click="dismissItem(item.type, 'day')"
              class="cursor-pointer w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_day') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_day_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'week')"
              class="cursor-pointer w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_week') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_week_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'historical')"
              class="cursor-pointer w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              {{ t('crm.action_items.dismiss_historical') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_historical_hint') }}</div>
            </button>
            <button type="button" @click="dismissItem(item.type, 'forever')"
              class="cursor-pointer w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-danger-600 border-t border-neutral-100">
              {{ t('crm.action_items.dismiss_forever') }}
              <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_forever_hint') }}</div>
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══ Standalone restore hint — pro případ že total=0 ale jsou skryté ═══ -->
  <div v-else-if="actionItems && actionItems.dismissed_count > 0"
    class="bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2 flex items-center justify-between text-sm">
    <span class="text-neutral-500">
      {{ t('crm.action_items.all_clear_n_hidden', { n: actionItems.dismissed_count }) }}
    </span>
    <button v-if="auth.canWrite" type="button" @click="restoreAllDismissed"
      class="text-xs text-primary-600 hover:text-primary-700 underline decoration-dotted">
      {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
    </button>
  </div>
</template>
