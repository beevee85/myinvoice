import { api } from './client'

// FORK (beevee85): pokladní doklady (PPD/VPD) — v2 (0924): pokladny, storno,
// pokladní kniha, náležitosti H3/H6 (protistrana, daňový doklad § 30a, podpisy).

export type CashDocumentKind = 'income' | 'expense'
export type CashDocumentStatus = 'active' | 'storno'

export interface CashVatRow {
  rate: number
  base: number
  vat: number
}

export interface CashDocument {
  id: number
  supplier_id: number
  cash_register_id: number | null
  register_name?: string | null
  kind: CashDocumentKind
  number: string
  issue_date: string
  accounting_date: string | null
  amount: number
  currency: string
  counterparty: string
  counterparty_client_id: number | null
  counterparty_ico: string | null
  counterparty_address: string | null
  description: string
  status: CashDocumentStatus
  storno_of_id: number | null
  storno_of_number?: string | null
  storno_by_number?: string | null
  is_tax_document: boolean
  vat_breakdown: CashVatRow[] | null
  issued_by: string | null
  received_by: string | null
  approved_by: string | null
  note: string | null
  invoice_id: number | null
  purchase_invoice_id: number | null
  project_id: number | null
  invoice_varsymbol: string | null
  purchase_varsymbol?: string | null
  created_by: number
  created_at: string
  updated_at: string
  /** Nefatální upozornění z create (např. zpětný záporný zůstatek). */
  warnings?: Array<{ code: string; message: string }>
}

export interface CashDocumentPayload {
  kind: CashDocumentKind
  cash_register_id?: number
  issue_date: string
  accounting_date?: string
  amount: number
  currency?: string
  counterparty?: string
  counterparty_client_id?: number | null
  counterparty_ico?: string
  counterparty_address?: string
  description?: string
  is_tax_document?: boolean
  vat_breakdown?: CashVatRow[]
  received_by?: string
  approved_by?: string
  note?: string
  invoice_id?: number
  purchase_invoice_id?: number
  project_id?: number
}

export interface CashRegister {
  id: number
  name: string
  currency: string
  is_default: boolean
  is_archived: boolean
  balance: number
}

export interface CashBookEntry {
  id: number
  kind: CashDocumentKind
  number: string
  issue_date: string
  accounting_date: string
  amount: number
  signed_amount: number
  running_balance: number
  currency: string
  counterparty: string
  description: string
  status: CashDocumentStatus
  storno_of_id: number | null
  invoice_varsymbol: string | null
  purchase_varsymbol: string | null
}

export interface CashBook {
  register: { id: number; name: string; currency: string }
  from: string
  to: string
  opening_balance: number
  income_total: number
  expense_total: number
  closing_balance: number
  entries: CashBookEntry[]
}

export interface CashInventoryResult {
  id: number
  inventory_date: string
  expected_amount: number
  actual_amount: number
  difference: number
  settlement_document_id: number | null
}

function supplierQuery(): string {
  // Přímá navigace v prohlížeči neposílá X-Supplier-Id header (na rozdíl od axios) —
  // proto supplier_id jako query param. Middleware ho přečte jako fallback.
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  return qs ? '?' + qs : ''
}

export const cashDocumentsApi = {
  list: (params: { year?: number; kind?: CashDocumentKind; register?: number } = {}) =>
    api.get<CashDocument[]>('/cash-documents', { params }).then(r => r.data),

  create: (payload: CashDocumentPayload) =>
    api.post<CashDocument>('/cash-documents', payload).then(r => r.data),

  update: (id: number, payload: Partial<Omit<CashDocumentPayload, 'kind'>>) =>
    api.put<CashDocument>(`/cash-documents/${id}`, payload).then(r => r.data),

  /** H7 — storno protidokladem (mazání backend odmítá). */
  storno: (id: number, reason: string) =>
    api.post<{ original: CashDocument; storno: CashDocument }>(`/cash-documents/${id}/storno`, { reason }).then(r => r.data),

  pdfUrl: (id: number) => `/api/cash-documents/${id}/pdf${supplierQuery()}`,
}

export const cashRegistersApi = {
  list: () => api.get<CashRegister[]>('/cash-registers').then(r => r.data),

  create: (payload: { name: string; currency?: string }) =>
    api.post<CashRegister>('/cash-registers', payload).then(r => r.data),

  book: (id: number, params: { year: number; month?: number }) =>
    api.get<CashBook>(`/cash-registers/${id}/book`, { params }).then(r => r.data),

  bookPdfUrl: (id: number, year: number, month?: number) => {
    const base = `/api/cash-registers/${id}/book/pdf`
    const qs = supplierQuery()
    const sep = qs ? '&' : '?'
    return `${base}${qs}${sep}year=${year}${month ? `&month=${month}` : ''}`
  },

  inventory: (id: number, payload: { inventory_date?: string; actual_amount: number; note?: string; create_settlement?: boolean }) =>
    api.post<CashInventoryResult>(`/cash-registers/${id}/inventory`, payload).then(r => r.data),
}
