<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { cashDocumentsApi, type CashDocument, type CashDocumentKind } from '@/api/cashDocuments'
import { clientsApi } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const docs = ref<CashDocument[]>([])
const loading = ref(false)
const yearFilter = ref<number>(new Date().getFullYear())
const kindFilter = ref<'' | CashDocumentKind>('')

const canWrite = computed(() => auth.user?.role === 'admin' || auth.user?.role === 'accountant')

const showForm = ref(false)
useHotkey('escape', () => { if (showForm.value) showForm.value = false })

const form = reactive({
  id: null as number | null,
  kind: 'income' as CashDocumentKind,
  issue_date: new Date().toISOString().slice(0, 10),
  amount: 0,
  currency: 'CZK',
  counterparty: '',
  description: '',
})
const error = ref('')

async function load() {
  loading.value = true
  try {
    docs.value = await cashDocumentsApi.list({
      year: yearFilter.value,
      ...(kindFilter.value ? { kind: kindFilter.value } : {}),
    })
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Našeptávač protistrany — jména klientů/dodavatelů (nativní datalist, načte se jednou)
const counterpartyOptions = ref<string[]>([])
onMounted(async () => {
  try {
    const res = await clientsApi.list({ per_page: 500, role: 'all' })
    counterpartyOptions.value = [...new Set(res.data.map(c => c.company_name).filter(Boolean))].sort()
  } catch {
    // našeptávač je jen komfort — bez něj formulář funguje dál
  }
})

function openCreate(kind: CashDocumentKind) {
  Object.assign(form, {
    id: null, kind, issue_date: new Date().toISOString().slice(0, 10),
    amount: 0, currency: 'CZK', counterparty: '', description: '',
  })
  error.value = ''
  showForm.value = true
}
function openEdit(d: CashDocument) {
  Object.assign(form, {
    id: d.id, kind: d.kind, issue_date: d.issue_date.slice(0, 10),
    amount: d.amount, currency: d.currency, counterparty: d.counterparty, description: d.description,
  })
  error.value = ''
  showForm.value = true
}

async function save() {
  error.value = ''
  try {
    if (form.id === null) {
      const created = await cashDocumentsApi.create({
        kind: form.kind, issue_date: form.issue_date, amount: form.amount,
        currency: form.currency, counterparty: form.counterparty, description: form.description,
      })
      toast.success(t('cash.created', { number: created.number }))
    } else {
      await cashDocumentsApi.update(form.id, {
        issue_date: form.issue_date, amount: form.amount,
        currency: form.currency, counterparty: form.counterparty, description: form.description,
      })
      toast.success(t('common.saved'))
    }
    showForm.value = false
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

async function remove(d: CashDocument) {
  if (!confirm(t('cash.delete_confirm', { number: d.number }))) return
  try {
    await cashDocumentsApi.remove(d.id)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function openPdf(d: CashDocument) {
  window.open(cashDocumentsApi.pdfUrl(d.id), '_blank')
}

function formatMoney(n: number, currency: string): string {
  return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(n)
}

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return [y, y - 1, y - 2]
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('cash.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('cash.subtitle') }}</p>
      </div>
      <div v-if="canWrite" class="flex gap-2">
        <button @click="openCreate('income')"
          class="cursor-pointer h-9 px-3 bg-success-500 hover:bg-success-600 text-white text-sm font-medium rounded-md">
          {{ t('cash.new_income') }}
        </button>
        <button @click="openCreate('expense')"
          class="cursor-pointer h-9 px-3 bg-danger-500 hover:bg-danger-600 text-white text-sm font-medium rounded-md">
          {{ t('cash.new_expense') }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-4">
      <select v-model.number="yearFilter" @change="load" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-model="kindFilter" @change="load" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="">{{ t('cash.all_kinds') }}</option>
        <option value="income">{{ t('cash.kind_income') }}</option>
        <option value="expense">{{ t('cash.kind_expense') }}</option>
      </select>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="docs.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-10 text-center text-sm text-neutral-500">
      {{ t('cash.empty') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('cash.number') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('cash.kind') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('cash.date') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('cash.counterparty') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('cash.description') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('cash.amount') }}</th>
            <th class="px-3 py-2 w-36"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="d in docs" :key="d.id">
            <td class="px-3 py-2 font-mono text-xs">{{ d.number }}</td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium"
                :class="d.kind === 'income' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ d.kind === 'income' ? t('cash.kind_income') : t('cash.kind_expense') }}
              </span>
            </td>
            <td class="px-3 py-2 text-xs">{{ d.issue_date.slice(0, 10) }}</td>
            <td class="px-3 py-2">{{ d.counterparty || '—' }}</td>
            <td class="px-3 py-2 text-xs text-neutral-600">
              {{ d.description || '—' }}
              <span v-if="d.invoice_varsymbol" class="text-neutral-400">(VS {{ d.invoice_varsymbol }})</span>
            </td>
            <td class="px-3 py-2 text-right font-mono"
              :class="d.kind === 'income' ? 'text-success-600' : 'text-danger-500'">
              {{ (d.kind === 'income' ? '+' : '−') + formatMoney(d.amount, d.currency) }}
            </td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <button @click="openPdf(d)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3">PDF</button>
              <button v-if="canWrite" @click="openEdit(d)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3">{{ t('common.edit') }}</button>
              <button v-if="canWrite" @click="remove(d)" class="cursor-pointer text-danger-500 hover:text-danger-600 text-xs">{{ t('common.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="d in docs" :key="`m-${d.id}`" class="p-3 space-y-1.5">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-mono text-xs">{{ d.number }}</span>
            <span class="font-mono text-sm font-semibold"
              :class="d.kind === 'income' ? 'text-success-600' : 'text-danger-500'">
              {{ (d.kind === 'income' ? '+' : '−') + formatMoney(d.amount, d.currency) }}
            </span>
          </div>
          <div class="text-sm">{{ d.counterparty || '—' }}</div>
          <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
            <span class="truncate">{{ d.description || '—' }}</span>
            <span class="font-mono whitespace-nowrap">{{ d.issue_date.slice(0, 10) }}</span>
          </div>
          <div class="flex gap-2 pt-1">
            <button @click="openPdf(d)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">PDF</button>
            <button v-if="canWrite" @click="openEdit(d)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
              {{ t('common.edit') }}
            </button>
            <button v-if="canWrite" @click="remove(d)"
              class="cursor-pointer flex-1 h-9 text-sm border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ form.id === null
            ? (form.kind === 'income' ? t('cash.new_income_title') : t('cash.new_expense_title'))
            : t('cash.edit_title') }}
        </h3>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.date') }} *</label>
              <input v-model="form.issue_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.amount') }} *</label>
              <div class="flex gap-2">
                <input v-model.number="form.amount" type="number" min="0" step="0.01"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                <input v-model="form.currency" type="text" maxlength="3"
                  class="w-16 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
              </div>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ form.kind === 'income' ? t('cash.received_from') : t('cash.paid_to') }}
            </label>
            <input v-model="form.counterparty" type="text" list="cash-counterparty-list"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <datalist id="cash-counterparty-list">
              <option v-for="name in counterpartyOptions" :key="name" :value="name" />
            </datalist>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.purpose') }}</label>
            <input v-model="form.description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <p v-if="form.id === null" class="text-xs text-neutral-500">{{ t('cash.number_hint') }}</p>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="save" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
              {{ form.id === null ? t('common.create') : t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
