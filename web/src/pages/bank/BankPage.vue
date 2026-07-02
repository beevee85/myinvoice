<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import StatementList from './StatementList.vue'
import BankAccounts from '@/pages/admin/BankAccounts.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

// Sjednocená stránka „Bankovní účty" (Finance): výpisy + 3 admin záložky
// z bývalé stránky Systém → Bankovní účty. Ne-admin vidí jen výpisy.
type Tab = 'statements' | 'accounts' | 'balances' | 'email'
const ADMIN_TABS: Tab[] = ['accounts', 'balances', 'email']
const visibleTabs = computed<Tab[]>(() => (isAdmin.value ? ['statements', ...ADMIN_TABS] : ['statements']))

function tabFromQuery(q: unknown): Tab {
  const v = String(q ?? '')
  return isAdmin.value && (ADMIN_TABS as string[]).includes(v) ? (v as Tab) : 'statements'
}
const tab = ref<Tab>(tabFromQuery(route.query.tab))
watch(() => route.query.tab, (q) => { tab.value = tabFromQuery(q) })
// Role se může doresolvit až po mountu (session check) — přehodnoť deep-link ?tab=.
watch(isAdmin, () => { tab.value = tabFromQuery(route.query.tab) })

function switchTab(v: Tab) {
  if (tab.value === v) return
  // Výpisy = default bez ?tab (jejich filtry si query řídí samy a tab by přepsaly).
  router.replace({ query: v === 'statements' ? {} : { tab: v } })
}

function tabLabel(v: Tab): string {
  return v === 'statements' ? t('bank.title')
    : v === 'accounts' ? t('bank_accounts.tab_accounts')
    : v === 'balances' ? t('bank_accounts.tab_balances')
    : t('bank_accounts.tab_email_notices')
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('bank_accounts.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank_accounts.subtitle') }}</p>
    </div>

    <div v-if="visibleTabs.length > 1" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in visibleTabs" :key="tt"
        @click="switchTab(tt)"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tabLabel(tt) }}
      </button>
    </div>

    <StatementList v-if="tab === 'statements'" embedded />
    <BankAccounts v-else embedded />
  </div>
</template>
