<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

const { t } = useI18n()
import { dashboardApi, type DashboardSummary } from '@/api/dashboard'
import { formatMoney, formatDate } from '@/composables/useFormat'
import SparklineChart from '@/components/charts/SparklineChart.vue'
import RevenueBarsChart from '@/components/charts/RevenueBarsChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import TaxNetWidget from '@/components/dashboard/TaxNetWidget.vue'
import ActionItemsWidget from '@/components/dashboard/ActionItemsWidget.vue'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'

const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

// Onboarding gate (#151): bez dodavatele je dashboard prázdný a všechny zakládací akce
// padají → místo dat ukážeme výzvu k vytvoření prvního dodavatele.
const hasSupplier = computed(() => supplierStore.hasSupplier)

const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)
const error = ref('')

async function loadSummary() {
  // Žádný dodavatel → nenačítej summary (vrátilo by jen prázdná data), ukaž gate.
  if (!hasSupplier.value) {
    loading.value = false
    return
  }
  try {
    summary.value = await dashboardApi.summary()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
}

onMounted(loadSummary)

// Výkaz práce modal — otevíráno z tlačítka „Výkaz" na kartě konceptu (stejný popup jako v /invoices).
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

const upcomingPerCurrency = computed(() => {
  if (!summary.value) return [] as Array<{ currency: string; total: number }>
  const map = new Map<string, number>()
  for (const i of summary.value.unpaid_upcoming) {
    map.set(i.currency, (map.get(i.currency) ?? 0) + Number(i.amount_to_pay || 0))
  }
  return Array.from(map, ([currency, total]) => ({ currency, total }))
})

const hasAnyData = computed(() => {
  if (!summary.value || !summary.value.kpi) return false
  return (summary.value.kpi.issued_count_ytd ?? 0) > 0
      || (summary.value.overdue?.length ?? 0) > 0
      || (summary.value.unpaid_upcoming?.length ?? 0) > 0
})

function openInvoice(id: number) {
  router.push(`/invoices/${id}`)
}

// ─── Graf obratu: Měsíce/Kvartály/Roky = client-side agregace 12M datasetu (API beze změny) ───
const revenuePeriod = ref<'months' | 'quarters' | 'years'>('months')
const periodOptions = computed(() => [
  { value: 'months', label: t('dashboard.period_months') },
  { value: 'quarters', label: t('dashboard.period_quarters') },
  { value: 'years', label: t('dashboard.period_years') },
])

/** Data grafu pro měnu dle zvoleného období. Map drží pořadí vkládání → chronologie sedí. */
function revenueSeries(currency: string): { labels: string[]; values: number[] } {
  const rev = summary.value?.revenue_by_month.find(r => r.currency === currency)
  if (!rev) return { labels: [], values: [] }
  if (revenuePeriod.value === 'months') {
    return {
      labels: rev.months.map(m => { const [y, mo] = m.ym.split('-'); return `${mo}/${(y ?? '').slice(2)}` }),
      values: rev.months.map(m => m.total),
    }
  }
  const acc = new Map<string, number>()
  for (const m of rev.months) {
    const [y, mo] = m.ym.split('-')
    const key = revenuePeriod.value === 'years'
      ? (y ?? '')
      : `Q${Math.floor((Number(mo) - 1) / 3) + 1}/${(y ?? '').slice(2)}`
    acc.set(key, (acc.get(key) ?? 0) + m.total)
  }
  return { labels: [...acc.keys()], values: [...acc.values()] }
}

/** Měny, které mají v grafu co ukázat (nenulová data). */
const chartCurrencies = computed(() =>
  (summary.value?.kpi?.per_currency ?? [])
    .map(c => c.currency)
    .filter(cur => (summary.value?.revenue_by_month.find(r => r.currency === cur)?.months ?? []).some(m => m.total !== 0))
)

// Mini graf nákladů (přijaté faktury, 12 měsíců, CZK) — místo boxu CRM.
const costsSparkline = computed(() => {
  const arr = summary.value?.purchase_costs_by_month ?? []
  return {
    labels: arr.map(m => { const [y, mo] = m.ym.split('-'); return `${mo}/${y}` }),
    values: arr.map(m => m.total),
  }
})
const hasCostsData = computed(() => (summary.value?.purchase_costs_by_month ?? []).some(m => m.total !== 0))

// ─── Sdílené třídy KPI dlaždic (label 13 muted / hodnota 32/700 / kontext 12) ───
const TILE = 'rounded-(--radius-card) p-5 cursor-pointer transition-colors'
const TILE_BG = 'bg-(--surface-muted) hover:bg-neutral-100'
const TILE_LABEL = 'text-[13px] text-neutral-500 mb-1'
const TILE_VALUE = 'text-[32px] font-bold leading-tight tabular-nums'
</script>

<template>
  <div>
    <!-- Onboarding gate (#151): dodavatel přeskočen v setupu → bez něj nelze nic založit -->
    <div v-if="!hasSupplier" class="max-w-2xl mx-auto bg-(--surface-muted) rounded-(--radius-card) p-8 text-center">
      <div class="w-14 h-14 bg-primary-100 rounded-full mx-auto mb-4 flex items-center justify-center">
        <svg class="w-7 h-7 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
        </svg>
      </div>
      <h2 class="mb-2">{{ t('dashboard.no_supplier.title') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('dashboard.no_supplier.intro') }}</p>
      <RouterLink v-if="isAdmin" to="/admin/codebooks?create=supplier"
        class="px-5 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full">
        {{ t('dashboard.no_supplier.cta_admin') }}
      </RouterLink>
      <p v-else class="text-sm text-neutral-600">{{ t('dashboard.no_supplier.non_admin') }}</p>
    </div>

    <template v-else>
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-(--radius-input) bg-danger-50 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <div v-else-if="!hasAnyData" class="bg-(--surface-muted) rounded-(--radius-card) p-8 text-center">
      <h2 class="mb-2">{{ t('dashboard.welcome') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('common.no_data') }}</p>
      <div class="flex justify-center gap-3">
        <RouterLink v-if="auth.canWrite" to="/clients/new" class="px-4 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full">
          {{ t('client.new') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite" to="/invoices/new" class="px-4 h-10 inline-flex items-center border-[1.5px] border-primary-600 text-primary-700 hover:bg-primary-50 text-sm font-medium rounded-full">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-else-if="summary && summary.kpi" class="space-y-8">
      <!-- ═══ Akce pro tebe (přesunuto z CRM) — první část Přehledu (skryto pro readonly) ═══ -->
      <ActionItemsWidget v-if="auth.canWrite" />

      <!-- ═══ Výkazy práce — rozpracované (draft) vystavené faktury k doplnění (skryto pro readonly) ═══ -->
      <section v-if="auth.canWrite && summary.draft_invoices && summary.draft_invoices.length" class="space-y-3">
        <div class="flex items-baseline gap-2 flex-wrap">
          <h2>{{ t('dashboard.work_reports.title') }}</h2>
          <span class="text-xs text-neutral-400">{{ t('dashboard.work_reports.hint') }}</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-(--grid-gap)">
          <div v-for="d in summary.draft_invoices" :key="d.id"
            class="bg-(--surface-muted) rounded-(--radius-card) p-4 flex flex-col gap-2">
            <div class="flex items-start justify-between gap-2">
              <RouterLink :to="`/clients/${d.client_id}`" class="font-medium text-neutral-900 hover:text-primary-700 hover:underline truncate" :title="d.client_company_name">
                {{ d.client_company_name }}
              </RouterLink>
              <span class="text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-500 font-medium whitespace-nowrap">
                {{ t('status.draft') }}
              </span>
            </div>
            <div class="text-xs text-neutral-500 min-h-[1rem] truncate" :title="d.project_name || ''">
              <span v-if="d.project_name">📁 {{ d.project_name }}</span>
              <span v-else class="text-neutral-300">{{ t('dashboard.work_reports.no_project') }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 mt-auto pt-2 border-t border-neutral-200/60">
              <span class="text-xs tabular-nums text-neutral-600 truncate">
                <span v-if="d.varsymbol">{{ d.varsymbol }} · </span>{{ formatMoney(d.total_with_vat, d.currency) }}
              </span>
              <div class="flex items-center gap-1.5 shrink-0">
                <RouterLink :to="`/invoices/${d.id}/edit`"
                  class="cursor-pointer inline-flex items-center justify-center h-7 px-2.5 rounded-full border border-neutral-300 text-neutral-700 hover:bg-surface text-xs font-medium">
                  {{ t('common.edit') }}
                </RouterLink>
                <button type="button" @click="openWorkReport(d.id)"
                  class="cursor-pointer inline-flex items-center gap-1 h-7 px-2.5 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                  {{ t('dashboard.work_reports.button') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Daňový widget „co mi zbyde" — jen pro OSVČ (komponenta se sama skryje jinak) -->
      <TaxNetWidget />

      <!-- ═══ Sekce 1: VYSTAVENÉ FAKTURY — KPI dlaždice + graf obratu ═══ -->
      <section class="space-y-4">
        <h2>{{ t('dashboard.section_issued') }}</h2>

        <!-- KPI dlaždice: 12sloupcový grid, gap 24, mobil 1 sloupec -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-(--grid-gap)">
          <!-- Obrat per měna -->
          <div v-for="c in summary.kpi.per_currency" :key="c.currency"
            @click="router.push({ path: '/invoices', query: { year: String(summary.year), currency: c.currency } })"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3']">
            <div :class="TILE_LABEL">{{ t('dashboard.revenue', { year: summary.year, currency: c.currency }) }}</div>
            <div :class="TILE_VALUE">{{ formatMoney(c.this_year, c.currency) }}</div>
            <div v-if="c.change_pct !== null" class="text-xs mt-1" :class="c.change_pct >= 0 ? 'text-success-600' : 'text-danger-500'"
              :title="t('dashboard.yoy_ytd_tooltip', { year: summary.prev_year, total: formatMoney(c.prev_year, c.currency), ytd: formatMoney(c.prev_year_ytd, c.currency) })">
              {{ c.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(c.change_pct) }} % {{ t('dashboard.vs_prev_ytd', { year: summary.prev_year }) }}
            </div>
            <div v-else class="text-xs text-neutral-400 mt-1">{{ t('dashboard.no_prev_year', { year: summary.prev_year }) }}</div>
          </div>

          <div @click="router.push({ path: '/invoices', query: { year: String(summary.year) } })"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3']">
            <div :class="TILE_LABEL">{{ t('dashboard.issued_count', { year: summary.year }) }}</div>
            <div :class="TILE_VALUE">{{ summary.kpi.issued_count_ytd }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.invoices_unit') }}</div>
          </div>

          <!-- Po splatnosti: jemné červené pozadí (bez borderu), jen když nenulové -->
          <div @click="router.push({ path: '/invoices', query: { year: 'all', overdue: '1' } })"
            :class="[TILE, 'md:col-span-6 lg:col-span-3', summary.kpi.overdue_count > 0 ? 'bg-danger-50 hover:bg-danger-50' : TILE_BG]">
            <div :class="TILE_LABEL">{{ t('dashboard.overdue') }}</div>
            <div :class="[TILE_VALUE, summary.kpi.overdue_count > 0 ? 'text-danger-500' : '']">
              {{ summary.kpi.overdue_count }}
            </div>
            <div class="text-xs mt-1 flex flex-wrap gap-x-3" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <span v-for="o in summary.kpi.overdue_per_currency" :key="o.currency">{{ formatMoney(o.total, o.currency) }}</span>
              <span v-if="summary.kpi.overdue_count === 0">{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <div @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3']">
            <div :class="TILE_LABEL">{{ t('dashboard.upcoming') }}</div>
            <div :class="TILE_VALUE">{{ summary.unpaid_upcoming.length }}</div>
            <div class="text-xs mt-1 text-neutral-400 flex flex-wrap gap-x-3">
              <span v-for="u in upcomingPerCurrency" :key="u.currency">{{ formatMoney(u.total, u.currency) }}</span>
              <span v-if="!upcomingPerCurrency.length">{{ t('dashboard.upcoming_none') }}</span>
            </div>
          </div>

          <div @click="router.push('/stats')"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3']">
            <div :class="TILE_LABEL">{{ t('dashboard.avg_payment') }}</div>
            <div :class="TILE_VALUE">
              {{ summary.kpi.avg_payment_days !== null ? summary.kpi.avg_payment_days + ' ' + t('dashboard.days') : '—' }}
            </div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.this_year_paid') }}</div>
          </div>

          <!-- Čekající schválení (admin, jen když existují) -->
          <RouterLink
            v-if="isAdmin && summary.pending_approvals && summary.pending_approvals.requested > 0"
            to="/admin/approvals"
            :class="[TILE, 'md:col-span-6 lg:col-span-3', summary.pending_approvals.overdue > 0 ? 'bg-warning-50 hover:bg-warning-50' : 'bg-primary-50 hover:bg-primary-100']">
            <div :class="TILE_LABEL">{{ t('dashboard.pending_approvals') }}</div>
            <div :class="[TILE_VALUE, summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-primary-700']">
              {{ summary.pending_approvals.requested }}
            </div>
            <div class="text-xs mt-1"
              :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-neutral-400'">
              <span v-if="summary.pending_approvals.overdue > 0">
                {{ t('dashboard.pending_approvals_overdue', { n: summary.pending_approvals.overdue }) }}
              </span>
              <span v-else>{{ t('dashboard.pending_approvals_hint') }}</span>
            </div>
          </RouterLink>

          <!-- Graf obratu: osy, gridlines, tooltip, zaoblené sloupce s gradientem + přepínač období -->
          <div v-if="chartCurrencies.length" class="md:col-span-12 bg-(--surface-muted) rounded-(--radius-card) p-5">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
              <h3 class="text-[13px] font-medium text-neutral-500">{{ t('dashboard.revenue_chart_title') }}</h3>
              <SegmentedControl
                :model-value="revenuePeriod"
                :options="periodOptions"
                size="sm"
                @update:model-value="v => revenuePeriod = String(v) as 'months' | 'quarters' | 'years'"
              />
            </div>
            <div class="space-y-6">
              <div v-for="cur in chartCurrencies" :key="cur">
                <div v-if="chartCurrencies.length > 1" class="text-xs text-neutral-500 mb-1">{{ cur }}</div>
                <RevenueBarsChart
                  :labels="revenueSeries(cur).labels"
                  :values="revenueSeries(cur).values"
                  :format="(v: number) => formatMoney(v, cur)"
                  :height="230"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ Sekce 2: PŘIJATÉ FAKTURY (visible jen pokud existují YTD) ═══ -->
      <section v-if="(summary.kpi.purchase_count_ytd ?? 0) > 0" class="space-y-4">
        <h2>{{ t('dashboard.section_purchase') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-(--grid-gap)">
          <!-- Costs YTD -->
          <div @click="router.push({ path: '/purchase-invoices', query: { year: String(summary.year) } })"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3']">
            <div :class="TILE_LABEL">{{ t('dashboard.purchase_costs_ytd', { year: summary.year }) }}</div>
            <div :class="TILE_VALUE">{{ formatMoney(summary.kpi.purchase_costs_ytd, 'CZK') }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ summary.kpi.purchase_count_ytd }} {{ t('dashboard.invoices_unit') }}</div>
          </div>

          <!-- Unpaid -->
          <div @click="router.push({ path: '/purchase-invoices', query: { unpaid: '1' } })"
            :class="[TILE, 'md:col-span-6 lg:col-span-3', (summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'bg-warning-50 hover:bg-warning-50' : TILE_BG]">
            <div :class="TILE_LABEL">{{ t('dashboard.purchase_unpaid') }}</div>
            <div :class="[TILE_VALUE, (summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : '']">
              {{ summary.kpi.purchase_unpaid_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : 'text-neutral-400'">
              {{ formatMoney(summary.kpi.purchase_unpaid_total, 'CZK') }}
            </div>
          </div>

          <!-- Overdue (vždy zobrazené pro grid alignment, červené pokud > 0) -->
          <div @click="router.push({ path: '/purchase-invoices', query: { overdue: '1' } })"
            :class="[TILE, 'md:col-span-6 lg:col-span-3', (summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'bg-danger-50 hover:bg-danger-50' : TILE_BG]">
            <div :class="TILE_LABEL">{{ t('dashboard.purchase_overdue') }}</div>
            <div :class="[TILE_VALUE, (summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : '']">
              {{ summary.kpi.purchase_overdue_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <span v-if="(summary.kpi.purchase_overdue_count ?? 0) > 0" class="hover:underline">
                {{ t('dashboard.purchase_overdue_link') }}
              </span>
              <span v-else>{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <!-- 4. box: mini graf nákladů (12 měsíců, CZK) — odkaz do CRM analytics -->
          <RouterLink to="/crm"
            :class="[TILE, TILE_BG, 'md:col-span-6 lg:col-span-3 flex flex-col']">
            <div :class="TILE_LABEL">{{ t('dashboard.costs_trend_12m') }}</div>
            <div v-if="hasCostsData" class="mt-1 flex-1 flex items-end">
              <SparklineChart class="w-full"
                :labels="costsSparkline.labels" :values="costsSparkline.values"
                :format="(v: number) => formatMoney(v, 'CZK')" color="#D9822B" :height="48" />
            </div>
            <div v-else class="text-2xl font-semibold text-primary-700">CRM →</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.costs_trend_hint') }}</div>
          </RouterLink>
        </div>
      </section>

      <!-- ═══ Sekce 3: SPLATNOST POHLEDÁVEK (vystavené, příchozí platby) ═══ -->
      <section v-if="summary.due_buckets.length" class="space-y-4">
        <div class="flex items-baseline gap-2 flex-wrap">
          <h2>{{ t('dashboard.section_receivables_due') }}</h2>
          <span class="text-xs text-neutral-400">{{ t('dashboard.receivables_hint') }}</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-(--grid-gap)">
          <div v-for="b in summary.due_buckets" :key="`db-today-${b.currency}`"
            @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
            :class="[TILE, 'md:col-span-4', b.today_count > 0 ? 'bg-warning-50 hover:bg-warning-50' : TILE_BG]">
            <div :class="TILE_LABEL">{{ t('dashboard.due_today') }}</div>
            <div :class="[TILE_VALUE, b.today_count > 0 ? 'text-warning-600' : 'text-neutral-300']">
              {{ b.today_count }}
            </div>
            <div class="text-xs mt-1 tabular-nums" :class="b.today_count > 0 ? 'text-neutral-700' : 'text-neutral-400'">
              {{ formatMoney(b.today_total, b.currency) }}
            </div>
          </div>
          <div v-for="b in summary.due_buckets" :key="`db-week-${b.currency}`"
            @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
            :class="[TILE, TILE_BG, 'md:col-span-4']">
            <div :class="TILE_LABEL">{{ t('dashboard.due_this_week') }}</div>
            <div :class="TILE_VALUE">{{ b.week_count }}</div>
            <div class="text-xs mt-1 tabular-nums text-neutral-500">{{ formatMoney(b.week_total, b.currency) }}</div>
          </div>
          <div v-for="b in summary.due_buckets" :key="`db-month-${b.currency}`"
            @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
            :class="[TILE, TILE_BG, 'md:col-span-4']">
            <div :class="TILE_LABEL">{{ t('dashboard.due_this_month') }}</div>
            <div :class="TILE_VALUE">{{ b.month_count }}</div>
            <div class="text-xs mt-1 tabular-nums text-neutral-500">{{ formatMoney(b.month_total, b.currency) }}</div>
          </div>
        </div>
      </section>

      <!-- Cash-flow forecast 30 / 60 / 90 dní — kolik se očekává inkasovat -->
      <div v-if="summary.cashflow_forecast.length" class="bg-(--surface-muted) rounded-(--radius-card) p-5">
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-[13px] font-medium text-neutral-500">{{ t('dashboard.cashflow_forecast') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('dashboard.cashflow_forecast_hint') }}</span>
        </div>
        <div class="grid grid-cols-3 gap-(--grid-gap)">
          <div v-for="period in ['30','60','90']" :key="`cf-${period}`" class="text-center">
            <div class="text-[13px] text-neutral-500 mb-1">{{ t('dashboard.cashflow_in_days', { n: period }) }}</div>
            <div class="space-y-0.5">
              <div v-for="cf in summary.cashflow_forecast" :key="`cf-${period}-${cf.currency}`"
                class="tabular-nums text-lg font-bold text-neutral-900">
                {{ formatMoney(period === '30' ? cf.in_30 : period === '60' ? cf.in_60 : cf.in_90, cf.currency) }}
              </div>
            </div>
            <div class="text-xs text-neutral-500 mt-1 space-y-0.5">
              <div v-for="cf in summary.cashflow_forecast" :key="`cf-cnt-${period}-${cf.currency}`">
                {{ period === '30' ? cf.count_30 : period === '60' ? cf.count_60 : cf.count_90 }} {{ t('dashboard.invoices_unit') }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-(--grid-gap)">
        <!-- Po splatnosti -->
        <div class="min-w-0">
          <header class="pb-2 flex items-center justify-between">
            <h3 class="text-[15px] font-semibold">{{ t('dashboard.overdue_table') }}</h3>
            <div class="flex items-center gap-2">
              <span v-if="summary.overdue.length" class="text-xs px-2 py-0.5 rounded-full bg-danger-50 text-danger-500">
                {{ summary.overdue.length }}
              </span>
              <RouterLink :to="{ path: '/invoices', query: { year: 'all', overdue: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <div v-if="!summary.overdue.length" class="p-6 text-center text-sm text-neutral-500 bg-(--surface-muted) rounded-(--radius-card)">
            {{ t('dashboard.overdue_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="ui-table table-sticky-first">
            <thead>
              <tr>
                <th>{{ t('type.invoice') }}</th>
                <th>{{ t('nav.clients') }}</th>
                <th class="num">{{ t('invoice.amount_to_pay') }}</th>
                <th>{{ t('dashboard.overdue') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in summary.overdue" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer">
                <td class="text-[13px] tabular-nums text-primary-700 font-semibold">
                  {{ i.varsymbol }}
                  <span v-if="i.invoice_type === 'proforma'" class="ml-1 px-1.5 py-0.5 rounded-full bg-primary-50 text-primary-700 text-[10px] font-medium">{{ t('type.proforma') }}</span>
                </td>
                <td class="truncate max-w-[200px] font-medium">{{ i.client_company_name }}</td>
                <td class="num">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td>
                  <span class="text-xs px-2 py-0.5 rounded-full bg-danger-50 text-danger-500 font-medium tabular-nums">
                    +{{ i.days_overdue }}d
                  </span>
                </td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.overdue.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.overdue" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-(--surface-muted) px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="tabular-nums text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5">
                <span class="flex items-center gap-1.5 min-w-0">
                  <span class="tabular-nums text-xs text-neutral-500">{{ i.varsymbol }}</span>
                  <span v-if="i.invoice_type === 'proforma'" class="px-1.5 py-0.5 rounded-full bg-primary-50 text-primary-700 text-[10px] font-medium">{{ t('type.proforma') }}</span>
                </span>
                <span class="text-xs px-1.5 py-0.5 rounded-full bg-danger-50 text-danger-500 font-medium whitespace-nowrap tabular-nums">
                  +{{ i.days_overdue }}d
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Nezaplacené -->
        <div class="min-w-0">
          <header class="pb-2 flex items-center justify-between">
            <h3 class="text-[15px] font-semibold">{{ t('dashboard.unpaid_upcoming') }}</h3>
            <div class="flex items-center gap-2">
              <span v-if="summary.unpaid_upcoming.length" class="text-xs px-2 py-0.5 rounded-full bg-primary-100 text-primary-700">
                {{ summary.unpaid_upcoming.length }}
              </span>
              <RouterLink :to="{ path: '/invoices', query: { year: 'all', unpaid: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <div v-if="!summary.unpaid_upcoming.length" class="p-6 text-center text-sm text-neutral-500 bg-(--surface-muted) rounded-(--radius-card)">
            {{ t('dashboard.unpaid_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="ui-table table-sticky-first">
            <thead>
              <tr>
                <th>{{ t('type.invoice') }}</th>
                <th>{{ t('nav.clients') }}</th>
                <th class="num">{{ t('invoice.amount_to_pay') }}</th>
                <th>{{ t('invoice.due_date') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in summary.unpaid_upcoming" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer">
                <td class="text-[13px] tabular-nums text-primary-700 font-semibold">{{ i.varsymbol }}</td>
                <td class="truncate max-w-[200px] font-medium">{{ i.client_company_name }}</td>
                <td class="num">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="text-xs">{{ formatDate(i.due_date) }}</td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.unpaid_upcoming.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.unpaid_upcoming" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-(--surface-muted) px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="tabular-nums text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                <span class="tabular-nums">{{ i.varsymbol }}</span>
                <span class="tabular-nums">{{ formatDate(i.due_date) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top klienti — posledních 12 měsíců: tabulka vlevo, koláč vpravo -->
      <div v-if="summary.top_clients_12m.length" class="grid grid-cols-1 lg:grid-cols-2 gap-(--grid-gap) items-start">
      <div class="min-w-0">
        <header class="pb-2">
          <h3 class="text-[15px] font-semibold">{{ t('dashboard.top_clients_12m') }}</h3>
        </header>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="ui-table table-sticky-first">
          <thead>
            <tr>
              <th class="w-8">#</th>
              <th>{{ t('nav.clients') }}</th>
              <th class="num">Faktur</th>
              <th class="num">Obrat</th>
              <th class="w-32">{{ t('common.share') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(c, i) in summary.top_clients_12m" :key="c.client_id" class="cursor-pointer"
                @click="router.push(`/clients/${c.client_id}`)">
              <td class="text-neutral-400 tabular-nums text-xs">{{ i + 1 }}</td>
              <td class="font-medium">
                {{ c.company_name }}
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400 font-normal">({{ c.currencies }})</span>
              </td>
              <td class="num text-xs text-neutral-600">{{ c.invoice_count }}</td>
              <td class="num">{{ formatMoney(c.total_czk, 'CZK') }}</td>
              <td>
                <div class="h-2 bg-neutral-100 rounded-full overflow-hidden">
                  <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: kompaktní list s share bar -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="(c, i) in summary.top_clients_12m" :key="`m-${c.client_id}`"
            @click="router.push(`/clients/${c.client_id}`)"
            class="cursor-pointer hover:bg-(--surface-muted) px-3 py-2.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2 min-w-0">
                <span class="text-neutral-400 tabular-nums text-xs whitespace-nowrap">{{ i + 1 }}.</span>
                <span class="font-medium text-neutral-900 truncate">{{ c.company_name }}</span>
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="text-xs text-neutral-400 whitespace-nowrap">({{ c.currencies }})</span>
              </div>
              <div class="tabular-nums text-sm whitespace-nowrap">{{ formatMoney(c.total_czk, 'CZK') }}</div>
            </div>
            <div class="flex items-center gap-2 mt-1.5">
              <div class="h-1.5 flex-1 bg-neutral-100 rounded-full overflow-hidden">
                <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
              </div>
              <span class="text-xs text-neutral-500 tabular-nums whitespace-nowrap">{{ c.invoice_count }}×</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Koláč Top klienti — totožná data, druhý úhel pohledu (vždy v CZK po přepočtu) -->
      <div class="bg-(--surface-muted) rounded-(--radius-card) p-5">
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-[13px] font-medium text-neutral-500">{{ t('dashboard.top_clients_12m_share') }}</h3>
          <span class="text-xs tabular-nums text-neutral-500">CZK</span>
        </div>
        <TopClientsPieChart :clients="summary.top_clients_12m" />
      </div>
      </div>
    </div>
    </template>

    <!-- Výkaz práce modal — otevřený z tlačítka „Výkaz" na kartě konceptu. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="loadSummary" />
  </div>
</template>
