<script setup lang="ts">
// FORK 0905 — potvrzovací dialog mazání dokladů (koš / trvalé smazání / vysypání koše).
//
// Zásady (iDoklad/Vyfakturuj):
//  * žádný native confirm() (v aplikaci už jednou zamrzl renderer),
//  * povinný důvod smazání (min. 10 znaků) — jde do audit logu i snapshotu,
//  * u trvalého smazání jednoho dokladu se navíc opisuje číslo dokladu,
//  * destruktivní tlačítko je SEKUNDÁRNÍ červené a stojí na opačné straně,
//    než je v aplikaci obvyklé „Potvrdit" — primární pozice patří „Zrušit",
//  * blokované doklady se vypíšou s důvodem; hromadná operace je přeskočí.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import type { TrashBlocker } from '@/api/invoices'

export interface TrashModalDoc {
  id: number
  varsymbol: string | null
  party?: string | null
  totalFormatted?: string | null
  taxDate?: string | null
  statusLabel?: string | null
  blockers: TrashBlocker[]
}

const props = withDefaults(defineProps<{
  mode: 'trash' | 'force' | 'empty'
  docs: TrashModalDoc[]
  isAdmin: boolean
  busy?: boolean
}>(), { busy: false })

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'confirm', payload: { reason: string; override: boolean; confirmNumber: string }): void
}>()

const { t } = useI18n()

const reason = ref('')
const override = ref(false)
const confirmNumber = ref('')

const single = computed(() => props.docs.length === 1 ? props.docs[0] : null)
const isDestructive = computed(() => props.mode !== 'trash')

/** Nepřebitelné blokace (DPH období, vazby) — doklad neprojde nikdy. */
function isHardBlocked(d: TrashModalDoc): boolean {
  return d.blockers.some(b => !b.overridable)
}
/** Blokace, které admin smí přebít checkboxem (odesláno / exportováno). */
function isOverridableBlocked(d: TrashModalDoc): boolean {
  return !isHardBlocked(d) && d.blockers.length > 0
}
function isEligible(d: TrashModalDoc): boolean {
  if (isHardBlocked(d)) return false
  if (d.blockers.length === 0) return true
  return props.isAdmin && override.value
}

const eligibleDocs = computed(() => props.docs.filter(isEligible))
const hasOverridable = computed(() => props.docs.some(isOverridableBlocked))

/** Opsání čísla dokladu — jen trvalé smazání jednoho dokladu s číslem. */
const needsConfirmNumber = computed(() =>
  props.mode === 'force' && single.value !== null && !!single.value.varsymbol)
const confirmNumberOk = computed(() =>
  !needsConfirmNumber.value || confirmNumber.value.trim() === single.value?.varsymbol)

const reasonOk = computed(() => reason.value.trim().length >= 10)
const canConfirm = computed(() =>
  reasonOk.value && confirmNumberOk.value && eligibleDocs.value.length > 0 && !props.busy)

const title = computed(() => ({
  trash: t('doc_trash.modal_title_trash'),
  force: t('doc_trash.modal_title_force'),
  empty: t('doc_trash.modal_title_empty'),
}[props.mode]))

const confirmLabel = computed(() => ({
  trash: t('doc_trash.confirm_trash'),
  force: t('doc_trash.confirm_force'),
  empty: t('doc_trash.confirm_empty'),
}[props.mode]))

function submit() {
  if (!canConfirm.value) return
  emit('confirm', {
    reason: reason.value.trim(),
    override: override.value && props.isAdmin,
    confirmNumber: confirmNumber.value.trim(),
  })
}
</script>

<template>
  <Modal :title="title" width-class="max-w-xl" @close="emit('close')">
    <!-- Varovný panel: červený u nevratných operací, jantarový u koše -->
    <div
      :class="['rounded-lg border px-4 py-3 text-sm',
               isDestructive
                 ? 'border-danger-300 bg-danger-50 text-danger-700'
                 : 'border-warning-300 bg-warning-50 text-warning-700']"
    >
      <p class="font-semibold">
        {{ isDestructive ? t('doc_trash.warn_irreversible_title') : t('doc_trash.warn_trash_title') }}
      </p>
      <p class="mt-1">
        {{ isDestructive ? t('doc_trash.warn_irreversible_body') : t('doc_trash.warn_trash_body') }}
      </p>
    </div>

    <!-- Identifikace jednoho dokladu -->
    <dl v-if="single" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
      <dt class="text-neutral-500">{{ t('doc_trash.field_number') }}</dt>
      <dd class="font-mono font-medium text-neutral-900">{{ single.varsymbol || t('doc_trash.no_number') }}</dd>
      <template v-if="single.party">
        <dt class="text-neutral-500">{{ t('doc_trash.field_party') }}</dt>
        <dd class="text-neutral-900">{{ single.party }}</dd>
      </template>
      <template v-if="single.totalFormatted">
        <dt class="text-neutral-500">{{ t('doc_trash.field_total') }}</dt>
        <dd class="font-mono text-neutral-900">{{ single.totalFormatted }}</dd>
      </template>
      <template v-if="single.taxDate">
        <dt class="text-neutral-500">{{ t('doc_trash.field_tax_date') }}</dt>
        <dd class="text-neutral-900">{{ single.taxDate }}</dd>
      </template>
      <template v-if="single.statusLabel">
        <dt class="text-neutral-500">{{ t('doc_trash.field_status') }}</dt>
        <dd class="text-neutral-900">{{ single.statusLabel }}</dd>
      </template>
    </dl>

    <!-- Hromadný výpis: čísla dotčených dokladů + případné blokace -->
    <div v-else class="mt-4">
      <p class="text-sm text-neutral-700">
        {{ t('doc_trash.bulk_intro', { n: docs.length, eligible: eligibleDocs.length }) }}
      </p>
      <ul class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-neutral-200 divide-y divide-neutral-100 text-sm">
        <li v-for="d in docs" :key="d.id" class="px-3 py-1.5 flex items-start gap-2">
          <span class="font-mono shrink-0" :class="isEligible(d) ? 'text-neutral-900' : 'text-neutral-400 line-through'">
            {{ d.varsymbol || `#${d.id}` }}
          </span>
          <span v-if="d.blockers.length" class="text-xs" :class="isHardBlocked(d) ? 'text-danger-600' : 'text-warning-600'">
            {{ d.blockers.map(b => b.message).join(' ') }}
            <template v-if="!isEligible(d)">{{ ' — ' + t('doc_trash.will_skip') }}</template>
          </span>
        </li>
      </ul>
    </div>

    <!-- Blokace jednoho dokladu -->
    <div v-if="single && single.blockers.length" class="mt-3 space-y-1.5">
      <p
        v-for="(b, i) in single.blockers" :key="i"
        class="rounded-md px-3 py-2 text-sm"
        :class="b.overridable ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-700'"
      >
        {{ b.message }}
        <span v-if="!b.overridable" class="font-medium"> {{ t('doc_trash.blocker_no_override') }}</span>
      </p>
    </div>

    <!-- „Vím, co dělám" — jen admin, jen přebitelné blokace (odesláno / exportováno) -->
    <label
      v-if="hasOverridable && isAdmin"
      class="mt-3 flex items-start gap-2 text-sm text-neutral-800 cursor-pointer"
    >
      <input v-model="override" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-danger-600" />
      <span>{{ t('doc_trash.override_ack') }}</span>
    </label>

    <!-- Povinný důvod -->
    <div class="mt-4">
      <label class="block text-sm font-medium text-neutral-700">{{ t('doc_trash.reason_label') }}</label>
      <textarea
        v-model="reason"
        rows="2"
        :placeholder="t('doc_trash.reason_placeholder')"
        class="mt-1 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none"
      ></textarea>
      <p class="mt-0.5 text-xs" :class="reasonOk ? 'text-neutral-400' : 'text-danger-500'">
        {{ t('doc_trash.reason_hint') }}
      </p>
    </div>

    <!-- Opsání čísla dokladu (jen hard delete jednoho dokladu) -->
    <div v-if="needsConfirmNumber" class="mt-3">
      <label class="block text-sm font-medium text-neutral-700">
        {{ t('doc_trash.confirm_number_label', { varsymbol: single?.varsymbol }) }}
      </label>
      <input
        v-model="confirmNumber"
        type="text"
        autocomplete="off"
        spellcheck="false"
        :placeholder="single?.varsymbol ?? ''"
        class="mt-1 w-full rounded-md border px-3 py-2 text-sm font-mono focus:outline-none"
        :class="confirmNumber && !confirmNumberOk ? 'border-danger-400 focus:border-danger-500' : 'border-neutral-300 focus:border-primary-500'"
      />
    </div>

    <template #footer>
      <!-- Destruktivní tlačítko vlevo (sekundární červené); primární pozice vpravo patří Zrušit -->
      <button
        type="button"
        class="mr-auto inline-flex items-center rounded-full border px-4 py-2 text-sm font-medium transition-colors
               border-danger-300 text-danger-600 hover:bg-danger-50
               disabled:opacity-50 disabled:pointer-events-none"
        :disabled="!canConfirm"
        @click="submit"
      >
        <svg v-if="busy" class="mr-2 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        {{ confirmLabel }}
      </button>
      <button
        type="button"
        class="inline-flex items-center rounded-full bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        @click="emit('close')"
      >
        {{ t('common.cancel') }}
      </button>
    </template>
  </Modal>
</template>
