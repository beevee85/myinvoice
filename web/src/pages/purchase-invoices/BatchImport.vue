<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  batchImportApi,
  type BatchDetail,
  type Finding,
  type FindingSeverity,
} from '@/api/batchImport'

/**
 * FORK (beevee85) — review dávkového importu.
 *
 * Stránka NIC NEZAKLÁDÁ. Ukazuje, co validace našla, a nechává rozhodnutí na
 * člověku — všechno končí jako draft ke schválení. Proto tu není tlačítko
 * „přijmout vše": u dokladu, který má FAIL, by se kliklo dřív, než by si ho
 * kdokoli přečetl.
 *
 * Obsah dokladů se sem nedostane. Server vrací nálezy, ne `raw_json`.
 */

const { t } = useI18n()
const route = useRoute()

const batchId = Number(route.params.id ?? 0)
const detail = ref<BatchDetail | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)

const resultsJson = ref('')
const token = ref('')
const submitting = ref(false)
const submitError = ref<string | null>(null)
const submitFindings = ref<Finding[] | null>(null)

async function load() {
  if (!Number.isInteger(batchId) || batchId <= 0) {
    loadError.value = t('batch_import.invalid_id')
    return
  }
  loading.value = true
  loadError.value = null
  try {
    detail.value = await batchImportApi.get(batchId)
  } catch (e: any) {
    loadError.value = e?.response?.data?.message ?? t('batch_import.load_failed')
  } finally {
    loading.value = false
  }
}

async function submit() {
  submitting.value = true
  submitError.value = null
  submitFindings.value = null
  try {
    const r = await batchImportApi.submitResults(batchId, token.value.trim(), resultsJson.value)
    submitFindings.value = r.findings
    await load()
  } catch (e: any) {
    submitError.value = e?.response?.data?.message ?? t('batch_import.submit_failed')
  } finally {
    submitting.value = false
  }
}

/** Soubor podle id, ať se u nálezu pozná, o který doklad jde. */
const fileById = computed(() => {
  const m = new Map<number, string>()
  for (const f of detail.value?.files ?? []) m.set(f.id, f.original_name)
  return m
})

const counts = computed(() => {
  const c = { fail: 0, warn: 0, info: 0 }
  for (const r of detail.value?.results ?? []) {
    for (const f of r.findings) c[f.severity]++
  }
  return c
})

/** Doklad s FAILem se ukáže první — je to to, co uživatel musí vyřešit. */
const sortedResults = computed(() => {
  const rank = (s: string) => (s === 'rejected' ? 0 : s === 'pending' ? 1 : 2)
  return [...(detail.value?.results ?? [])].sort((a, b) => rank(a.status) - rank(b.status))
})

function severityClass(s: FindingSeverity): string {
  return s === 'fail'
    ? 'text-red-700 dark:text-red-400'
    : s === 'warn'
      ? 'text-amber-700 dark:text-amber-400'
      : 'text-slate-600 dark:text-slate-400'
}

function statusBadgeClass(s: string): string {
  if (s === 'rejected' || s === 'failed') return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
  if (s === 'validated' || s === 'done') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} kB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

load()
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold">{{ t('batch_import.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {{ t('batch_import.subtitle') }}
        </p>
      </div>
      <span
        v-if="detail"
        class="rounded px-2 py-1 text-xs font-medium"
        :class="statusBadgeClass(detail.batch.status)"
      >{{ detail.batch.status }}</span>
    </header>

    <p v-if="loading" class="text-sm text-slate-500">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>

    <template v-else-if="detail">
      <!-- Přehled -->
      <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <div>
            <dt class="text-slate-500">{{ t('batch_import.files') }}</dt>
            <dd class="font-medium">{{ detail.batch.file_count }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">{{ t('batch_import.size') }}</dt>
            <dd class="font-medium">{{ formatBytes(detail.batch.total_bytes) }}</dd>
          </div>
          <div>
            <dt class="text-slate-500">{{ t('batch_import.problems') }}</dt>
            <dd class="font-medium">
              <span v-if="counts.fail" class="text-red-700 dark:text-red-400">{{ counts.fail }}</span>
              <span v-else>0</span>
              <span v-if="counts.warn" class="ml-2 text-amber-700 dark:text-amber-400">
                +{{ counts.warn }} {{ t('batch_import.warnings') }}
              </span>
            </dd>
          </div>
          <div>
            <dt class="text-slate-500">{{ t('batch_import.created') }}</dt>
            <dd class="font-medium">{{ detail.batch.created_at }}</dd>
          </div>
        </dl>

        <p v-if="detail.batch.error_message" class="mt-3 text-sm text-red-600">
          {{ detail.batch.error_message }}
        </p>
      </section>

      <!-- Odeslání výsledků -->
      <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
        <h2 class="mb-3 font-medium">{{ t('batch_import.submit_title') }}</h2>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
          {{ t('batch_import.submit_hint') }}
        </p>

        <label class="mb-1 block text-sm font-medium" for="bi-token">
          {{ t('batch_import.token') }}
        </label>
        <input
          id="bi-token"
          v-model="token"
          type="password"
          autocomplete="off"
          class="mb-3 w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
        >

        <label class="mb-1 block text-sm font-medium" for="bi-json">results.json</label>
        <textarea
          id="bi-json"
          v-model="resultsJson"
          rows="8"
          spellcheck="false"
          class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-600 dark:bg-slate-800"
        />

        <button
          class="mt-3 rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900"
          :disabled="submitting || !token.trim() || !resultsJson.trim()"
          @click="submit"
        >
          {{ submitting ? t('common.saving') : t('batch_import.submit') }}
        </button>

        <p v-if="submitError" class="mt-3 text-sm text-red-600">{{ submitError }}</p>
      </section>

      <!-- Nálezy po dokladech -->
      <section v-if="sortedResults.length" class="space-y-3">
        <h2 class="font-medium">{{ t('batch_import.results_title') }}</h2>

        <article
          v-for="r in sortedResults"
          :key="r.id"
          class="rounded-lg border border-slate-200 p-4 dark:border-slate-700"
        >
          <header class="mb-2 flex items-center justify-between gap-3">
            <span class="truncate text-sm font-medium">
              {{ fileById.get(r.purchase_import_batch_file_id) ?? '—' }}
            </span>
            <span class="shrink-0 rounded px-2 py-0.5 text-xs" :class="statusBadgeClass(r.status)">
              {{ r.status }}
            </span>
          </header>

          <ul v-if="r.findings.length" class="space-y-1 text-sm">
            <li v-for="(f, i) in r.findings" :key="i" :class="severityClass(f.severity)">
              <span class="font-mono text-xs">{{ f.rule }}</span>
              <span class="mx-1">·</span>
              <span>{{ f.message }}</span>
              <span class="ml-1 font-mono text-xs opacity-60">{{ f.pointer }}</span>
            </li>
          </ul>
          <p v-else class="text-sm text-slate-500">{{ t('batch_import.no_findings') }}</p>

          <p v-if="r.raw_purged_at" class="mt-2 text-xs text-slate-500">
            {{ t('batch_import.raw_purged') }}
          </p>
        </article>
      </section>

      <p v-else class="text-sm text-slate-500">{{ t('batch_import.no_results_yet') }}</p>
    </template>
  </div>
</template>
