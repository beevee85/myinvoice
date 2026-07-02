<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import SentEmails from './SentEmails.vue'
import EmailProfiles from './EmailProfiles.vue'
import EmailTemplates from './EmailTemplates.vue'
import ElectronicSignatures from './ElectronicSignatures.vue'
import SmtpLogAnalysis from './SmtpLogAnalysis.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

type Tab = 'sent' | 'templates' | 'profiles' | 'signatures' | 'logs'
const VALID: Tab[] = ['sent', 'templates', 'profiles', 'signatures', 'logs']

function initialTab(): Tab {
  const q = String(route.query.tab || '')
  return (VALID as string[]).includes(q) ? (q as Tab) : 'sent'
}
const tab = ref<Tab>(initialTab())

// Drž aktivní záložku v URL (?tab=…) — kvůli deep-linkům a zpětné navigaci.
watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})
</script>

<template>
  <div>
    <!-- Záložky ve stylu Číselníků -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in VALID" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'sent' ? t('nav.sent_emails')
          : tt === 'templates' ? t('nav.email_templates')
          : tt === 'profiles' ? t('nav.email_profiles')
          : tt === 'signatures' ? t('nav.electronic_signatures')
          : t('nav.smtp_logs') }}
      </button>
    </div>

    <SentEmails v-if="tab === 'sent'" />
    <EmailTemplates v-else-if="tab === 'templates'" />
    <EmailProfiles v-else-if="tab === 'profiles'" />
    <ElectronicSignatures v-else-if="tab === 'signatures'" />
    <SmtpLogAnalysis v-else />
  </div>
</template>
