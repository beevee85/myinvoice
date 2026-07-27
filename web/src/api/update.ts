import { api } from './client'

export interface UpdateStatus {
  current: string
  latest: string | null
  has_update: boolean
  release_notes_md: string | null
  release_url: string | null
  published_at: string | null
  last_check_at: string | null
  last_check_error: string | null
  cache_stale: boolean
  environment: 'docker' | 'native'
  upgrade_in_progress: boolean
  last_upgrade_result: {
    status: 'applied' | 'failed' | string
    target_version?: string
    applied_at?: string
    message?: string
  } | null
}

export interface UpdateTriggerResponse {
  status: 'queued' | 'manual_required' | 'error' | string
  environment?: 'docker' | 'native'
  target_version?: string
  message?: string
  instructions?: string[]
}

export interface PublicVersion {
  current: string
  latest: string | null
  has_update: boolean
  release_url: string | null
}

export const updateApi = {
  /** Public — pro footer / about page (bez auth). */
  publicVersion: () => api.get<PublicVersion>('/version').then((r) => r.data),

  /** Admin — kompletní status včetně release notes. */
  status: (signal?: AbortSignal) =>
    api.get<UpdateStatus>('/admin/update/status', { signal }).then((r) => r.data),

  /** Admin — vynucený fresh fetch z GitHubu. */
  refresh: () => api.post<UpdateStatus>('/admin/update/refresh').then((r) => r.data),

  /** Admin — zařadit upgrade do fronty (Docker) nebo dostat instrukce (nativní). */
  trigger: (target_version?: string) =>
    api
      .post<UpdateTriggerResponse>('/admin/update/trigger', { target_version: target_version ?? null })
      .then((r) => r.data),

  /** Admin — zrušit zaseknutý „upgrade probíhá" flag (watcher neběží / upgrade proběhl ručně). */
  cancel: () => api.post<{ status: string; cleared: boolean }>('/admin/update/cancel').then((r) => r.data),
}
