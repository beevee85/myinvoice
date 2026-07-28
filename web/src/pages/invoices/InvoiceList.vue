<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { invoicesApi, type MonthGroup, type InvoiceListItem } from '@/api/invoices'
import { formatMoney, formatDate, formatMonth, statusLabel, typeLabel, isOverdue, invoiceRowClass, displayStatus, taxDateClass } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useRowLink } from '@/composables/useRowLink'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import DatePicker from '@/components/ui/DatePicker.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Button from '@/components/ui/Button.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Badge from '@/components/ui/Badge.vue'
import StatusDot from '@/components/ui/StatusDot.vue'
import TabsNav from '@/components/ui/TabsNav.vue'
import Modal from '@/components/ui/Modal.vue'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import DocumentTrashModal, { type TrashModalDoc } from '@/components/invoices/DocumentTrashModal.vue'
import { apiErrorMessage } from '@/api/errors'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const thanksEnabled = computed(() => supplierStore.currentSupplier?.payment_thanks_enabled ?? false)

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/invoices/new') })

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const typeFilter = ref<string>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const currencyFilter = ref<string>('')
const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])

/** Panel filtrů (rozbaluje tlačítko „Filtry" s badge počtu aktivních). */
const showFilters = ref(false)

// Počet aktivních filtrů pro odznáček na tlačítku „Filtry" (rok i hledání se nepočítají — rok má výchozí hodnotu, hledání je vždy vidět)
const activeFilterCount = computed(() => {
  let n = 0
  if (statusFilter.value) n++
  if (typeFilter.value) n++
  if (clientFilter.value !== '') n++
  if (currencyFilter.value) n++
  if (monthFilter.value !== '') n++
  if (dateFrom.value || dateTo.value) n++
  if (overdueOnly.value) n++
  if (unpaidOnly.value) n++
  return n
})

// FORK 0905 — režim koše: samostatný tab, list jede s filter[trash]=1.
const trashOnly = ref(false)

// ─── Taby stavů = presety existujících filtrů (žádná nová API sémantika) ───
const statusTabs = computed(() => [
  { value: 'all',     label: t('invoice.tab_all') },
  { value: 'paid',    label: t('invoice.tab_paid') },
  { value: 'unpaid',  label: t('invoice.tab_unpaid') },
  { value: 'overdue', label: t('invoice.tab_overdue') },
  { value: 'draft',   label: t('invoice.tab_drafts') },
  { value: 'trash',   label: t('doc_trash.tab') },
])
const activeTab = computed<string>(() => {
  if (trashOnly.value) return 'trash'
  if (overdueOnly.value) return 'overdue'
  if (unpaidOnly.value) return 'unpaid'
  if (statusFilter.value === 'paid') return 'paid'
  if (statusFilter.value === 'draft') return 'draft'
  if (!statusFilter.value) return 'all'
  return '' // jiný stav vybraný v panelu filtrů — žádný tab není aktivní
})
function setTab(v: string | number) {
  const tab = String(v)
  // preset přepisuje jen stavovou čtveřici (status / unpaid / overdue / koš); ostatní filtry nechává
  statusFilter.value = tab === 'paid' ? 'paid' : (tab === 'draft' ? 'draft' : '')
  unpaidOnly.value = tab === 'unpaid'
  overdueOnly.value = tab === 'overdue'
  trashOnly.value = tab === 'trash'
}

// ─── Odstranitelné chipy aktivních filtrů (zrcadlí activeFilterCount + odchylku roku) ───
const filterChips = computed(() => {
  const chips: { key: string; label: string; clear: () => void }[] = []
  if (statusFilter.value) chips.push({ key: 'status', label: statusLabel(statusFilter.value), clear: () => { statusFilter.value = '' } })
  if (typeFilter.value) chips.push({ key: 'type', label: typeLabel(typeFilter.value), clear: () => { typeFilter.value = '' } })
  if (clientFilter.value !== '') {
    const c = clients.value.find(x => x.id === clientFilter.value)
    chips.push({ key: 'client', label: c?.company_name ?? `#${clientFilter.value}`, clear: () => { clientFilter.value = '' } })
  }
  if (currencyFilter.value) chips.push({ key: 'currency', label: currencyFilter.value, clear: () => { currencyFilter.value = '' } })
  if (monthFilter.value !== '') chips.push({ key: 'month', label: monthOptions.value[Number(monthFilter.value) - 1] ?? String(monthFilter.value), clear: () => { monthFilter.value = '' } })
  if (dateFrom.value || dateTo.value) chips.push({
    key: 'range',
    label: `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}`,
    clear: () => { dateFrom.value = ''; dateTo.value = '' },
  })
  if (overdueOnly.value) chips.push({ key: 'overdue', label: t('invoice.overdue_only'), clear: () => { overdueOnly.value = false } })
  if (unpaidOnly.value) chips.push({ key: 'unpaid', label: t('invoice.unpaid_only'), clear: () => { unpaidOnly.value = false } })
  if (yearFilter.value === '') chips.push({ key: 'year', label: t('invoice.all_years'), clear: () => { yearFilter.value = DEFAULT_YEAR } })
  else if (yearFilter.value !== DEFAULT_YEAR) chips.push({ key: 'year', label: String(yearFilter.value), clear: () => { yearFilter.value = DEFAULT_YEAR } })
  return chips
})

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)
const bulkPdfOpen = ref(false)
const bulkPdfSign = ref(false)

const selectedPdfIds = computed(() => {
  const selected = new Set(selectedIds.value)
  const visible = groups.value.flatMap(group => group.invoices)
    .map(invoice => invoice.id)
    .filter(id => selected.has(id))
  const visibleSet = new Set(visible)
  return [...visible, ...selectedIds.value.filter(id => !visibleSet.has(id))]
})

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function hasPositiveAmountToPay(inv: InvoiceListItem): boolean {
  if (!['invoice', 'proforma'].includes(inv.invoice_type)) return true
  return Number(inv.amount_to_pay ?? 0) > 0
}

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

function isGroupSelected(group: MonthGroup): boolean {
  return group.invoices.length > 0 && group.invoices.every(invoice => selectedIds.value.includes(invoice.id))
}

function isGroupSelectionPartial(group: MonthGroup): boolean {
  const selectedCount = group.invoices.filter(invoice => selectedIds.value.includes(invoice.id)).length
  return selectedCount > 0 && selectedCount < group.invoices.length
}

function toggleGroupSelected(group: MonthGroup) {
  const groupIds = group.invoices.map(invoice => invoice.id)
  const selected = new Set(selectedIds.value)

  if (groupIds.every(id => selected.has(id))) {
    selectedIds.value = selectedIds.value.filter(id => !groupIds.includes(id))
    return
  }

  for (const id of groupIds) selected.add(id)
  selectedIds.value = Array.from(selected)
}

function openBulkPdfExport() {
  if (selectedPdfIds.value.length === 0) return
  bulkPdfSign.value = false
  bulkPdfOpen.value = true
}

// ─── FORK 0905 — koš dokladů: dialogy + operace ───
const trashModalOpen = ref(false)
const trashModalMode = ref<'trash' | 'force' | 'empty'>('trash')
const trashModalDocs = ref<TrashModalDoc[]>([])
const trashBusy = ref(false)

/** Preflight blokací → naplní dialog daty (čísla dokladů + důvody blokací). */
async function openTrashModal(mode: 'trash' | 'force' | 'empty', ids: number[]) {
  if (!ids.length) return
  trashBusy.value = true
  try {
    const { documents } = await invoicesApi.trashPreflight(ids)
    const byId = new Map(groups.value.flatMap(g => g.invoices).map(i => [i.id, i]))
    trashModalDocs.value = documents.filter(d => d.found).map(d => {
      const row = byId.get(d.id)
      return {
        id: d.id,
        varsymbol: d.varsymbol ?? null,
        party: row?.client_company_name ?? null,
        totalFormatted: row ? formatMoney(row.total_with_vat, row.currency) : null,
        taxDate: row ? formatDate(row.tax_date || row.issue_date) : null,
        statusLabel: d.status ? statusLabel(d.status) : null,
        blockers: d.blockers ?? [],
      }
    })
    trashModalMode.value = mode
    trashModalOpen.value = true
  } catch (e) {
    toast.error(apiErrorMessage(e, t('doc_trash.preflight_failed')))
  } finally {
    trashBusy.value = false
  }
}

function openBulkTrash() { openTrashModal('trash', selectedIds.value) }
function openBulkForce() { openTrashModal('force', selectedIds.value) }
function openEmptyTrash() {
  const ids = groups.value.flatMap(g => g.invoices).map(i => i.id)
  openTrashModal('empty', ids)
}
/** Řádkové akce v koši. */
function rowForceDelete(inv: InvoiceListItem) { openTrashModal('force', [inv.id]) }
async function rowRestore(inv: InvoiceListItem) {
  try {
    await invoicesApi.restore(inv.id)
    toast.success(t('doc_trash.restored', { varsymbol: inv.varsymbol || `#${inv.id}` }))
    selectedIds.value = selectedIds.value.filter(id => id !== inv.id)
    await load(true)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('doc_trash.restore_failed')))
  }
}
async function bulkRestore() {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  trashBusy.value = true
  const errors: string[] = []
  for (const id of ids) {
    try { await invoicesApi.restore(id) } catch (e) { errors.push(apiErrorMessage(e, `#${id}`)) }
  }
  trashBusy.value = false
  selectedIds.value = []
  await load(true)
  if (errors.length) toast.warning(t('doc_trash.bulk_partial', { ok: ids.length - errors.length, failed: errors.length }) + ' ' + errors.join('; '))
  else toast.success(t('doc_trash.bulk_restored', { n: ids.length }))
}

/** Potvrzení z dialogu — provede operaci podle režimu. Blokované doklady přeskočí. */
async function confirmTrashModal(payload: { reason: string; override: boolean; confirmNumber: string }) {
  trashBusy.value = true
  const errors: string[] = []
  let done = 0
  try {
    if (trashModalMode.value === 'empty') {
      const res = await invoicesApi.emptyTrash(payload.reason)
      done = res.deleted.length
      if (res.skipped.length) {
        toast.warning(t('doc_trash.empty_skipped', { n: done, skipped: res.skipped.length }))
      } else {
        toast.success(t('doc_trash.empty_done', { n: done }))
      }
    } else {
      // Jen doklady, které preflight nechal průchozí (nepřebitelně blokované vynech).
      const eligible = trashModalDocs.value.filter(d =>
        !d.blockers.some(b => !b.overridable)
        && (d.blockers.length === 0 || payload.override))
      for (const d of eligible) {
        try {
          if (trashModalMode.value === 'trash') {
            await invoicesApi.delete(d.id, payload.reason, payload.override)
          } else {
            await invoicesApi.forceDelete(d.id, payload.reason,
              eligible.length === 1 ? payload.confirmNumber : (d.varsymbol ?? ''), payload.override)
          }
          done++
        } catch (e) {
          errors.push(`${d.varsymbol || `#${d.id}`}: ${apiErrorMessage(e, t('doc_trash.op_failed'))}`)
        }
      }
      const skipped = trashModalDocs.value.length - eligible.length
      if (errors.length) toast.warning(t('doc_trash.bulk_partial', { ok: done, failed: errors.length }) + ' ' + errors.join('; '))
      else if (trashModalMode.value === 'trash') toast.success(t('doc_trash.trashed_done', { n: done }) + (skipped ? ' ' + t('doc_trash.skipped_note', { n: skipped }) : ''))
      else toast.success(t('doc_trash.force_done', { n: done }) + (skipped ? ' ' + t('doc_trash.skipped_note', { n: skipped }) : ''))
    }
  } finally {
    trashBusy.value = false
    trashModalOpen.value = false
    selectedIds.value = []
    await load(true)
  }
}

async function responseErrorMessage(error: any, fallback: string): Promise<string> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text())
      return parsed?.error?.message || fallback
    } catch {
      return fallback
    }
  }
  return data?.error?.message || fallback
}

async function bulkExportPdf() {
  const ids = selectedPdfIds.value
  if (ids.length === 0 || ids.length > 100) return
  bulkBusy.value = true
  try {
    const response = await invoicesApi.exportSelectedPdf(ids, bulkPdfSign.value)
    const disposition = response.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    const filename = match?.[1] || `myinvoice-vybrane-faktury-${new Date().toISOString().slice(0, 10)}.pdf`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    bulkPdfOpen.value = false
    toast.success(t('invoice.bulk_pdf_done', { n: ids.length }))
  } catch (error: any) {
    toast.error(await responseErrorMessage(error, t('invoice.bulk_pdf_failed') as string))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkReissue() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('invoice.bulk_clone_confirm', { n: selectedIds.value.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkReissue(selectedIds.value, { increment_month_in_descriptions: true })
    selectedIds.value = []
    if (r.errors.length) {
      toast.warning(t('invoice.bulk_reissue_partial', { ok: r.created.length, err: r.errors.length }))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: r.created.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reissue_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné odeslání klientům — pouze faktury se status issued/sent/reminded/paid + ne cancellation
const sendableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

// Hromadné vystavení — jen drafty. Řadíme podle issue_date asc, pak id asc, aby varsymboly šly sekvenčně.
const issuableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'draft')
    .sort((a, b) => (a.issue_date || '').localeCompare(b.issue_date || '') || (a.id - b.id))
})

// Hromadné označení za zaplacené — jen issued/sent/reminded (ne paid, ne cancelled, ne draft, ne cancellation)
const markPayableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
      && hasPositiveAmountToPay(inv)
    )
})

// Hromadná upomínka — jen běžné faktury (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placené bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const reminderSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => {
      if (!ids.has(inv.id)) return false
      if (inv.invoice_type !== 'invoice') return false
      if (!['issued', 'sent', 'reminded'].includes(inv.status)) return false
      if (!hasPositiveAmountToPay(inv)) return false
      if ((inv.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
      const due = new Date(inv.due_date)
      return due < today
    })
})

async function bulkSendReminders() {
  const list = reminderSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_reminder_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_reminder_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkSendReminders(list.map(i => i.id))
    selectedIds.value = []
    if (r.errors.length) {
      const detail = r.errors.map(e => `#${e.invoice_id}: ${e.error}`).join('\n')
      toast.warning(t('invoice.bulk_reminder_partial', { ok: r.sent.length, err: r.errors.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_reminder_success', { n: r.sent.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reminder_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkMarkPaid() {
  const list = markPayableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  // Volitelně i poděkování za úhradu (issue #57) — jen pokud má dodavatel funkci zapnutou.
  const sendThanks = thanksEnabled.value && confirm(t('invoice.bulk_send_thanks_confirm', { n: list.length }))
  const today = new Date().toISOString().slice(0, 10)
  bulkBusy.value = true
  let okCount = 0
  let thanksSent = 0
  let thanksFailed = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        const updated = await invoicesApi.markPaid(inv.id, today, sendThanks ? { sendThanks: true, thanksTrigger: 'bulk' } : undefined)
        okCount++
        const pt = updated.payment_thanks
        if (pt?.status === 'sent') thanksSent++
        else if (pt?.status === 'failed') thanksFailed++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    let msg = errors.length
      ? t('invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length })
      : t('invoice.bulk_mark_paid_success', { n: okCount })
    if (sendThanks) {
      msg += '\n' + t('invoice.bulk_thanks_summary', { sent: thanksSent, failed: thanksFailed })
    }
    if (errors.length) {
      toast.warning(msg + '\n' + errors.join('\n'))
    } else {
      toast.success(msg)
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkIssue() {
  const list = issuableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_issue_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_issue_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.issue(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`#${inv.id}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_issue_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_issue_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkSend() {
  const list = sendableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_send_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_send_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.send(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_send_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function exportCsv() {
  try {
    const r = await invoicesApi.exportCsv({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
    })
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.csv_export_failed'))
  }
}

// ─── Rychlé akce řádku (poslední sloupec, zobrazené při hoveru) — zkratky k EXISTUJÍCÍM akcím ───
const rowBusyId = ref<number | null>(null)

function rowPdf(inv: InvoiceListItem) {
  window.open(invoicesApi.pdfUrl(inv.id, false), '_blank')
}

// Stejný flow jako „Duplikovat" na detailu faktury (clone_confirm + posun měsíců v popiscích).
async function rowClone(inv: InvoiceListItem) {
  if (!confirm(t('invoice.clone_confirm', { varsymbol: inv.varsymbol || `#${inv.id}` }))) return
  const incrementMonths = confirm(t('invoice.clone_increment_confirm'))
  rowBusyId.value = inv.id
  try {
    const r = await invoicesApi.clone(inv.id, { increment_month_in_descriptions: incrementMonths })
    if (!r?.draft_id) {
      toast.error(t('invoice.invalid_response'))
      return
    }
    router.push(`/invoices/${r.draft_id}/edit`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.clone_failed'))
  } finally {
    rowBusyId.value = null
  }
}

function mergeGroups(existing: MonthGroup[], incoming: MonthGroup[]): MonthGroup[] {
  const byMonth = new Map<string, MonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    // Merge totals_per_currency
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
        found.draft_without_vat = Math.round((found.draft_without_vat + t.draft_without_vat) * 100) / 100
        found.draft_vat         = Math.round((found.draft_vat         + t.draft_vat)         * 100) / 100
        found.draft_with_vat    = Math.round((found.draft_with_vat    + t.draft_with_vat)    * 100) / 100
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const result = await invoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      client_id: clientFilter.value === '' ? undefined : Number(clientFilter.value),
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
      overdue: overdueOnly.value || undefined,
      unpaid_only: unpaidOnly.value || undefined,
      trash: trashOnly.value || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

// Sync filtrů s URL query (stejný pattern jako PurchaseInvoiceList) — detekuje menu
// link click přes route.query change z !empty na empty → reset.
const DEFAULT_YEAR = new Date().getFullYear()

onMounted(async () => {
  loadFiltersFromQuery(route.query)
  // Panel filtrů rozbalit, když URL nese aktivní filtry (deep-link z KPI dlaždic apod.)
  showFilters.value = activeFilterCount.value > 0 && activeTab.value === ''
  // Načti seznam klientů + měn pro select (paralelně s prvním load)
  clientsApi.list({ archived: false, per_page: 200, role: 'customers' }).then(r => { clients.value = r.data }).catch(() => {})
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  await load(true)
})

function loadFiltersFromQuery(q: typeof route.query) {
  statusFilter.value = typeof q.status === 'string' ? q.status : ''
  typeFilter.value   = typeof q.type === 'string' ? q.type : ''
  clientFilter.value = typeof q.client_id === 'string' && q.client_id !== '' ? Number(q.client_id) : ''
  overdueOnly.value  = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value   = q.unpaid === '1' || q.unpaid === 'true'
  trashOnly.value    = q.trash === '1'
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value) ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (typeFilter.value) q.type = typeFilter.value
  if (clientFilter.value !== '') q.client_id = String(clientFilter.value)
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (trashOnly.value) q.trash = '1'
  if (search.value) q.q = search.value
  router.replace({ query: q })
}

watch([statusFilter, typeFilter, clientFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, trashOnly, currencyFilter], () => {
  syncFiltersToUrl()
  load(true)
})
// Výběr řádků nesmí přežít přepnutí koš ↔ aktivní doklady (jiné operace nad jinou množinou).
watch(trashOnly, () => { selectedIds.value = [] })
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) monthFilter.value = '' })
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load(true) }, 300)
})

// Reset filtrů při menu link click (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    statusFilter.value = ''
    typeFilter.value = ''
    clientFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    trashOnly.value = false
    currencyFilter.value = ''
    search.value = ''
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

const navigateRow = useRowLink()
function openInvoice(inv: InvoiceListItem, e?: MouseEvent) {
  navigateRow(`/invoices/${inv.id}`, e)
}

// Work Report modal: otevíráno z buttonu "Výkaz" v sloupci Stav.
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

// Year dropdown — distinct roky z `invoices` aktuálního supplier (issue #33).
// Composable doplňuje aktuální + minulý rok + aktuálně zvolený rok z URL.
const yearOptions = useYearOptions('invoices', yearFilter)

// `tm()` vrací raw translation message (pole), kdežto `t()` na poli vrátí stringified verzi.
// `rt()` zformátuje jednotlivé položky pole (pro případnou interpolaci).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))

// ─── Vzhledové mapy pro nové komponenty ───
const TYPE_BADGE: Record<string, 'primary' | 'accent' | 'purple' | 'amber' | 'neutral'> = {
  invoice: 'primary',
  proforma: 'accent',
  credit_note: 'purple',
  tax_document: 'amber',
  cancellation: 'neutral',
}

/** Stav → StatusDot (text stavu jde do tooltipu; po splatnosti má přednost červená). */
function dotFor(inv: InvoiceListItem): { kind: 'ok' | 'danger' | 'pending' | 'muted' | 'info'; title: string } {
  const ds = displayStatus(inv.status, inv.payment_status)
  if (isOverdue(inv.due_date, inv.status)) {
    return { kind: 'danger', title: `${statusLabel(ds)} · ${t('invoice.overdue_only')}` }
  }
  const map: Record<string, 'ok' | 'danger' | 'pending' | 'muted' | 'info'> = {
    paid: 'ok',
    partially_paid: 'pending',
    overpaid: 'info',
    issued: 'pending',
    sent: 'pending',
    reminded: 'pending',
    draft: 'muted',
    cancelled: 'muted',
  }
  return { kind: map[ds] ?? 'muted', title: statusLabel(ds) }
}
</script>

<template>
  <div>
    <!-- Hlavička stránky -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1>{{ t('invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('invoice.subtitle_grouping') }}</p>
      </div>
      <Button v-if="auth.canWrite" variant="primary" to="/invoices/new">+ {{ t('invoice.new') }}</Button>
    </div>

    <!-- Taby stavů (presety filtrů) -->
    <TabsNav :model-value="activeTab" :tabs="statusTabs" class="mb-4" @update:model-value="setTab" />

    <!-- Search + toolbar hromadných akcí + Filtry -->
    <div class="flex items-center gap-2 flex-wrap mb-3">
      <div class="relative flex-1 min-w-56 max-w-md">
        <svg class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none"
             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
        </svg>
        <input
          v-model="search"
          type="search"
          :placeholder="t('invoice.search_placeholder')"
          class="w-full h-10 pl-10 pr-4 rounded-full border border-neutral-200 bg-surface text-sm focus-visible:outline-none"
        />
      </div>

      <!-- FORK 0905 — koš: vlastní sada hromadných akcí -->
      <div v-if="trashOnly" class="flex items-center gap-2" role="toolbar" :aria-label="t('doc_trash.tab')">
        <span v-if="selectedIds.length" class="text-xs text-neutral-500 tabular-nums px-1.5">{{ selectedIds.length }}×</span>
        <Button v-if="auth.canWrite" variant="secondary" :disabled="trashBusy || selectedIds.length === 0" @click="bulkRestore">
          {{ t('doc_trash.bulk_restore') }}
        </Button>
        <button
          v-if="auth.isAdmin"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-full border border-danger-300 px-4 py-2 text-sm font-medium text-danger-600 hover:bg-danger-50 disabled:opacity-50 disabled:pointer-events-none"
          :disabled="trashBusy || selectedIds.length === 0"
          @click="openBulkForce"
        >
          {{ t('doc_trash.bulk_force') }}
        </button>
        <button
          v-if="auth.isAdmin"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-full border border-danger-300 px-4 py-2 text-sm font-medium text-danger-600 hover:bg-danger-50 disabled:opacity-50 disabled:pointer-events-none"
          :disabled="trashBusy || total === 0"
          @click="openEmptyTrash"
        >
          {{ t('doc_trash.empty_trash') }}
        </button>
      </div>

      <!-- Hromadné akce: kruhové ikony, disabled dokud výběr nesplňuje podmínky dané akce -->
      <div v-else class="flex items-center gap-1" role="toolbar" aria-label="Hromadné akce">
        <span v-if="selectedIds.length" class="text-xs text-neutral-500 tabular-nums px-1.5">{{ selectedIds.length }}×</span>
        <IconButton :label="t('invoice.bulk_pdf', { n: selectedPdfIds.length })" :disabled="bulkBusy || selectedIds.length === 0" @click="openBulkPdfExport">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16"/></svg>
        </IconButton>
        <template v-if="auth.canWrite">
          <IconButton :label="t('invoice.bulk_issue', { n: issuableSelected.length })" :disabled="bulkBusy || issuableSelected.length === 0" @click="bulkIssue">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          </IconButton>
          <IconButton :label="t('invoice.bulk_mark_paid', { n: markPayableSelected.length })" :disabled="bulkBusy || markPayableSelected.length === 0" @click="bulkMarkPaid">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          </IconButton>
          <IconButton :label="t('invoice.bulk_send', { n: sendableSelected.length })" :disabled="bulkBusy || sendableSelected.length === 0" @click="bulkSend">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
          </IconButton>
          <IconButton :label="t('invoice.bulk_reminder', { n: reminderSelected.length })" :disabled="bulkBusy || reminderSelected.length === 0" @click="bulkSendReminders">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
          </IconButton>
          <IconButton :label="t('invoice.bulk_reissue', { n: selectedIds.length })" :disabled="bulkBusy || selectedIds.length === 0" @click="bulkReissue">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>
          </IconButton>
          <!-- FORK 0905: hromadný přesun do koše -->
          <IconButton :label="t('doc_trash.bulk_trash', { n: selectedIds.length })" :disabled="bulkBusy || trashBusy || selectedIds.length === 0" @click="openBulkTrash">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          </IconButton>
        </template>
        <IconButton :label="t('invoice.csv_export')" @click="exportCsv">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 0 1 2-2h11l5 5v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        </IconButton>
      </div>

      <Button variant="secondary" @click="showFilters = !showFilters">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3z"/></svg>
        {{ t('common.filters') }}
        <span v-if="activeFilterCount" class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-primary-600 text-white text-xs tabular-nums">{{ activeFilterCount }}</span>
      </Button>
    </div>

    <!-- Panel filtrů -->
    <div v-if="showFilters" class="bg-(--surface-muted) rounded-(--radius-card) p-4 mb-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <AppSelect
          :model-value="statusFilter"
          @update:model-value="v => statusFilter = String(v)"
          :options="[
            { value: '', label: t('invoice.all_statuses') },
            { value: 'draft', label: t('status.draft') },
            { value: 'issued', label: t('status.issued') },
            { value: 'sent', label: t('status.sent') },
            { value: 'reminded', label: t('status.reminded') },
            { value: 'paid', label: t('status.paid') },
            { value: 'cancelled', label: t('status.cancelled') },
          ]"
          :placeholder="t('invoice.all_statuses')"
          :aria-label="t('invoice.all_statuses')"
        />
        <AppSelect
          :model-value="typeFilter"
          @update:model-value="v => typeFilter = String(v)"
          :options="[
            { value: '', label: t('invoice.all_types') },
            { value: 'invoice', label: t('type.invoice') },
            { value: 'proforma', label: t('type.proforma') },
            { value: 'credit_note', label: t('type.credit_note') },
          ]"
          :placeholder="t('invoice.all_types')"
          :aria-label="t('invoice.all_types')"
        />
        <SearchableSelect
          :model-value="clientFilter === '' ? null : clientFilter"
          @update:model-value="(v) => clientFilter = v === null ? '' : v"
          :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
          :placeholder="t('project.all_clients')"
        />
        <AppSelect
          :model-value="currencyFilter"
          @update:model-value="v => currencyFilter = String(v)"
          :options="[{ value: '', label: t('invoice.all_currencies') }, ...currencies.map(c => ({ value: c.code, label: c.code }))]"
          :placeholder="t('invoice.all_currencies')"
          :aria-label="t('invoice.all_currencies')"
        />
        <AppSelect
          :model-value="yearFilter"
          @update:model-value="v => yearFilter = v === '' ? '' : Number(v)"
          :disabled="!!dateFrom || !!dateTo"
          :options="[{ value: '', label: t('invoice.all_years') }, ...yearOptions.map(y => ({ value: y, label: String(y) }))]"
          :placeholder="t('invoice.all_years')"
          :aria-label="t('invoice.all_years')"
        />
        <AppSelect
          :model-value="monthFilter"
          @update:model-value="v => monthFilter = v === '' ? '' : Number(v)"
          :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          :options="[{ value: '', label: t('invoice.all_months') }, ...monthOptions.map((label, i) => ({ value: i + 1, label }))]"
          :placeholder="t('invoice.all_months')"
          :aria-label="t('invoice.month_filter')"
        />
        <DatePicker v-model="dateFrom" :max="dateTo || undefined" aria-label="Datum od" placeholder="Od: dd.mm.rrrr" />
        <DatePicker v-model="dateTo" :min="dateFrom || undefined" aria-label="Datum do" placeholder="Do: dd.mm.rrrr" />
      </div>
      <div class="flex items-center gap-6 mt-3">
        <Checkbox v-model="overdueOnly" :label="t('invoice.overdue_only')" />
        <Checkbox v-model="unpaidOnly" :label="t('invoice.unpaid_only')" />
      </div>
    </div>

    <!-- Chipy aktivních filtrů -->
    <div v-if="filterChips.length" class="flex items-center gap-2 flex-wrap mb-4">
      <span
        v-for="chip in filterChips"
        :key="chip.key + chip.label"
        class="inline-flex items-center gap-1.5 pl-3 pr-1.5 py-1 rounded-full bg-primary-50 text-primary-700 text-[13px]"
      >
        {{ chip.label }}
        <button
          type="button"
          class="cursor-pointer inline-flex items-center justify-center w-5 h-5 rounded-full hover:bg-primary-100"
          :aria-label="`${t('common.cancel')}: ${chip.label}`"
          @click="chip.clear()"
        >
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </span>
    </div>

    <div v-if="loading">
      <TableSkeleton :rows="8" :cols="7" />
    </div>

    <EmptyState v-else-if="!groups.length && trashOnly" :title="t('doc_trash.empty_state')" />
    <EmptyState v-else-if="!groups.length" :title="t('invoice.no_data')" :cta="t('invoice.issue_first')" to="/invoices/new" />

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-6">
        <!-- Sticky pruh měsíce: název + počet vlevo, součty vpravo -->
        <header class="sticky top-16 z-[5] flex items-center justify-between gap-3 bg-(--surface-muted) rounded-xl px-4 py-2.5">
          <div class="flex items-baseline gap-3 min-w-0">
            <span class="text-sm font-semibold text-neutral-800 capitalize whitespace-nowrap">{{ formatMonth(g.month) }}</span>
            <span class="text-xs text-neutral-500 whitespace-nowrap">{{ g.count }} {{ g.count === 1 ? t('invoice.doc_1') : (g.count < 5 ? t('invoice.doc_2_4') : t('invoice.doc_5plus')) }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs tabular-nums flex-wrap justify-end">
            <span v-for="tot in g.totals_per_currency" :key="tot.currency">
              <span class="text-neutral-500">{{ tot.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tot.with_vat, tot.currency) }}</span>
              <span v-if="tot.draft_with_vat !== 0" class="ml-1 text-primary-600"
                :title="t('invoice.prediction_hint', { amount: formatMoney(tot.draft_with_vat, tot.currency) })">
                → {{ formatMoney(tot.with_vat + tot.draft_with_vat, tot.currency) }}
                <span class="text-[10px] text-primary-500">{{ t('invoice.prediction') }}</span>
              </span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto mt-2">
          <table class="ui-table table-sticky-first">
            <thead>
              <tr>
                <th class="w-10">
                  <Checkbox
                    :model-value="isGroupSelected(g)"
                    :indeterminate="isGroupSelectionPartial(g)"
                    :aria-label="t('invoice.select_month', { month: formatMonth(g.month) })"
                    @update:model-value="toggleGroupSelected(g)"
                  />
                </th>
                <th class="w-32">Var. symbol</th>
                <th>{{ t('invoice.client_project') }}</th>
                <th>Typ</th>
                <th>DUZP / Vystaveno</th>
                <th v-if="!trashOnly">Splatnost</th>
                <th class="num">{{ t('invoice.amount_to_pay') }}</th>
                <th v-if="!trashOnly">Stav</th>
                <th v-if="trashOnly">{{ t('doc_trash.col_deleted') }}</th>
                <th v-if="trashOnly">{{ t('doc_trash.col_reason') }}</th>
                <th class="w-32"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="inv in g.invoices"
                :key="inv.id"
                @click="openInvoice(inv, $event)"
                @auxclick.prevent="openInvoice(inv, $event)"
                class="cursor-pointer"
                :class="invoiceRowClass(inv.due_date, inv.status)"
              >
                <td @click.stop>
                  <Checkbox
                    :model-value="selectedIds.includes(inv.id)"
                    @update:model-value="toggleSelected(inv.id)"
                  />
                </td>
                <td>
                  <RouterLink v-if="inv.varsymbol" :to="`/invoices/${inv.id}`" @click.stop
                    class="text-primary-700 font-semibold text-[13px] tabular-nums hover:underline">{{ inv.varsymbol }}</RouterLink>
                  <span v-else class="text-neutral-400 text-[13px] tabular-nums">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                </td>
                <td>
                  <div class="font-semibold text-neutral-900">{{ inv.client_company_name }}</div>
                  <div v-if="inv.project_name" class="text-xs text-neutral-500 truncate max-w-md">{{ inv.project_name }}</div>
                </td>
                <td>
                  <Badge :color="TYPE_BADGE[inv.invoice_type] ?? 'neutral'" size="sm">{{ typeLabel(inv.invoice_type) }}</Badge>
                </td>
                <td class="text-xs">
                  <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                </td>
                <td v-if="!trashOnly" class="text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td class="num font-medium">
                  {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                </td>
                <!-- FORK 0905 — koš: kdo a kdy smazal + důvod -->
                <td v-if="trashOnly" class="text-xs text-neutral-600">
                  <div>{{ inv.deleted_at ? formatDate(inv.deleted_at) : '—' }}</div>
                  <div v-if="inv.deleted_by_name" class="text-neutral-400">{{ inv.deleted_by_name }}</div>
                </td>
                <td v-if="trashOnly" class="text-xs text-neutral-600 max-w-56">
                  <span class="line-clamp-2" :title="inv.delete_reason ?? undefined">{{ inv.delete_reason || '—' }}</span>
                </td>
                <td v-if="!trashOnly" @click.stop>
                  <!-- Pro koncepty (s právem editace) zobraz tlačítko "Výkaz" místo stavu — rychlý přístup k modalu. -->
                  <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite"
                    @click="openWorkReport(inv.id)"
                    class="cursor-pointer text-xs px-2.5 py-1 rounded-full border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                    :title="t('invoice.wr_btn')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                    {{ t('invoice.wr_btn') }}
                  </button>
                  <span v-else class="inline-flex items-center gap-1">
                    <StatusDot :kind="dotFor(inv).kind" :title="dotFor(inv).title" size="sm" />
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded-full bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded-full bg-warning-50 text-warning-600 font-semibold tabular-nums"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                  </span>
                </td>
                <!-- FORK 0905 — koš: jen Obnovit / Smazat trvale -->
                <td v-if="trashOnly" @click.stop>
                  <span class="row-actions inline-flex items-center gap-0.5 justify-end w-full">
                    <IconButton v-if="auth.canWrite" :label="t('doc_trash.restore')" size="sm" :disabled="trashBusy" @click="rowRestore(inv)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 0 1 8 8v2M3 10l6 6m-6-6l6-6"/></svg>
                    </IconButton>
                    <IconButton v-if="auth.isAdmin" :label="t('doc_trash.force_delete')" size="sm" :disabled="trashBusy" @click="rowForceDelete(inv)">
                      <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
                    </IconButton>
                    <IconButton :label="t('common.view_all')" size="sm" :to="`/invoices/${inv.id}`">
                      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                    </IconButton>
                  </span>
                </td>
                <td v-else @click.stop>
                  <!-- Rychlé zkratky k existujícím akcím — viditelné při hoveru řádku -->
                  <span class="row-actions inline-flex items-center gap-0.5 justify-end w-full">
                    <IconButton v-if="inv.status === 'draft' && auth.canWrite" :label="t('common.edit')" size="sm" :to="`/invoices/${inv.id}/edit`">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82l-4.207 1.02 1.02-4.207L16.862 4.487Z"/></svg>
                    </IconButton>
                    <IconButton label="PDF" size="sm" @click="rowPdf(inv)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                    </IconButton>
                    <IconButton v-if="auth.canWrite" :label="t('invoice.clone')" size="sm" :disabled="rowBusyId === inv.id" @click="rowClone(inv)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>
                    </IconButton>
                    <IconButton :label="t('common.view_all')" size="sm" :to="`/invoices/${inv.id}`">
                      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                    </IconButton>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden mt-2 divide-y divide-neutral-100 rounded-xl overflow-hidden bg-surface">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv, $event)"
            @auxclick.prevent="openInvoice(inv, $event)"
            class="cursor-pointer hover:bg-(--surface-muted) transition px-3 py-3"
            :class="invoiceRowClass(inv.due_date, inv.status)"
          >
            <div class="flex items-start gap-3">
              <div @click.stop class="mt-0.5">
                <Checkbox
                  :model-value="selectedIds.includes(inv.id)"
                  @update:model-value="toggleSelected(inv.id)"
                />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-semibold text-neutral-900 truncate">{{ inv.client_company_name }}</div>
                  <div class="text-sm font-semibold whitespace-nowrap tabular-nums">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <span class="tabular-nums">
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                    </span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ typeLabel(inv.invoice_type) }}</span>
                    <span v-if="inv.project_name" class="text-neutral-400"> · </span>
                    <span v-if="inv.project_name" class="truncate">{{ inv.project_name }}</span>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1.5 flex-wrap justify-end" @click.stop>
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded-full bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded-full bg-warning-50 text-warning-600 font-semibold tabular-nums"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                    <!-- Pro koncepty (s právem editace) tlačítko "Výkaz" — stejně jako v desktop tabulce. -->
                    <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite"
                      @click="openWorkReport(inv.id)"
                      class="cursor-pointer text-xs px-2.5 py-1 rounded-full border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                      :title="t('invoice.wr_btn')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                      {{ t('invoice.wr_btn') }}
                    </button>
                    <span v-else class="inline-flex items-center gap-1.5">
                      <StatusDot :kind="dotFor(inv).kind" :title="dotFor(inv).title" size="sm" />
                      <span class="text-xs text-neutral-500">{{ dotFor(inv).title }}</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-4">
        <Button variant="secondary" :loading="loadingMore" @click="load(false)">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
        </Button>
      </div>
    </div>

    <!-- Hromadný PDF export -->
    <Modal v-if="bulkPdfOpen" :title="t('invoice.bulk_pdf_title')" width-class="max-w-md" @close="bulkPdfOpen = false">
      <div class="space-y-4">
        <p class="text-sm text-neutral-500">{{ t('invoice.bulk_pdf_hint', { n: selectedPdfIds.length }) }}</p>
        <div class="rounded-(--radius-input) bg-(--surface-muted) p-3">
          <Checkbox v-model="bulkPdfSign">
            <span class="block text-sm font-medium text-neutral-800">{{ t('invoice.bulk_pdf_sign') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('invoice.bulk_pdf_sign_hint') }}</span>
          </Checkbox>
        </div>
        <p v-if="selectedPdfIds.length > 100" class="text-sm text-danger-500">
          {{ t('invoice.bulk_pdf_limit') }}
        </p>
      </div>
      <template #footer>
        <Button variant="ghost" :disabled="bulkBusy" @click="bulkPdfOpen = false">{{ t('common.cancel') }}</Button>
        <Button variant="primary" :loading="bulkBusy" :disabled="selectedPdfIds.length > 100" @click="bulkExportPdf">
          {{ t('invoice.bulk_pdf_download') }}
        </Button>
      </template>
    </Modal>

    <!-- Work report modal — otevřený z buttonu "Výkaz" v sloupci Stav. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="load(true)" />

    <!-- FORK 0905 — potvrzovací dialog koše / trvalého smazání / vysypání -->
    <DocumentTrashModal
      v-if="trashModalOpen"
      :mode="trashModalMode"
      :docs="trashModalDocs"
      :is-admin="auth.isAdmin"
      :busy="trashBusy"
      @close="trashModalOpen = false"
      @confirm="confirmTrashModal"
    />
  </div>
</template>
