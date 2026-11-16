<template>
  <section v-if="status" class="github-app" data-testid="github-app-panel">
    <h4>{{ t('vaultStores.githubApp.title') }}</h4>
    <p v-if="notice" :class="['admin-note', { 'vault-warning': notice !== 'registered' }]" data-testid="github-app-result">
      {{ t(`vaultStores.githubApp.result.${notice}`) }}
    </p>
    <div v-if="error" class="admin-error-banner">{{ error }}</div>

    <template v-if="status.source">
      <p class="admin-note" data-testid="github-app-status">
        <a v-if="status.html_url" :href="status.html_url" target="_blank" rel="noopener noreferrer"><strong>{{ status.slug }}</strong></a>
        <strong v-else>{{ status.slug }}</strong>
        · App ID {{ status.app_id }}
        <template v-if="status.owner"> · {{ t('vaultStores.githubApp.owner', { owner: status.owner }) }}</template>
        · {{ t(`vaultStores.githubApp.source.${status.source}`) }}
      </p>
      <ul v-if="status.problems.length" class="admin-note vault-warning" data-testid="github-app-problems">
        <li v-for="problem in status.problems" :key="problem">{{ problem }}</li>
      </ul>
      <p class="admin-note">{{ t('vaultStores.githubApp.next') }}</p>
      <button
        v-if="status.source === 'database'"
        type="button"
        class="warning-btn"
        :disabled="loading"
        data-testid="github-app-forget"
        @click="forget"
      >
        {{ t('vaultStores.githubApp.forget') }}
      </button>
    </template>

    <form v-else class="admin-form" data-testid="github-app-form" @submit.prevent="register">
      <p class="admin-note">{{ t('vaultStores.githubApp.help') }}</p>
      <input
        v-model.trim="organization"
        :placeholder="t('vaultStores.githubApp.organization')"
        pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,38}"
        data-testid="github-app-organization"
      />
      <small class="admin-note">{{ t('vaultStores.githubApp.organizationHelp') }}</small>
      <button type="submit" :disabled="loading" data-testid="github-app-register">{{ t('vaultStores.githubApp.register') }}</button>
    </form>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../services/api'

interface GitHubAppStatus {
  source: 'env' | 'database' | null
  app_id: string | null
  slug: string | null
  owner: string | null
  html_url: string | null
  registered_at: string | null
  webhook_secret_set: boolean
  problems: string[]
  webhook_url: string
}

const RESULTS = ['registered', 'state_invalid', 'already_configured', 'exchange_failed'] as const

const { t } = useI18n()
const status = ref<GitHubAppStatus | null>(null)
const organization = ref('')
const loading = ref(false)
const error = ref('')
// Back from GitHub's "Create GitHub App" screen: /?storage=github-app&result=…
const returning = new URLSearchParams(window.location.search)
const notice = ref<(typeof RESULTS)[number] | null>(null)
if (returning.get('storage') === 'github-app') {
  const result = returning.get('result') as (typeof RESULTS)[number] | null
  notice.value = result && RESULTS.includes(result) ? result : null
  window.history.replaceState(null, '', window.location.pathname)
}

function message(e: unknown): string {
  const response = (e as { response?: { data?: { message?: string } } }).response
  return response?.data?.message || t('adminPanel.requestFailed')
}

async function fetchStatus() {
  try {
    const response = await api.get<{ data: GitHubAppStatus }>('/admin/github/app')
    const data = response.data.data
    status.value = data && typeof data === 'object' && 'source' in data ? data : null
  } catch {
    // Not an instance administrator, or GitHub is off: nothing to show.
    status.value = null
  }
}

/**
 * GitHub's manifest flow needs a top-level form POST to github.com; the
 * browser comes back to /api/admin/github/manifest/callback, which stores the
 * App and returns here.
 */
async function register() {
  loading.value = true
  error.value = ''
  notice.value = null
  try {
    const query = organization.value ? `?organization=${encodeURIComponent(organization.value)}` : ''
    const response = await api.get<{ data: { post_url: string; manifest: Record<string, unknown> } }>(`/admin/github/manifest${query}`)
    const form = document.createElement('form')
    form.method = 'post'
    form.action = response.data.data.post_url
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = 'manifest'
    input.value = JSON.stringify(response.data.data.manifest)
    form.appendChild(input)
    document.body.appendChild(form)
    form.submit()
  } catch (e: unknown) {
    error.value = message(e)
    loading.value = false
  }
}

async function forget() {
  if (!window.confirm(t('vaultStores.githubApp.forgetConfirm'))) return
  loading.value = true
  error.value = ''
  try {
    await api.delete('/admin/github/app')
    notice.value = null
    await fetchStatus()
  } catch (e: unknown) {
    error.value = message(e)
  } finally {
    loading.value = false
  }
}

onMounted(fetchStatus)
</script>

<style scoped>
.github-app {
  margin-bottom: var(--space-3);
}
.vault-warning {
  color: var(--color-warning-fg);
}
</style>
