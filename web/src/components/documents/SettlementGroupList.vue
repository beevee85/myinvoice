<script setup lang="ts">
// FORK 0922 — režim seznamu „Podle vyúčtování" (Dokument 1, C2/C3/C5/C6).
// Hlavní řádek = konečná faktura s CELKOVOU hodnotou plnění (nikdy nula — nula
// patří jen do sloupce „K úhradě"); pod ním odsazené související doklady.
// Skupina bez konečné faktury = Otevřeno, řadí se dle nejnovějšího dokladu.
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  settlementGroupsApi,
  type SettlementDirection, type SettlementGroup, type SettlementMember,
} from '@/api/settlementGroups'
import { formatMoney, formatDate } from '@/composables/useFormat'
import Badge from '@/components/ui/Badge.vue'

const props = defineProps<{ direction: SettlementDirection }>()

const { t } = useI18n()
const router = useRouter()

const loading = ref(true)
const error = ref('')
const groups = ref<SettlementGroup[]>([])
const standalone = ref<SettlementMember[]>([])
const collapsed = ref<Set<number>>(new Set())

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await settlementGroupsApi.list(props.direction)
    groups.value = res.groups
    standalone.value = res.standalone
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || (t('common.error') as string)
  } finally {
    loading.value = false
  }
}
onMounted(load)

type Entry = { kind: 'group'; group: SettlementGroup; date: string } | { kind: 'doc'; doc: SettlementMember; date: string }

// C3: skupina dle data konečné faktury (bez ní dle nejnovějšího členu),
// samostatné doklady dle vlastního data; vše sestupně proloženě.
const entries = computed<Entry[]>(() => {
  const out: Entry[] = []
  for (const g of groups.value) out.push({ kind: 'group', group: g, date: g.sort_date ?? '' })
  for (const d of standalone.value) out.push({ kind: 'doc', doc: d, date: d.issue_date })
  return out.sort((a, b) => b.date.localeCompare(a.date))
})

function toggle(id: number) {
  const s = new Set(collapsed.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  collapsed.value = s
}

function open(doc: { id: number }) {
  router.push(`${props.direction === 'purchase' ? '/purchase-invoices' : '/invoices'}/${doc.id}`)
}

function kindLabel(m: SettlementMember): string {
  const key = props.direction === 'purchase'
    ? `purchase_invoice.document_kind.${m.document_kind}`
    : `type.${m.document_kind}`
  return t(key) as string
}

function docNumber(m: SettlementMember): string {
  return m.varsymbol || m.vendor_invoice_number || `#${m.id}`
}

// C5: vizuální hierarchie typů — konečná tučně, záloha světlejší s přerušovaným
// levým okrajem (není daňový doklad), dobropis červený akcent.
function rowClass(m: SettlementMember): string {
  if (m.status === 'cancelled') return 'opacity-50'
  switch (m.settlement_role ?? m.document_kind) {
    case 'advance': case 'proforma':
      return 'text-neutral-500 border-l-2 border-dashed border-neutral-300'
    case 'credit_note':
      return 'border-l-2 border-danger-500/60 text-danger-700'
    default:
      return ''
  }
}

// Ikony typů (C5) — jednoduché SVG path dle typu dokladu.
const ICONS: Record<string, string> = {
  final:    'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  advance:  'M2.25 8.25h19.5M2.25 9v6.75A2.25 2.25 0 0 0 4.5 18h15a2.25 2.25 0 0 0 2.25-2.25V9A2.25 2.25 0 0 0 19.5 6.75h-15A2.25 2.25 0 0 0 2.25 9z',
  advance_tax_document: 'M9 14l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  credit_note: 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 0 1 0 12h-3',
}
function iconFor(m: SettlementMember): string {
  return ICONS[m.settlement_role ?? 'final'] ?? ICONS.final
}

function paidLabel(m: SettlementMember): string | null {
  if (m.status === 'paid' || m.paid_at) {
    return m.paid_at ? (t('doc_relations.member_paid_at', { date: formatDate(m.paid_at) }) as string)
                     : (t('doc_relations.member_paid') as string)
  }
  return null
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
  <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

  <div v-else class="space-y-3">
    <template v-for="e in entries" :key="e.kind === 'group' ? `g-${e.group.id}` : `d-${e.doc.id}`">
      <!-- ═══ Skupina (obchodní případ) ═══ -->
      <div v-if="e.kind === 'group'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Hlavní řádek: konečná faktura / Otevřeno -->
        <div
          class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-neutral-50 transition"
          @click="e.group.final_document_id ? open({ id: e.group.final_document_id }) : toggle(e.group.id)"
        >
          <button type="button" @click.stop="toggle(e.group.id)"
            class="cursor-pointer w-6 h-6 flex items-center justify-center rounded hover:bg-neutral-100 text-neutral-500"
            :aria-label="t('doc_relations.group_toggle')">
            <svg class="w-4 h-4 transition-transform" :class="collapsed.has(e.group.id) ? '-rotate-90' : ''"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>

          <svg class="w-4 h-4 text-primary-700 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.final"/>
          </svg>

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-mono text-[13px] font-bold text-neutral-900">
                {{ e.group.final_varsymbol || t('doc_relations.group_open_label') }}
              </span>
              <Badge v-if="e.group.final_document_id" color="primary" size="sm">
                {{ direction === 'purchase' ? t('purchase_invoice.document_kind.invoice') : t('type.invoice') }}
              </Badge>
              <span v-if="e.group.external_ref" class="text-xs text-neutral-500 font-mono">{{ e.group.external_ref }}</span>
            </div>
            <div class="text-sm text-neutral-600 truncate">
              {{ e.group.counterparty_name }}
              <span v-if="e.group.label && !e.group.label.startsWith('VIN ')" class="text-neutral-400"> · {{ e.group.label }}</span>
            </div>
          </div>

          <div class="text-right whitespace-nowrap">
            <!-- C2: hlavní řádek nese CELKOVOU hodnotu plnění, ne nulu -->
            <div class="font-mono font-bold text-neutral-900">{{ formatMoney(e.group.total_amount, e.group.currency) }}</div>
            <div v-if="e.group.final_amount_to_pay !== null" class="text-xs text-neutral-500 font-mono">
              {{ t('doc_relations.group_to_pay') }} {{ formatMoney(e.group.final_amount_to_pay, e.group.currency) }}
            </div>
          </div>

          <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
            :class="{
              'bg-success-50 text-success-600 border border-success-500/40': e.group.status === 'settled',
              'bg-primary-50 text-primary-700 border border-primary-500/40': e.group.status === 'open',
              'bg-danger-50 text-danger-500 border border-danger-500/40': e.group.status === 'mismatch',
            }">
            {{ e.group.status === 'settled' ? '✓ ' + t('doc_relations.group_settled')
              : e.group.status === 'mismatch' ? '⚠ ' + t('doc_relations.group_mismatch')
              : t('doc_relations.group_open') }}
          </span>
        </div>

        <!-- Podřízené doklady -->
        <div v-if="!collapsed.has(e.group.id)" class="border-t border-neutral-100 divide-y divide-neutral-50">
          <div
            v-for="m in e.group.members.filter(x => x.id !== e.group.final_document_id)"
            :key="m.id"
            @click="open(m)"
            class="flex items-center gap-3 pl-14 pr-4 py-2 cursor-pointer hover:bg-neutral-50 transition text-sm"
            :class="rowClass(m)"
          >
            <svg class="w-3.5 h-3.5 flex-shrink-0" :class="m.settlement_role === 'advance' ? 'text-neutral-400' : 'text-neutral-500'"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="iconFor(m)"/>
            </svg>
            <span class="font-mono text-xs w-32 truncate">{{ docNumber(m) }}</span>
            <span class="flex-1 min-w-0 truncate text-xs">
              {{ kindLabel(m) }}
              <span v-if="m.settlement_role === 'advance'" class="text-neutral-400"> · {{ t('doc_relations.non_tax_badge') }}</span>
              <span v-else-if="m.settlement_role === 'advance_tax_document' && m.tax_date" class="text-neutral-400">
                · {{ t('doc_relations.member_vat_period', { period: m.tax_date.slice(0, 7) }) }}</span>
            </span>
            <span v-if="paidLabel(m)" class="text-xs text-success-600 whitespace-nowrap">{{ paidLabel(m) }}</span>
            <span class="font-mono text-xs whitespace-nowrap w-28 text-right">{{ formatMoney(m.total_with_vat + (m.rounding || 0), m.currency) }}</span>
          </div>
        </div>
      </div>

      <!-- ═══ Samostatný doklad ═══ -->
      <div v-else
        @click="open(e.doc)"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-2.5 flex items-center gap-3 cursor-pointer hover:bg-neutral-50 transition text-sm"
        :class="rowClass(e.doc)"
      >
        <svg class="w-4 h-4 text-neutral-400 flex-shrink-0 ml-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="iconFor(e.doc)"/>
        </svg>
        <span class="font-mono text-xs w-32 truncate">{{ docNumber(e.doc) }}</span>
        <span class="flex-1 min-w-0 truncate">
          <span class="text-neutral-900">{{ e.doc.counterparty_name }}</span>
          <span class="text-neutral-400 text-xs"> · {{ kindLabel(e.doc) }}</span>
        </span>
        <span class="text-xs text-neutral-500 whitespace-nowrap">{{ formatDate(e.doc.issue_date) }}</span>
        <!-- C6: celkem s DPH i K úhradě -->
        <span class="font-mono text-xs whitespace-nowrap w-28 text-right">{{ formatMoney(e.doc.total_with_vat + (e.doc.rounding || 0), e.doc.currency) }}</span>
        <span class="font-mono text-xs whitespace-nowrap w-28 text-right text-neutral-500"
          :title="t('doc_relations.group_to_pay')">{{ formatMoney(e.doc.amount_to_pay + (e.doc.rounding || 0), e.doc.currency) }}</span>
      </div>
    </template>

    <div v-if="entries.length === 0" class="text-center text-neutral-500 py-12 text-sm">
      {{ t('doc_relations.group_empty') }}
    </div>
  </div>
</template>
