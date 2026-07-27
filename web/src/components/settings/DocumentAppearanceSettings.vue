<script setup lang="ts">
// FORK (beevee85) REDESIGN F8: Nastavení → Vzhled dokladu.
// Přepínače patičky/čárového kódu, právní věta, razítko/podpis a živý PDF náhled
// na ukázkových datech (query parametry přepisují neuložené hodnoty, takže náhled
// ukazuje i rozpracované změny před uložením).
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type BrandingProfile } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'
import Switch from '@/components/ui/Switch.vue'
import AppSelect from '@/components/ui/AppSelect.vue'

const { t } = useI18n()
const toast = useToast()

const DEFAULT_LEGAL = 'V případě nedodržení data splatnosti vám můžeme účtovat zákonný úrok z prodlení.'

const loading = ref(true)
const saving = ref(false)
const attribution = ref(true)
const barcode = ref(false)
const legalText = ref('')
const signaturePath = ref<string | null>(null)
const signatureBusy = ref(false)
const signatureInput = ref<HTMLInputElement | null>(null)

const previewLang = ref<'cs' | 'en'>('cs')
const previewPaid = ref(false)
const previewProfileId = ref<number | ''>('')
const profiles = ref<BrandingProfile[]>([])
const previewNonce = ref(0)

const previewUrl = computed(() => settingsApi.documentPreviewUrl({
  lang: previewLang.value,
  branding_profile_id: previewProfileId.value,
  attribution: attribution.value,
  barcode: barcode.value,
  legal_text: legalText.value,
  paid: previewPaid.value,
}) + `&_n=${previewNonce.value}#toolbar=0`)

async function load() {
  loading.value = true
  try {
    const s = await settingsApi.getSupplier()
    attribution.value = s.pdf_attribution_enabled === undefined ? true : !!Number(s.pdf_attribution_enabled)
    barcode.value = !!Number(s.pdf_barcode_enabled ?? 0)
    legalText.value = (s.pdf_legal_text as string | null) ?? ''
    signaturePath.value = (s.signature_path as string | null) ?? null
    if (s.branding_profiles_enabled) {
      profiles.value = await settingsApi.listBrandingProfiles().catch(() => [])
    }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save() {
  saving.value = true
  try {
    await settingsApi.updateSupplier({
      pdf_attribution_enabled: attribution.value ? 1 : 0,
      pdf_barcode_enabled: barcode.value ? 1 : 0,
      pdf_legal_text: legalText.value.trim() === '' ? null : legalText.value.trim(),
    })
    toast.success(t('common.saved'))
    previewNonce.value++
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

function insertDefaultLegal() {
  legalText.value = DEFAULT_LEGAL
}

async function onSignatureSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (file.size > 1_048_576) {
    toast.error('Soubor razítka je příliš velký (max 1 MiB).')
    return
  }
  signatureBusy.value = true
  try {
    const r = await settingsApi.uploadSignature(file)
    signaturePath.value = r.signature_path
    toast.success(t('common.saved'))
    previewNonce.value++
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    signatureBusy.value = false
  }
}

async function removeSignature() {
  if (!confirm('Odebrat razítko/podpis z dokladů?')) return
  signatureBusy.value = true
  try {
    await settingsApi.deleteSignature()
    signaturePath.value = null
    previewNonce.value++
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    signatureBusy.value = false
  }
}
</script>

<template>
  <section class="bg-(--surface-muted) rounded-(--radius-card) p-5 sm:p-6">
    <div class="flex items-baseline justify-between gap-3 flex-wrap mb-1">
      <h2 class="text-base">Vzhled dokladu</h2>
      <span class="text-xs text-neutral-500">Živý náhled na ukázkových datech — změny se do náhledu promítají hned, tlačítko Uložit je zapíše.</span>
    </div>

    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-(--grid-gap) mt-4">
      <!-- Levý sloupec: nastavení -->
      <div class="space-y-5">
        <div class="space-y-3">
          <Switch v-model="attribution" label="Patička „Používá fakturační systém MyInvoice.cz“ na dokladech" />
          <Switch v-model="barcode" label="Čárový kód variabilního symbolu (Code128) v hlavičce" />
        </div>

        <div>
          <div class="flex items-baseline justify-between gap-2 mb-1">
            <label class="text-[13px] text-neutral-600 font-medium" for="pdf-legal-text">Právní věta pod položkami</label>
            <button type="button" class="cursor-pointer text-xs text-primary-700 hover:underline" @click="insertDefaultLegal">Vložit výchozí text</button>
          </div>
          <textarea
            id="pdf-legal-text"
            v-model="legalText"
            rows="3"
            maxlength="1000"
            class="w-full rounded-(--radius-input) border border-neutral-200 bg-surface px-3 py-2 text-sm focus-visible:outline-none"
            placeholder="Prázdné = na dokladu se netiskne nic"
          ></textarea>
        </div>

        <div>
          <div class="text-[13px] text-neutral-600 font-medium mb-1.5">Razítko / podpis (blok vlevo dole na dokladu)</div>
          <div class="flex items-center gap-3 flex-wrap">
            <input ref="signatureInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onSignatureSelected" />
            <Button variant="secondary" size="sm" :loading="signatureBusy" @click="signatureInput?.click()">
              {{ signaturePath ? 'Nahrát jiný obrázek' : 'Nahrát obrázek' }}
            </Button>
            <Button v-if="signaturePath" variant="ghost" size="sm" :disabled="signatureBusy" @click="removeSignature">Odebrat</Button>
            <span v-if="signaturePath" class="text-xs text-success-600">✓ nahráno</span>
            <span v-else class="text-xs text-neutral-400">PNG/JPG/WebP, max 1 MiB — bez obrázku se tiskne jen linka pro podpis</span>
          </div>
        </div>

        <div class="pt-1">
          <Button variant="primary" :loading="saving" @click="save">{{ t('common.save') }}</Button>
        </div>
      </div>

      <!-- Pravý sloupec: živý náhled -->
      <div>
        <div class="flex items-center gap-2 flex-wrap mb-2">
          <AppSelect
            :model-value="previewLang"
            :options="[{ value: 'cs', label: 'Náhled česky' }, { value: 'en', label: 'Náhled anglicky' }]"
            size="sm" inline aria-label="Jazyk náhledu"
            @update:model-value="v => previewLang = String(v) === 'en' ? 'en' : 'cs'"
          />
          <AppSelect
            v-if="profiles.length"
            :model-value="previewProfileId"
            :options="[{ value: '', label: 'Výchozí branding' }, ...profiles.map(p => ({ value: p.id, label: p.name }))]"
            size="sm" inline aria-label="Brandingový profil"
            @update:model-value="v => previewProfileId = v === '' ? '' : Number(v)"
          />
          <Switch v-model="previewPaid" label="Ukázat zaplacenou" size="sm" />
          <Button variant="ghost" size="sm" @click="previewNonce++">Obnovit</Button>
        </div>
        <iframe
          :src="previewUrl"
          class="w-full h-[70vh] rounded-(--radius-input) border border-neutral-200 bg-white"
          title="Náhled PDF dokladu"
        ></iframe>
      </div>
    </div>
  </section>
</template>
