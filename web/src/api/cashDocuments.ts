import { api } from './client'

// FORK (beevee85): pokladní doklady (PPD/VPD) — doklad o pohybu hotovosti.

export type CashDocumentKind = 'income' | 'expense'

export interface CashDocument {
  id: number
  supplier_id: number
  kind: CashDocumentKind
  number: string
  issue_date: string
  amount: number
  currency: string
  counterparty: string
  description: string
  invoice_id: number | null
  purchase_invoice_id: number | null
  invoice_varsymbol: string | null
  created_by: number
  created_at: string
  updated_at: string
}

export interface CashDocumentPayload {
  kind: CashDocumentKind
  issue_date: string
  amount: number
  currency?: string
  counterparty?: string
  description?: string
  invoice_id?: number
  purchase_invoice_id?: number
}

export const cashDocumentsApi = {
  list: (params: { year?: number; kind?: CashDocumentKind } = {}) =>
    api.get<CashDocument[]>('/cash-documents', { params }).then(r => r.data),

  create: (payload: CashDocumentPayload) =>
    api.post<CashDocument>('/cash-documents', payload).then(r => r.data),

  update: (id: number, payload: Partial<Omit<CashDocumentPayload, 'kind'>>) =>
    api.put<CashDocument>(`/cash-documents/${id}`, payload).then(r => r.data),

  remove: (id: number) => api.delete(`/cash-documents/${id}`),

  pdfUrl: (id: number) => {
    // Přímá navigace v prohlížeči neposílá X-Supplier-Id header (na rozdíl od axios) —
    // proto přidáváme supplier_id jako query param. Middleware ho přečte jako fallback.
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/cash-documents/${id}/pdf${qs ? '?' + qs : ''}`
  },
}
