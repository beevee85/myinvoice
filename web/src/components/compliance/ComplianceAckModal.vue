<script setup lang="ts">
// FORK 0925 — modal vynuceného rozhodnutí (Dokument 5 §4, texty Dokument 6):
// 1. co bylo zjištěno (s čísly), 2. právní odkaz, 3. volby (PRVNÍ je bezriziková,
// ŽÁDNÁ není předvybraná), 4. věta o zápisu. Esc modal NEZAVÍRÁ, „příště
// nezobrazovat" neexistuje. Volby jsou znak po znaku sdílené s drawerem
// v /compliance (týž i18n zdroj — akceptace Dokumentu 7 §15.6).
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ComplianceCheck, ComplianceAck } from '@/api/compliance'

const props = defineProps<{ checks: ComplianceCheck[] }>()
const emit = defineEmits<{
  (e: 'confirm', ack: ComplianceAck): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const selection = ref<Record<string, string>>({})
const notes = ref<Record<string, string>>({})

function noteRule(check: ComplianceCheck, value: string): { required: boolean; min: number } {
  const c = check.choices.find(x => x.value === value)
  return { required: !!c?.note_required, min: c?.note_min ?? 10 }
}

const complete = computed(() => props.checks.every(check => {
  const choice = selection.value[check.type]
  if (!choice) return false
  const rule = noteRule(check, choice)
  if (rule.required && (notes.value[check.type]?.trim().length ?? 0) < rule.min) return false
  return true
}))

function confirm() {
  if (!complete.value) return
  const ack: ComplianceAck = {}
  for (const check of props.checks) {
    ack[check.type] = {
      choice: selection.value[check.type],
      note: notes.value[check.type]?.trim() || undefined,
    }
  }
  emit('confirm', ack)
}
</script>

<template>
  <!-- záměrně bez @click.self a bez Esc — zavřít lze jen tlačítkem Zrušit -->
  <div class="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-xl max-w-xl w-full p-5 max-h-[92vh] overflow-y-auto">
      <div v-for="check in checks" :key="check.type" class="mb-5">
        <h3 class="text-lg font-semibold flex items-center gap-2">
          <span class="text-warning-600">⚑</span> {{ check.title }}
        </h3>
        <p class="text-sm text-neutral-700 mt-2 whitespace-pre-line">{{ check.message }}</p>
        <p v-if="check.legal_reference" class="text-xs text-neutral-500 mt-1.5">{{ check.legal_reference }}</p>

        <div class="mt-3 space-y-2">
          <label v-for="c in check.choices" :key="c.value"
            class="flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition"
            :class="selection[check.type] === c.value
              ? 'border-primary-500 bg-primary-50'
              : 'border-neutral-200 hover:border-neutral-300'">
            <input type="radio" :name="`ack-${check.type}`" :value="c.value"
              v-model="selection[check.type]"
              class="mt-0.5 text-primary-600 border-neutral-300" />
            <span class="text-sm">
              {{ t(`compliance.choice_${c.value}`) }}
              <span v-if="c.note_required" class="block text-xs text-neutral-500">
                {{ t('compliance.note_required_hint', { n: c.note_min ?? 10 }) }}
              </span>
            </span>
          </label>
          <textarea
            v-if="selection[check.type] && noteRule(check, selection[check.type]).required"
            v-model="notes[check.type]"
            rows="2"
            :placeholder="t('compliance.note_placeholder')"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"
          ></textarea>
        </div>
      </div>

      <p class="text-xs text-neutral-500 border-t border-neutral-100 pt-3">
        {{ t('compliance.ack_footer') }}
      </p>
      <div class="flex justify-end gap-2 mt-3">
        <button type="button" @click="emit('cancel')"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="button" @click="confirm" :disabled="!complete"
          class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ t('compliance.ack_confirm') }}
        </button>
      </div>
    </div>
  </div>
</template>
