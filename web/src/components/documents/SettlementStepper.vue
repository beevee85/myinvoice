<script setup lang="ts">
// FORK 0922 — D1: vodorovný stepper řetězce záloha → DD → konečná faktura.
// Kroky = členové vyúčtovací skupiny dokladu; aktuální doklad zvýrazněný,
// každý krok klikatelný (datum + částka + stav úhrady).
import { ref, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { settlementGroupsApi, type SettlementDirection, type SettlementChainStep } from '@/api/settlementGroups'
import { formatMoney, formatDate } from '@/composables/useFormat'

const props = defineProps<{
  direction: SettlementDirection
  documentId: number
}>()

const { t } = useI18n()
const router = useRouter()
const steps = ref<SettlementChainStep[]>([])

async function load() {
  try {
    steps.value = await settlementGroupsApi.chain(props.direction, props.documentId)
  } catch {
    steps.value = [] // stepper je doplněk — chyba načtení nesmí shodit detail
  }
}
onMounted(load)
watch(() => props.documentId, load)

const visible = computed(() => steps.value.length >= 2)

function stepLabel(s: SettlementChainStep): string {
  const key = props.direction === 'purchase'
    ? `purchase_invoice.document_kind.${s.document_kind}`
    : `type.${s.document_kind}`
  return t(key) as string
}

function isPaid(s: SettlementChainStep): boolean {
  return s.status === 'paid' || !!s.paid_at
}

function open(s: SettlementChainStep) {
  if (s.id === props.documentId) return
  router.push(`${props.direction === 'purchase' ? '/purchase-invoices' : '/invoices'}/${s.id}`)
}
</script>

<template>
  <div v-if="visible" class="bg-surface border border-neutral-200 rounded-lg px-4 py-3 shadow-sm overflow-x-auto">
    <div class="text-xs font-medium text-neutral-500 mb-2">{{ t('doc_relations.stepper_title') }}</div>
    <div class="flex items-stretch gap-0 min-w-max">
      <template v-for="(s, i) in steps" :key="s.id">
        <button
          type="button"
          @click="open(s)"
          class="text-left rounded-lg border px-3 py-2 min-w-36 transition"
          :class="[
            s.id === documentId
              ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500/40'
              : 'border-neutral-200 hover:border-primary-300 hover:bg-neutral-50 cursor-pointer',
            s.settlement_role === 'advance' ? 'border-dashed' : '',
          ]"
        >
          <div class="text-[11px] uppercase tracking-wide"
            :class="s.settlement_role === 'final' ? 'text-primary-700 font-semibold' : 'text-neutral-500'">
            {{ stepLabel(s) }}
          </div>
          <div class="font-mono text-xs mt-0.5" :class="s.settlement_role === 'final' ? 'font-semibold' : ''">
            {{ s.varsymbol || s.vendor_invoice_number || `#${s.id}` }}
          </div>
          <div class="text-[11px] text-neutral-500 mt-0.5">{{ formatDate(s.issue_date) }}</div>
          <div class="flex items-center gap-1 text-xs font-mono mt-0.5">
            {{ formatMoney(s.total_with_vat + (s.rounding || 0), s.currency) }}
            <span v-if="isPaid(s)" class="text-success-600" :title="t('doc_relations.step_paid')">✓</span>
            <span v-else-if="s.status === 'cancelled'" class="text-danger-500">✕</span>
          </div>
        </button>
        <div v-if="i < steps.length - 1" class="flex items-center px-1 text-neutral-400">→</div>
      </template>
    </div>
  </div>
</template>
