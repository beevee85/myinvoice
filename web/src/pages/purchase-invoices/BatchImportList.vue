<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  batchImportApi,
  type BatchListItem,
  type CreateBatchResponse,
} from '@/api/batchImport'

/**
 * FORK (beevee85) — seznam dávek + založení nové.
 *
 * TOKEN SE UKAZUJE JEDNOU. Server ho podruhé nevydá (v DB je jen hash),
 * takže modal nejde zavřít omylem — vyžaduje explicitní potvrzení, že si
 * uživatel token uložil. Ztracený token = dávka se musí založit znovu;
 * to je vlastnost druhého faktoru, ne chyba UI.
 */

const { t } = useI18n()
const router = useRouter()

const batches = ref<BatchListItem[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)

const picked = ref<File[]>([])
const uploading = ref(false)
const uploadPct = ref(0)
const uploadError = ref<string | null>(null)

/** Výsledek založení — dokud ho uživatel nepotvrdí, modal nejde zavřít. */
const created = ref<CreateBatchResponse | null>(null)
const tokenCopied = ref(false)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    batches.value = await batchImportApi.list()
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message ?? t('batch_import.load_failed')
  } finally {
    loading.value = false
  }
}

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  picked.value = Array.from(input.files ?? [])
  input.value = ''
  uploadError.value = null
}

async function createBatch() {
  if (picked.value.length === 0) return
  uploading.value = true
  uploadPct.value = 0
  uploadError.value = null
  try {
    created.value = await batchImportApi.create(picked.value, (pct) => { uploadPct.value = pct })
    tokenCopied.value = false
    picked.value = []
    await load()
  } catch (e: any) {
    uploadError.value = e?.response?.data?.error?.message ?? t('batch_import.create_failed')
  } finally {
    uploading.value = false
  }
}

async function copyToken() {
  if (!created.value) return
  try {
    await navigator.clipboard.writeText(created.value.token)
    tokenCopied.value = true
  } catch {
    // Clipboard API nemusí být dostupné (http) — token zůstává viditelný k ručnímu opsání.
  }
}

function ackToken() {
  const id = created.value?.batch_id
  created.value = null
  if (id) void router.push({ name: 'purchase-invoice-batch-import', params: { id } })
}

function statusBadgeClass(s: string): string {
  if (s === 'failed' || s === 'cancelled') return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
  if (s === 'done') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
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
    <header>
      <h1 class="text-xl font-semibold">{{ t('batch_import.list_title') }}</h1>
      <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {{ t('batch_import.list_subtitle') }}
      </p>
    </header>

    <!-- Nová dávka -->
    <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
      <h2 class="mb-3 font-medium">{{ t('batch_import.new_batch') }}</h2>

      <label
        class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 px-4 py-8 text-sm text-slate-500 hover:border-slate-400 dark:border-slate-600 dark:hover:border-slate-500"
      >
        <input type="file" class="hidden" accept="application/pdf,.pdf" multiple @change="onPick">
        <span v-if="picked.length === 0">{{ t('batch_import.pick_hint') }}</span>
        <span v-else class="font-medium text-slate-700 dark:text-slate-300">
          {{ t('batch_import.picked_count', { count: picked.length }) }}
        </span>
      </label>

      <ul v-if="picked.length" class="mt-3 max-h-40 space-y-1 overflow-y-auto text-xs text-slate-600 dark:text-slate-400">
        <li v-for="(f, i) in picked" :key="i" class="flex justify-between gap-3">
          <span class="truncate">{{ f.name }}</span>
          <span class="shrink-0 font-mono">{{ formatBytes(f.size) }}</span>
        </li>
      </ul>

      <div class="mt-3 flex items-center gap-3">
        <button
          class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900"
          :disabled="uploading || picked.length === 0"
          @click="createBatch"
        >
          {{ uploading ? t('batch_import.uploading', { pct: uploadPct }) : t('batch_import.create') }}
        </button>
        <p v-if="uploadError" class="text-sm text-red-600">{{ uploadError }}</p>
      </div>
    </section>

    <!-- Seznam -->
    <p v-if="loading" class="text-sm text-slate-500">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>

    <template v-else>
      <p v-if="batches.length === 0" class="text-sm text-slate-500">
        {{ t('batch_import.no_batches') }}
      </p>

      <!-- Desktop tabulka -->
      <div v-if="batches.length" class="hidden overflow-x-auto rounded-lg border border-slate-200 md:block dark:border-slate-700">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-slate-800">
            <tr>
              <th class="px-3 py-2 text-left">#</th>
              <th class="px-3 py-2 text-left">{{ t('batch_import.status_label') }}</th>
              <th class="px-3 py-2 text-right">{{ t('batch_import.files') }}</th>
              <th class="px-3 py-2 text-right">{{ t('batch_import.size') }}</th>
              <th class="px-3 py-2 text-left">{{ t('batch_import.created') }}</th>
              <th class="px-3 py-2 text-right"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-slate-700">
            <tr
              v-for="b in batches"
              :key="b.id"
              class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-slate-800/60"
              @click="router.push({ name: 'purchase-invoice-batch-import', params: { id: b.id } })"
            >
              <td class="px-3 py-2 font-mono">{{ b.id }}</td>
              <td class="px-3 py-2">
                <span class="rounded px-2 py-0.5 text-xs" :class="statusBadgeClass(b.status)">
                  {{ t(`batch_import.status.${b.status}`, b.status) }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">{{ b.file_count }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatBytes(b.total_bytes) }}</td>
              <td class="px-3 py-2">{{ b.created_at }}</td>
              <td class="px-3 py-2 text-right">
                <a
                  :href="batchImportApi.packageUrl(b.id)"
                  class="text-xs underline"
                  @click.stop
                >{{ t('batch_import.package') }}</a>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div v-if="batches.length" class="space-y-2 md:hidden">
        <article
          v-for="b in batches"
          :key="b.id"
          class="rounded-lg border border-slate-200 p-3 dark:border-slate-700"
          @click="router.push({ name: 'purchase-invoice-batch-import', params: { id: b.id } })"
        >
          <div class="flex items-center justify-between">
            <span class="font-mono text-sm">#{{ b.id }}</span>
            <span class="rounded px-2 py-0.5 text-xs" :class="statusBadgeClass(b.status)">
              {{ t(`batch_import.status.${b.status}`, b.status) }}
            </span>
          </div>
          <div class="mt-1 flex justify-between text-xs text-slate-500">
            <span>{{ b.file_count }} × PDF · {{ formatBytes(b.total_bytes) }}</span>
            <span>{{ b.created_at }}</span>
          </div>
        </article>
      </div>
    </template>

    <!-- Token modal — nejde zavřít jinak než potvrzením -->
    <div
      v-if="created"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
    >
      <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl dark:bg-slate-900">
        <h2 class="text-lg font-semibold">{{ t('batch_import.token_title') }}</h2>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
          {{ t('batch_import.token_once_hint') }}
        </p>

        <div class="mt-4 flex items-center gap-2">
          <code
            class="flex-1 overflow-x-auto rounded border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
          >{{ created.token }}</code>
          <button
            class="shrink-0 rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-600"
            @click="copyToken"
          >
            {{ tokenCopied ? t('batch_import.token_copied') : t('batch_import.token_copy') }}
          </button>
        </div>

        <p v-if="created.token_expires_at" class="mt-2 text-xs text-slate-500">
          {{ t('batch_import.token_expires', { at: created.token_expires_at }) }}
        </p>

        <div class="mt-5 flex items-center justify-between gap-3">
          <a
            :href="batchImportApi.packageUrl(created.batch_id)"
            class="text-sm underline"
          >{{ t('batch_import.download_package') }}</a>
          <button
            class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900"
            @click="ackToken"
          >
            {{ t('batch_import.token_ack') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
