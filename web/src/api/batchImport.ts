import { api } from './client'

/**
 * FORK (beevee85) — dávkový import přijatých dokladů.
 *
 * Endpointy jsou pod `/purchase-invoices/batch-import/`, ne `import-batches` —
 * to druhé už upstream používá pro jiný koncept (dohledání dávky AI importu).
 *
 * POZOR NA `raw_json`: server ho VĚDOMĚ nevrací. Nese obsah cizích dokladů
 * a UI potřebuje nálezy, ne obsah. Kdyby se sem někdy dostal, vzniklo by druhé
 * místo, odkud osobní údaje odcházejí — a retence na serveru by ztratila smysl,
 * protože co odešlo, se nedá odmazat.
 */

export type FindingSeverity = 'fail' | 'warn' | 'info'

export interface Finding {
  rule: string
  severity: FindingSeverity
  pointer: string
  message: string
}

export interface BatchFile {
  id: number
  original_name: string
  stored_name: string
  byte_size: number
  sha256: string
  mime_type: string | null
  page_count: number | null
}

export interface BatchResult {
  id: number
  purchase_import_batch_file_id: number
  status: 'pending' | 'validated' | 'rejected' | 'applied'
  findings: Finding[]
  purchase_invoice_id: number | null
  raw_purged_at: string | null
  normalized_purged_at: string | null
  created_at: string
}

export interface Batch {
  id: number
  status: string
  file_count: number
  total_bytes: number
  manifest_sha256: string | null
  heartbeat_at: string | null
  error_code: string | null
  error_message: string | null
  created_at: string
  applied_at: string | null
}

export interface BatchDetail {
  batch: Batch
  files: BatchFile[]
  results: BatchResult[]
}

export interface SubmitResponse {
  ok: boolean
  batch_id: number
  findings: Finding[]
}

export interface BatchListItem {
  id: number
  status: string
  file_count: number
  total_bytes: number
  created_at: string
  applied_at: string | null
}

export interface CreateBatchResponse {
  ok: boolean
  batch_id: number
  /** Plaintext token — server ho vrací POUZE TEĎ, nikdy podruhé. */
  token: string
  token_expires_at: string | null
  manifest_sha256: string
  file_count: number
  total_bytes: number
}

export interface ApplyResponse {
  ok: boolean
  purchase_invoice_id: number
  warnings: string[]
  batch_status: string
}

export const batchImportApi = {
  /**
   * Seznam dávek. 404 znamená VYPNUTÝ PŘÍZNAK (routa neexistuje) — navigace
   * to používá jako detekci featury, žádný jiný konfigurační kanál není.
   */
  async list(): Promise<BatchListItem[]> {
    const { data } = await api.get<{ batches: BatchListItem[] }>('/purchase-invoices/batch-import')
    return data.batches
  },

  async create(files: File[], onProgress?: (pct: number) => void): Promise<CreateBatchResponse> {
    const fd = new FormData()
    for (const f of files) fd.append('files[]', f, f.name)
    const { data } = await api.post<CreateBatchResponse>('/purchase-invoices/batch-import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
      },
    })
    return data
  },

  /** Jedno volání = jeden doklad = jeden koncept. Hromadné schválení neexistuje. */
  async applyResult(batchId: number, resultId: number): Promise<ApplyResponse> {
    const { data } = await api.post<ApplyResponse>(
      `/purchase-invoices/batch-import/${batchId}/results/${resultId}/apply`,
    )
    return data
  },

  /** Přímý odkaz na ZIP balíček — supplier_id v query (prohlížeč neposílá X-Supplier-Id). */
  packageUrl(batchId: number): string {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/batch-import/${batchId}/package${qs ? '?' + qs : ''}`
  },

  async get(id: number): Promise<BatchDetail> {
    const { data } = await api.get<BatchDetail>(`/purchase-invoices/batch-import/${id}`)
    return data
  },

  /**
   * Odeslání `results.json`.
   *
   * Token jde HLAVIČKOU, ne v těle — v těle by ho zachytil každý log requestů.
   *
   * Tělo se posílá jako SYROVÝ TEXT přesně tak, jak ho uživatel vložil.
   * Kdybychom ho protáhli přes `JSON.parse` + `JSON.stringify`, zahodily by se
   * duplicitní klíče ještě před odesláním — a právě ty je server postavený
   * odhalit (V80). Sanitizace na klientovi by tu kontrolu tiše vypnula.
   */
  async submitResults(id: number, token: string, rawJson: string): Promise<SubmitResponse> {
    const { data } = await api.post<SubmitResponse>(
      `/purchase-invoices/batch-import/${id}/results`,
      rawJson,
      {
        headers: { 'X-Batch-Token': token, 'Content-Type': 'application/json' },
        transformRequest: [(body) => body],
      },
    )
    return data
  },
}
