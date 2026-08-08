<script setup lang="ts">
// FORK (beevee85): pokladna v2 (0924) — více pokladen, pokladní kniha se
// zůstatky, storno místo mazání (H7), náležitosti H3/H6 (protistrana s IČO,
// zjednodušený daňový doklad § 30a, podpisové záznamy dle § 33a/10 — R3).
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  cashDocumentsApi, cashRegistersApi,
  type CashDocument, type CashDocumentKind, type CashRegister, type CashBook,
} from '@/api/cashDocuments'
import { clientsApi } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import ComplianceAckModal from '@/components/compliance/ComplianceAckModal.vue'
import { extractComplianceChecks, type ComplianceAck, type ComplianceCheck } from '@/api/compliance'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const docs = ref<CashDocument[]>([])
const registers = ref<CashRegister[]>([])
const loading = ref(false)
const yearFilter = ref<number>(new Date().getFullYear())
const kindFilter = ref<'' | CashDocumentKind>('')
const registerFilter = ref<number | 0>(0)

const canWrite = computed(() => auth.user?.role === 'admin' || auth.user?.role === 'accountant')

// Záložky: doklady / pokladní kniha (H4)
const tab = ref<'docs' | 'book'>('docs')

const showForm = ref(false)
useHotkey('escape', () => {
  if (showForm.value) showForm.value = false
  if (stornoDoc.value) stornoDoc.value = null
  if (showInventory.value) showInventory.value = false
  if (showNewRegister.value) showNewRegister.value = false
})

const form = reactive({
  id: null as number | null,
  kind: 'income' as CashDocumentKind,
  cash_register_id: 0,
  issue_date: new Date().toISOString().slice(0, 10),
  accounting_date: new Date().toISOString().slice(0, 10),
  amount: 0,
  currency: 'CZK',
  counterparty: '',
  counterparty_client_id: null as number | null,
  description: '',
  is_tax_document: false,
  tax_rate: 21,
  received_by: '',
  approved_by: '',
  note: '',
})
const error = ref('')

const defaultRegisterId = computed(() =>
  registers.value.find(r => r.is_default)?.id ?? registers.value[0]?.id ?? 0)

async function loadRegisters() {
  try {
    registers.value = await cashRegistersApi.list()
    if (!registerFilter.value && registers.value.length) registerFilter.value = defaultRegisterId.value
  } catch {
    registers.value = []
  }
}

async function load() {
  loading.value = true
  try {
    docs.value = await cashDocumentsApi.list({
      year: yearFilter.value,
      ...(kindFilter.value ? { kind: kindFilter.value } : {}),
      ...(registerFilter.value ? { register: registerFilter.value } : {}),
    })
  } finally {
    loading.value = false
  }
}
onMounted(async () => { await loadRegisters(); await load(); await loadBook() })

// Našeptávač protistrany — klienti/dodavatelé (datalist + mapa jméno → id pro autofill IČO/adresy)
const counterpartyOptions = ref<Array<{ id: number; name: string }>>([])
onMounted(async () => {
  try {
    const res = await clientsApi.list({ per_page: 500, role: 'all' })
    counterpartyOptions.value = res.data
      .filter(c => !!c.company_name)
      .map(c => ({ id: c.id, name: c.company_name }))
      .sort((a, b) => a.name.localeCompare(b.name, 'cs'))
  } catch {
    // našeptávač je jen komfort — bez něj formulář funguje dál
  }
})
// Jméno z datalistu se přeloží na klienta (backend pak doplní IČO + adresu z karty).
watch(() => form.counterparty, name => {
  const match = counterpartyOptions.value.find(c => c.name === name)
  form.counterparty_client_id = match?.id ?? null
})

function openCreate(kind: CashDocumentKind) {
  const today = new Date().toISOString().slice(0, 10)
  Object.assign(form, {
    id: null, kind, cash_register_id: registerFilter.value || defaultRegisterId.value,
    issue_date: today, accounting_date: today,
    amount: 0, currency: registers.value.find(r => r.id === (registerFilter.value || defaultRegisterId.value))?.currency ?? 'CZK',
    counterparty: '', counterparty_client_id: null, description: '',
    is_tax_document: false, tax_rate: 21, received_by: '', approved_by: '', note: '',
  })
  error.value = ''
  showForm.value = true
}

// Deep-link z menu „Vytvořit": /cash-documents?new=income|expense (vzor: /logbook?new=trip).
function handleNewQuery() {
  const q = route.query.new
  if ((q === 'income' || q === 'expense') && canWrite.value) {
    openCreate(q)
    router.replace({ query: { ...route.query, new: undefined } })
  }
}
onMounted(handleNewQuery)
watch(() => route.query.new, handleNewQuery)

function openEdit(d: CashDocument) {
  Object.assign(form, {
    id: d.id, kind: d.kind, cash_register_id: d.cash_register_id ?? 0,
    issue_date: d.issue_date.slice(0, 10),
    accounting_date: (d.accounting_date ?? d.issue_date).slice(0, 10),
    amount: d.amount, currency: d.currency,
    counterparty: d.counterparty, counterparty_client_id: d.counterparty_client_id,
    description: d.description, is_tax_document: d.is_tax_document,
    tax_rate: d.vat_breakdown?.[0]?.rate ?? 21,
    received_by: d.received_by ?? '', approved_by: d.approved_by ?? '', note: d.note ?? '',
  })
  error.value = ''
  showForm.value = true
}

// § 30a — rozpad DPH shora z celkové částky (jednosazbový hotovostní prodej).
const taxBreakdown = computed(() => {
  const amount = Number(form.amount) || 0
  const rate = Number(form.tax_rate) || 0
  const base = Math.round((amount * 100 / (100 + rate)) * 100) / 100
  return [{ rate, base, vat: Math.round((amount - base) * 100) / 100 }]
})

// FORK 0925 — modal vynuceného rozhodnutí
const ackChecks = ref<ComplianceCheck[] | null>(null)
const ackRetry = ref<((ack: ComplianceAck) => void) | null>(null)

async function save(complianceAck?: ComplianceAck) {
  error.value = ''
  try {
    if (form.id === null) {
      const created = await cashDocumentsApi.create({
        kind: form.kind,
        cash_register_id: form.cash_register_id || undefined,
        issue_date: form.issue_date,
        accounting_date: form.accounting_date || undefined,
        amount: form.amount,
        currency: form.currency,
        counterparty: form.counterparty,
        counterparty_client_id: form.counterparty_client_id ?? undefined,
        description: form.description,
        is_tax_document: form.is_tax_document || undefined,
        ...(form.is_tax_document ? { vat_breakdown: taxBreakdown.value } : {}),
        received_by: form.received_by || undefined,
        approved_by: form.approved_by || undefined,
        note: form.note || undefined,
        compliance_ack: complianceAck,
      })
      toast.success(t('cash.created', { number: created.number }))
      for (const w of created.warnings ?? []) toast.warning(w.message)
    } else {
      await cashDocumentsApi.update(form.id, {
        counterparty: form.counterparty,
        counterparty_client_id: form.counterparty_client_id ?? undefined,
        description: form.description,
        received_by: form.received_by || undefined,
        approved_by: form.approved_by || undefined,
        note: form.note || undefined,
      })
      toast.success(t('common.saved'))
    }
    showForm.value = false
    await Promise.all([load(), loadRegisters(), loadBook()])
  } catch (e: any) {
    const checks = extractComplianceChecks(e)
    if (checks) { ackChecks.value = checks; ackRetry.value = a => save(a); return }
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

// ── Storno (H7) ──────────────────────────────────────────────────────────
const stornoDoc = ref<CashDocument | null>(null)
const stornoReason = ref('')
async function submitStorno() {
  if (!stornoDoc.value) return
  try {
    const r = await cashDocumentsApi.storno(stornoDoc.value.id, stornoReason.value.trim())
    toast.success(t('cash.storno_done', { number: r.storno.number }))
    stornoDoc.value = null
    stornoReason.value = ''
    await Promise.all([load(), loadRegisters(), loadBook()])
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function openPdf(d: CashDocument) {
  window.open(cashDocumentsApi.pdfUrl(d.id), '_blank')
}

// ── Pokladní kniha (H4) ──────────────────────────────────────────────────
const book = ref<CashBook | null>(null)
const bookMonth = ref<number | 0>(0) // 0 = celý rok
async function loadBook() {
  if (!registerFilter.value) { book.value = null; return }
  try {
    book.value = await cashRegistersApi.book(registerFilter.value, {
      year: yearFilter.value,
      ...(bookMonth.value ? { month: bookMonth.value } : {}),
    })
  } catch {
    book.value = null
  }
}
watch([registerFilter, yearFilter, bookMonth], () => { load(); loadBook() })

function openBookPdf() {
  if (!registerFilter.value) return
  window.open(cashRegistersApi.bookPdfUrl(registerFilter.value, yearFilter.value, bookMonth.value || undefined), '_blank')
}

// ── Inventarizace (H4) ───────────────────────────────────────────────────
const showInventory = ref(false)
const inventoryActual = ref<string>('')
const inventoryNote = ref('')
const inventoryCreateDoc = ref(true)
const inventoryExpected = computed(() => book.value?.closing_balance ?? 0)
async function submitInventory() {
  if (!registerFilter.value) return
  const actual = Number(String(inventoryActual.value).replace(',', '.'))
  if (!Number.isFinite(actual)) { toast.error(t('cash.inventory_invalid')) ; return }
  try {
    const r = await cashRegistersApi.inventory(registerFilter.value, {
      actual_amount: actual,
      note: inventoryNote.value.trim() || undefined,
      create_settlement: inventoryCreateDoc.value,
    })
    toast.success(Math.abs(r.difference) < 0.01
      ? t('cash.inventory_ok')
      : t('cash.inventory_diff_recorded', { diff: formatMoney(r.difference, book.value?.register.currency ?? 'CZK') }))
    showInventory.value = false
    inventoryActual.value = ''
    inventoryNote.value = ''
    await Promise.all([load(), loadRegisters(), loadBook()])
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ── Nová pokladna ────────────────────────────────────────────────────────
const showNewRegister = ref(false)
const newRegisterName = ref('')
const newRegisterCurrency = ref('CZK')
async function submitNewRegister() {
  try {
    const r = await cashRegistersApi.create({ name: newRegisterName.value.trim(), currency: newRegisterCurrency.value })
    toast.success(t('cash.register_created', { name: r.name }))
    showNewRegister.value = false
    newRegisterName.value = ''
    await loadRegisters()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function formatMoney(n: number, currency: string): string {
  return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(n)
}

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return [y + 1, y, y - 1, y - 2]
})
const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) => new Date(2000, i, 1).toLocaleDateString('cs-CZ', { month: 'long' })))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
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

    <!-- Pokladny (0924): výběr + zůstatky + založení -->
    <div class="flex flex-wrap items-center gap-3 mb-3">
      <select v-model.number="registerFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option v-for="r in registers" :key="r.id" :value="r.id">
          {{ r.name }} ({{ r.currency }}) · {{ formatMoney(r.balance, r.currency) }}
        </option>
      </select>
      <button v-if="canWrite" @click="showNewRegister = true"
        class="cursor-pointer h-9 px-2.5 text-xs border border-neutral-300 rounded-md text-neutral-600 hover:bg-neutral-50">
        {{ t('cash.new_register') }}
      </button>
      <select v-model.number="yearFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-model="kindFilter" @change="load" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="">{{ t('cash.all_kinds') }}</option>
        <option value="income">{{ t('cash.kind_income') }}</option>
        <option value="expense">{{ t('cash.kind_expense') }}</option>
      </select>
      <SegmentedControl
        :model-value="tab"
        @update:model-value="v => tab = v as 'docs' | 'book'"
        :options="[
          { value: 'docs', label: t('cash.tab_docs') },
          { value: 'book', label: t('cash.tab_book') },
        ]"
      />
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- ═══ Záložka Doklady ═══ -->
    <template v-else-if="tab === 'docs'">
      <div v-if="docs.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-10 text-center text-sm text-neutral-500">
        {{ t('cash.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.number') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('cash.kind') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.counterparty') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.description') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('cash.amount') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="d in docs" :key="d.id" :class="d.status === 'storno' ? 'opacity-60' : ''">
              <td class="px-3 py-2 font-mono text-xs">
                {{ d.number }}
                <span v-if="d.status === 'storno'" class="block text-[10px] text-danger-500">
                  {{ t('cash.stornoed') }}<template v-if="d.storno_by_number"> · {{ d.storno_by_number }}</template>
                </span>
                <span v-else-if="d.storno_of_number" class="block text-[10px] text-danger-500">
                  {{ t('cash.storno_of', { number: d.storno_of_number }) }}
                </span>
                <span v-if="d.is_tax_document" class="block text-[10px] text-amber-600" :title="t('cash.tax_doc_hint')">
                  {{ t('cash.tax_doc_badge') }}
                </span>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="d.kind === 'income' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                  {{ d.kind === 'income' ? t('cash.kind_income') : t('cash.kind_expense') }}
                </span>
              </td>
              <td class="px-3 py-2 text-xs">{{ (d.accounting_date ?? d.issue_date).slice(0, 10) }}</td>
              <td class="px-3 py-2">
                {{ d.counterparty || '—' }}
                <span v-if="d.counterparty_ico" class="block text-[10px] text-neutral-400 font-mono">IČO {{ d.counterparty_ico }}</span>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-600">
                {{ d.description || '—' }}
                <span v-if="d.invoice_varsymbol" class="text-neutral-400">(VS {{ d.invoice_varsymbol }})</span>
                <span v-else-if="d.purchase_varsymbol" class="text-neutral-400">({{ d.purchase_varsymbol }})</span>
              </td>
              <td class="px-3 py-2 text-right font-mono"
                :class="(d.kind === 'income') === (d.amount >= 0) ? 'text-success-600' : 'text-danger-500'">
                {{ (d.kind === 'income' ? '+' : '−') + formatMoney(Math.abs(d.amount), d.currency) }}
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button @click="openPdf(d)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3">PDF</button>
                <button v-if="canWrite && d.status === 'active' && !d.storno_of_id" @click="openEdit(d)"
                  class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3">{{ t('common.edit') }}</button>
                <!-- H7: mazání neexistuje — jen storno -->
                <button v-if="canWrite && d.status === 'active' && !d.storno_of_id && d.amount >= 0"
                  @click="stornoDoc = d; stornoReason = ''"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 text-xs">{{ t('cash.storno_btn') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </template>

    <!-- ═══ Záložka Pokladní kniha (H4) ═══ -->
    <template v-else>
      <div class="flex flex-wrap items-center gap-3 mb-3">
        <select v-model.number="bookMonth" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option :value="0">{{ t('cash.whole_year') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <button @click="openBookPdf" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
          {{ t('cash.book_pdf') }}
        </button>
        <button v-if="canWrite" @click="showInventory = true; inventoryActual = ''"
          class="cursor-pointer h-9 px-3 text-sm border border-primary-500/50 text-primary-700 rounded-md hover:bg-primary-50">
          {{ t('cash.inventory_btn') }}
        </button>
      </div>

      <div v-if="!book" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-10 text-center text-sm text-neutral-500">
        {{ t('cash.empty') }}
      </div>
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap gap-x-6 gap-y-1 text-sm">
          <span>{{ t('cash.opening_balance') }}: <strong class="font-mono">{{ formatMoney(book.opening_balance, book.register.currency) }}</strong></span>
          <span class="text-success-600">{{ t('cash.income_total') }}: <strong class="font-mono">{{ formatMoney(book.income_total, book.register.currency) }}</strong></span>
          <span class="text-danger-500">{{ t('cash.expense_total') }}: <strong class="font-mono">{{ formatMoney(book.expense_total, book.register.currency) }}</strong></span>
          <span>{{ t('cash.closing_balance') }}: <strong class="font-mono">{{ formatMoney(book.closing_balance, book.register.currency) }}</strong></span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.number') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cash.counterparty') }} / {{ t('cash.description') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('cash.col_income') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('cash.col_expense') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('cash.col_balance') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr class="bg-neutral-50/60 italic text-neutral-500">
              <td class="px-3 py-2 text-xs">{{ book.from }}</td>
              <td colspan="4" class="px-3 py-2 text-xs">{{ t('cash.opening_balance') }}</td>
              <td class="px-3 py-2 text-right font-mono text-neutral-700">{{ formatMoney(book.opening_balance, book.register.currency) }}</td>
            </tr>
            <tr v-for="e in book.entries" :key="e.id" :class="e.status === 'storno' || e.storno_of_id ? 'text-danger-500/80' : ''">
              <td class="px-3 py-2 text-xs">{{ e.accounting_date.slice(0, 10) }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ e.number }}<span v-if="e.status === 'storno'"> ({{ t('cash.stornoed') }})</span></td>
              <td class="px-3 py-2 text-xs">{{ [e.counterparty, e.description].filter(Boolean).join(' — ') || '—' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ e.signed_amount >= 0 ? formatMoney(e.signed_amount, e.currency) : '' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ e.signed_amount < 0 ? formatMoney(-e.signed_amount, e.currency) : '' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs" :class="e.running_balance < 0 ? 'text-danger-500 font-bold' : ''">
                {{ formatMoney(e.running_balance, e.currency) }}
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </template>

    <!-- ═══ Modal: doklad ═══ -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="showForm = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 max-h-[92vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-3">
          {{ form.id ? t('cash.edit_title') : (form.kind === 'income' ? t('cash.new_income') : t('cash.new_expense')) }}
        </h3>
        <div v-if="error" class="mb-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

        <div class="grid grid-cols-2 gap-3">
          <div v-if="!form.id">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register') }}</label>
            <select v-model.number="form.cash_register_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="r in registers.filter(x => !x.is_archived)" :key="r.id" :value="r.id">{{ r.name }} ({{ r.currency }})</option>
            </select>
          </div>
          <div v-if="!form.id">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.amount') }} *</label>
            <input v-model.number="form.amount" type="number" step="0.01" min="0.01"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
          </div>
          <div v-if="!form.id">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.date_issued') }} *</label>
            <input v-model="form.issue_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div v-if="!form.id">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.date_accounting') }}</label>
            <input v-model="form.accounting_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div class="col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ form.kind === 'income' ? t('cash.received_from') : t('cash.paid_to') }}
            </label>
            <input v-model="form.counterparty" type="text" list="cash-counterparties" maxlength="190"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            <datalist id="cash-counterparties">
              <option v-for="c in counterpartyOptions" :key="c.id" :value="c.name" />
            </datalist>
            <p v-if="form.counterparty_client_id" class="text-xs text-success-600 mt-0.5">{{ t('cash.counterparty_linked') }}</p>
          </div>
          <div class="col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.description') }}</label>
            <input v-model="form.description" type="text" maxlength="500" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>

          <!-- H3: zjednodušený daňový doklad § 30a (jen PPD bez vazby na fakturu) -->
          <div v-if="!form.id && form.kind === 'income'" class="col-span-2">
            <label class="flex items-start gap-2 text-sm text-neutral-700 cursor-pointer">
              <input v-model="form.is_tax_document" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <span>
                {{ t('cash.is_tax_document') }}
                <span class="block text-xs text-neutral-500">{{ t('cash.is_tax_document_hint') }}</span>
              </span>
            </label>
            <div v-if="form.is_tax_document" class="mt-2 flex items-center gap-3 text-sm">
              <label>{{ t('cash.tax_rate') }}</label>
              <select v-model.number="form.tax_rate" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="21">21 %</option>
                <option :value="12">12 %</option>
              </select>
              <span class="text-xs text-neutral-500 font-mono">
                {{ t('cash.tax_base') }} {{ taxBreakdown[0].base.toFixed(2) }} · DPH {{ taxBreakdown[0].vat.toFixed(2) }}
              </span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.received_by') }}</label>
            <input v-model="form.received_by" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.approved_by') }}</label>
            <input v-model="form.approved_by" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div class="col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.note') }}</label>
            <textarea v-model="form.note" rows="2" maxlength="2000" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-4">
          <button @click="showForm = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="save()" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- FORK 0925 — modal vynuceného rozhodnutí (compliance) -->
    <ComplianceAckModal v-if="ackChecks" :checks="ackChecks"
      @cancel="ackChecks = null; ackRetry = null"
      @confirm="a => { const r = ackRetry; ackChecks = null; ackRetry = null; r?.(a) }" />

    <!-- ═══ Modal: storno (H7) ═══ -->
    <div v-if="stornoDoc" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-2">{{ t('cash.storno_title', { number: stornoDoc.number }) }}</h3>
        <p class="text-sm text-neutral-600 mb-3">{{ t('cash.storno_body') }}</p>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.storno_reason') }} *</label>
        <textarea v-model="stornoReason" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
        <div class="flex justify-end gap-2 mt-4">
          <button @click="stornoDoc = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="submitStorno" :disabled="stornoReason.trim().length < 5"
            class="cursor-pointer px-4 h-9 text-sm bg-danger-500 hover:bg-danger-600 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ t('cash.storno_confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ Modal: inventarizace (H4) ═══ -->
    <div v-if="showInventory" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-2">{{ t('cash.inventory_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-3">
          {{ t('cash.inventory_expected') }}: <strong class="font-mono">{{ formatMoney(inventoryExpected, book?.register.currency ?? 'CZK') }}</strong>
        </p>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.inventory_actual') }} *</label>
        <input v-model="inventoryActual" type="number" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono mb-3" />
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.note') }}</label>
        <textarea v-model="inventoryNote" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm mb-3"></textarea>
        <label class="flex items-start gap-2 text-sm text-neutral-700 cursor-pointer mb-1">
          <input v-model="inventoryCreateDoc" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('cash.inventory_create_doc') }}</span>
        </label>
        <p class="text-xs text-neutral-500">{{ t('cash.inventory_hint') }}</p>
        <div class="flex justify-end gap-2 mt-4">
          <button @click="showInventory = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="submitInventory"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
            {{ t('cash.inventory_confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ Modal: nová pokladna ═══ -->
    <div v-if="showNewRegister" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-sm w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('cash.new_register') }}</h3>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register_name') }} *</label>
        <input v-model="newRegisterName" type="text" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-3" />
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register_currency') }}</label>
        <input v-model="newRegisterCurrency" type="text" maxlength="3" class="w-24 h-10 px-3 border border-neutral-300 rounded-md font-mono uppercase mb-3" />
        <div class="flex justify-end gap-2">
          <button @click="showNewRegister = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="submitNewRegister" :disabled="!newRegisterName.trim()"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
