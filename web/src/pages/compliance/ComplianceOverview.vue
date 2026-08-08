<script setup lang="ts">
// FORK 0925 — Přehled rizik (Dokument 7). Zásady: výchozí pohled PODLE ZAKÁZEK
// (rozdělenou hotovost nejde vidět na úrovni dokladu), odbavený příznak NEMIZÍ
// (jen zesvětlá pruh), hromadné odbavení NEEXISTUJE, texty voleb v draweru jsou
// znak po znaku shodné s modalem (sdílené i18n compliance.choice_*).
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { complianceApi, type ComplianceFlag, type ComplianceSummary } from '@/api/compliance'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const loading = ref(true)
const flags = ref<ComplianceFlag[]>([])
const summary = ref<ComplianceSummary | null>(null)

const yearFilter = ref<number>(new Date().getFullYear())
const severityFilter = ref('')
const statusFilter = ref('unresolved')
const typeFilter = ref('')
const view = ref<'projects' | 'documents'>('projects')

async function load() {
  loading.value = true
  try {
    const [f, s] = await Promise.all([
      complianceApi.flags({
        year: yearFilter.value,
        severity: severityFilter.value || undefined,
        status: statusFilter.value || undefined,
        type: typeFilter.value || undefined,
      }),
      complianceApi.summary(),
    ])
    flags.value = f
    summary.value = s
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch([yearFilter, severityFilter, statusFilter, typeFilter], load)

const types = computed(() => [...new Set(flags.value.map(f => f.type))])

// ── Pohled „Podle zakázek": skupina nese NEJVYŠŠÍ závažnost svých příznaků ──
const SEVERITY_ORDER = { high: 0, medium: 1, low: 2 } as const
const byProject = computed(() => {
  const groups = new Map<string, { key: string; project_id: number | null; title: string; vin: string | null; flags: ComplianceFlag[] }>()
  for (const f of flags.value) {
    const key = f.project_id ? `p${f.project_id}` : (f.client_id ? `c${f.client_id}` : 'none')
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        project_id: f.project_id,
        title: f.project_name || f.client_company_name || (t('compliance.no_project') as string),
        vin: f.project_car_vin ?? f.car_vin,
        flags: [],
      })
    }
    groups.get(key)!.flags.push(f)
  }
  return [...groups.values()].sort((a, b) => {
    const sa = Math.min(...a.flags.map(f => SEVERITY_ORDER[f.severity]))
    const sb = Math.min(...b.flags.map(f => SEVERITY_ORDER[f.severity]))
    if (sa !== sb) return sa - sb
    return (b.flags[0]?.detected_at ?? '').localeCompare(a.flags[0]?.detected_at ?? '')
  })
})

function severityDot(s: string): string {
  return s === 'high' ? 'bg-danger-500' : s === 'medium' ? 'bg-warning-500' : 'bg-neutral-400'
}
function stripeClass(f: ComplianceFlag): string {
  // „Svícení" (Dokument 7 §5): HIGH open = plný červený pruh; po odbavení
  // zesvětlá na 30 %, ale NIKDY nezmizí.
  if (f.severity !== 'high') return ''
  return f.status === 'open' ? 'border-l-[3px] border-danger-500' : 'border-l-[3px] border-danger-500/30'
}
function statusLabel(f: ComplianceFlag): string {
  return t(`compliance.status_${f.status}`) as string
}
function subjectRoute(f: ComplianceFlag): string | null {
  switch (f.subject_type) {
    case 'invoice': return `/invoices/${f.subject_id}`
    case 'purchase_invoice': return `/purchase-invoices/${f.subject_id}`
    case 'cash_document': return '/cash-documents'
    case 'project': return `/projects/${f.subject_id}`
    case 'client': return `/clients/${f.subject_id}`
    default: return null
  }
}
function amountOf(f: ComplianceFlag): string | null {
  const a = (f.context as any)?.amount ?? (f.context as any)?.window_sum ?? (f.context as any)?.doc_sum
  return typeof a === 'number' ? formatMoney(a, 'CZK') : null
}

// ── Drawer (Dokument 7 §9) ───────────────────────────────────────────────
const drawer = ref<ComplianceFlag | null>(null)
const drawerChoice = ref('')
const drawerNote = ref('')
function openDrawer(f: ComplianceFlag) {
  drawer.value = f
  drawerChoice.value = ''
  drawerNote.value = ''
}
const drawerChoices = computed(() => {
  if (!drawer.value) return []
  // Volby dle typu — shodné hodnoty jako v modalu při ukládání.
  if (drawer.value.type === 'AML_STRUKTUROVANI') {
    return [
      { value: 'acknowledge', note_required: false, note_min: 0 },
      { value: 'not_suspicious', note_required: true, note_min: 50 },
      { value: 'escalate', note_required: false, note_min: 0 },
    ]
  }
  return [
    { value: 'acknowledge', note_required: false, note_min: 0 },
    { value: 'accept_risk', note_required: true, note_min: 10 },
    { value: 'resolved', note_required: false, note_min: 0 },
  ]
})
const drawerComplete = computed(() => {
  if (!drawerChoice.value) return false
  const c = drawerChoices.value.find(x => x.value === drawerChoice.value)
  if (c?.note_required && drawerNote.value.trim().length < (c.note_min || 10)) return false
  return true
})
async function submitDrawer() {
  if (!drawer.value || !drawerComplete.value) return
  try {
    const status = drawerChoice.value === 'resolved' ? 'resolved' : undefined
    const updated = await complianceApi.acknowledge(
      drawer.value.id, drawerChoice.value, drawerNote.value.trim() || undefined, status)
    const i = flags.value.findIndex(x => x.id === updated.id)
    if (i >= 0) flags.value[i] = updated
    drawer.value = updated
    toast.success(t('compliance.acknowledged_toast'))
    summary.value = await complianceApi.summary()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return [y + 1, y, y - 1, y - 2]
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-1">
      <h1 class="text-2xl font-semibold">{{ t('compliance.title') }}</h1>
    </div>
    <p class="text-sm text-neutral-500 mb-4">{{ t('compliance.subtitle') }}</p>

    <!-- Dlaždice (klikací filtry) -->
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <button @click="severityFilter = severityFilter === 'high' ? '' : 'high'; statusFilter = 'open'"
        class="cursor-pointer rounded-lg border p-3 text-left"
        :class="severityFilter === 'high' ? 'border-danger-500 bg-danger-50' : 'border-danger-500/40 bg-danger-50/50 hover:bg-danger-50'">
        <div class="text-2xl font-bold text-danger-600">{{ summary.open_high }}</div>
        <div class="text-xs text-neutral-600">{{ t('compliance.tile_high') }}</div>
      </button>
      <button @click="severityFilter = severityFilter === 'medium' ? '' : 'medium'; statusFilter = 'open'"
        class="cursor-pointer rounded-lg border p-3 text-left"
        :class="severityFilter === 'medium' ? 'border-warning-500 bg-warning-50' : 'border-warning-500/40 bg-warning-50/50 hover:bg-warning-50'">
        <div class="text-2xl font-bold text-warning-600">{{ summary.open_medium }}</div>
        <div class="text-xs text-neutral-600">{{ t('compliance.tile_medium') }}</div>
      </button>
      <div class="rounded-lg border border-primary-500/40 bg-primary-50/50 p-3">
        <div class="text-2xl font-bold text-primary-700">{{ summary.deadlines_30 }}</div>
        <div class="text-xs text-neutral-600">{{ t('compliance.tile_deadlines') }}</div>
      </div>
      <!-- záměrně šedá a neklikací — jen ukazuje, že se práce ukládá -->
      <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
        <div class="text-2xl font-bold text-neutral-500">{{ summary.handled_total }}</div>
        <div class="text-xs text-neutral-500">{{ t('compliance.tile_handled') }}</div>
      </div>
    </div>

    <!-- Filtry + pohled -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <select v-model.number="yearFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-model="severityFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="">{{ t('compliance.all_severities') }}</option>
        <option value="high">{{ t('compliance.severity_high') }}</option>
        <option value="medium">{{ t('compliance.severity_medium') }}</option>
        <option value="low">{{ t('compliance.severity_low') }}</option>
      </select>
      <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="unresolved">{{ t('compliance.status_unresolved') }}</option>
        <option value="open">{{ t('compliance.status_open') }}</option>
        <option value="acknowledged">{{ t('compliance.status_acknowledged') }}</option>
        <option value="">{{ t('compliance.status_all') }}</option>
      </select>
      <select v-model="typeFilter" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="">{{ t('compliance.all_types') }}</option>
        <option v-for="ty in types" :key="ty" :value="ty">{{ t(`compliance.type_${ty}`) }}</option>
      </select>
      <SegmentedControl
        :model-value="view"
        @update:model-value="v => view = v as 'projects' | 'documents'"
        :options="[
          { value: 'projects', label: t('compliance.view_projects') },
          { value: 'documents', label: t('compliance.view_documents') },
        ]"
      />
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- Prázdný stav (Dokument 7 §13) — nikdy negratulovat, říct co se hlídá -->
    <div v-else-if="flags.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-sm text-neutral-600">
      <p class="font-medium mb-2">{{ t('compliance.empty_title', { year: yearFilter }) }}</p>
      <p class="text-neutral-500 mb-1">{{ t('compliance.empty_checks_intro') }}</p>
      <ul class="list-disc pl-5 text-neutral-500 space-y-0.5">
        <li>{{ t('compliance.empty_check_cash') }}</li>
        <li>{{ t('compliance.empty_check_structuring') }}</li>
        <li>{{ t('compliance.empty_check_aml') }}</li>
        <li>{{ t('compliance.empty_check_register') }}</li>
      </ul>
      <p class="mt-3 text-xs text-neutral-400">{{ t('compliance.empty_handled', { n: summary?.handled_total ?? 0 }) }}</p>
    </div>

    <!-- ═══ Podle zakázek (výchozí) ═══ -->
    <div v-else-if="view === 'projects'" class="space-y-3">
      <div v-for="g in byProject" :key="g.key" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2.5 border-b border-neutral-100 flex items-center gap-2 flex-wrap">
          <span class="w-2.5 h-2.5 rounded-full" :class="severityDot(g.flags.reduce<'high' | 'medium' | 'low'>((m, f) => SEVERITY_ORDER[f.severity] < SEVERITY_ORDER[m] ? f.severity : m, 'low'))"></span>
          <RouterLink v-if="g.project_id" :to="`/projects/${g.project_id}`" class="font-semibold text-primary-700 hover:underline">{{ g.title }}</RouterLink>
          <span v-else class="font-semibold text-neutral-800">{{ g.title }}</span>
          <span v-if="g.vin" class="font-mono text-xs text-neutral-500">VIN {{ g.vin }}</span>
          <span class="ml-auto text-xs text-neutral-400">{{ g.flags.length }}×</span>
        </div>
        <div class="divide-y divide-neutral-50">
          <button v-for="f in g.flags" :key="f.id" @click="openDrawer(f)"
            class="w-full text-left flex items-center gap-3 px-4 py-2 hover:bg-neutral-50 transition cursor-pointer"
            :class="stripeClass(f)">
            <span class="w-2 h-2 rounded-full flex-shrink-0" :class="severityDot(f.severity)"
              :style="f.status !== 'open' ? 'opacity: 0.35' : ''"></span>
            <span class="flex-1 min-w-0 text-sm truncate">{{ f.message }}</span>
            <span v-if="amountOf(f)" class="font-mono text-xs text-neutral-600 whitespace-nowrap">{{ amountOf(f) }}</span>
            <span class="text-xs whitespace-nowrap" :class="f.status === 'open' ? 'text-danger-500' : 'text-neutral-400'">
              {{ statusLabel(f) }}
            </span>
            <span class="text-neutral-300">→</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ Podle dokladů ═══ -->
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="w-6 px-2 py-2"></th>
            <th class="px-3 py-2 text-left font-medium">{{ t('compliance.col_detected') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('compliance.col_counterparty') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('compliance.col_what') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('compliance.col_amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('compliance.col_status') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="f in flags" :key="f.id" @click="openDrawer(f)"
            class="cursor-pointer hover:bg-neutral-50 transition" :class="stripeClass(f)">
            <td class="px-2 py-2"><span class="block w-2.5 h-2.5 rounded-full" :class="severityDot(f.severity)"
              :style="f.status !== 'open' ? 'opacity: 0.35' : ''"></span></td>
            <td class="px-3 py-2 text-xs whitespace-nowrap">{{ formatDate(f.detected_at) }}</td>
            <td class="px-3 py-2 text-xs">{{ f.client_company_name || '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ f.message }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs whitespace-nowrap">{{ amountOf(f) || '' }}</td>
            <td class="px-3 py-2 text-xs whitespace-nowrap" :class="f.status === 'open' ? 'text-danger-500' : 'text-neutral-400'">
              {{ statusLabel(f) }}
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- ═══ Drawer detailu (Dokument 7 §9) ═══ -->
    <div v-if="drawer" class="fixed inset-0 z-[60]">
      <div class="absolute inset-0 bg-black/30" @click="drawer = null"></div>
      <div class="absolute right-0 top-0 h-full w-full max-w-lg bg-surface shadow-2xl overflow-y-auto">
        <div class="h-1.5" :class="severityDot(drawer.severity)"></div>
        <div class="p-5">
          <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ t(`compliance.type_${drawer.type}`) }}</h2>
            <button @click="drawer = null" class="cursor-pointer text-neutral-400 hover:text-neutral-600 text-xl leading-none">×</button>
          </div>
          <div class="text-xs mt-1" :class="drawer.status === 'open' ? 'text-danger-500' : 'text-neutral-500'">
            {{ statusLabel(drawer) }} · {{ formatDate(drawer.detected_at) }}
            <template v-if="drawer.detected_rule"> · {{ drawer.detected_rule }}</template>
          </div>

          <p class="text-sm text-neutral-700 mt-4 whitespace-pre-line">{{ drawer.message }}</p>

          <div v-if="drawer.legal_reference" class="mt-3 rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-xs text-neutral-600">
            <span class="font-medium">{{ t('compliance.legal_context') }}:</span> {{ drawer.legal_reference }}
          </div>

          <!-- Časová osa odbavení -->
          <div v-if="drawer.acknowledged_at" class="mt-3 text-xs text-neutral-600 border-l-2 border-neutral-200 pl-3">
            ⚑ {{ t(`compliance.choice_${drawer.acknowledgement_choice}`) }}
            · {{ drawer.acknowledged_by_name || '' }} · {{ formatDate(drawer.acknowledged_at) }}
            <div v-if="drawer.acknowledgement_note" class="italic mt-0.5">„{{ drawer.acknowledgement_note }}"</div>
          </div>

          <!-- Odkazy na doklady -->
          <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <RouterLink v-if="subjectRoute(drawer)" :to="subjectRoute(drawer)!"
              class="px-2 py-1 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50">
              {{ t('compliance.open_subject') }}
            </RouterLink>
            <RouterLink v-if="drawer.project_id" :to="`/projects/${drawer.project_id}`"
              class="px-2 py-1 rounded border border-neutral-300 text-neutral-600 hover:bg-neutral-50">
              {{ t('compliance.open_project') }}
            </RouterLink>
            <RouterLink v-if="drawer.client_id" :to="`/clients/${drawer.client_id}`"
              class="px-2 py-1 rounded border border-neutral-300 text-neutral-600 hover:bg-neutral-50">
              {{ drawer.client_company_name || t('compliance.open_client') }}
            </RouterLink>
          </div>

          <!-- Volby — texty shodné s modalem (sdílené i18n klíče) -->
          <div v-if="auth.canWrite && ['open', 'acknowledged'].includes(drawer.status)" class="mt-5 border-t border-neutral-100 pt-4">
            <div class="text-sm font-medium text-neutral-700 mb-2">{{ t('compliance.drawer_how') }}</div>
            <div class="space-y-2">
              <label v-for="c in drawerChoices" :key="c.value"
                class="flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition"
                :class="drawerChoice === c.value ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:border-neutral-300'">
                <input type="radio" name="drawer-choice" :value="c.value" v-model="drawerChoice"
                  class="mt-0.5 text-primary-600 border-neutral-300" />
                <span class="text-sm">
                  {{ t(`compliance.choice_${c.value}`) }}
                  <span v-if="c.note_required" class="block text-xs text-neutral-500">
                    {{ t('compliance.note_required_hint', { n: c.note_min || 10 }) }}
                  </span>
                </span>
              </label>
              <textarea v-if="drawerChoices.find(c => c.value === drawerChoice)?.note_required"
                v-model="drawerNote" rows="2" :placeholder="t('compliance.note_placeholder')"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
            </div>
            <p class="text-xs text-neutral-500 mt-2">{{ t('compliance.ack_footer') }}</p>
            <button @click="submitDrawer" :disabled="!drawerComplete"
              class="cursor-pointer mt-3 px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ t('compliance.ack_confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
