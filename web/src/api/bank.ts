import { api } from './client'

export interface BankStatement {
  id: number
  /** Zdroj výpisu: 'gpc' = nahraný/importovaný výpis, 'email_notice' = měsíční agregát e-mailových avíz. */
  source?: 'gpc' | 'email_notice'
  file_name: string
  account_number: string
  /** Kód banky (4místný), pokud je u výpisu evidovaný — pro zobrazení „účet / kód". */
  bank_code?: string | null
  /** Vlastní pojmenování účtu z currencies.label (např. "CZK — Fio Bank"), pokud match. */
  account_label: string | null
  currency: string | null
  statement_date: string
  statement_number: string | null
  prev_balance: number
  curr_balance: number
  transaction_count: number
  matched_count: number
  imported_at: string
  has_file: boolean
  /** Je k výpisu přiložené PDF (bank_statements.pdf_content)? */
  has_pdf: boolean
  /** Původní název nahraného PDF, pokud je. */
  pdf_name?: string | null
}

export type MatchStatus = 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'

export interface BankTransaction {
  id: number
  /** 'statement' = z nahraného výpisu, 'email_notice' = z e-mailového avíza. */
  source?: 'statement' | 'email_notice'
  statement_id: number
  posted_at: string
  amount: number
  currency: string | null
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  counterparty_account: string | null
  counterparty_bank: string | null
  counterparty_name: string | null
  description: string | null
  bank_ref: string | null
  matched_invoice_id: number | null
  matched_purchase_invoice_id?: number | null
  matched_varsymbol?: string | null
  matched_invoice_amount?: number | null
  matched_client_name?: string | null
  /** Číslo přijaté faktury (vendor_invoice_number, fallback varsymbol), pokud je transakce spárovaná s přijatou. */
  matched_purchase_ref?: string | null
  /** Název dodavatele spárované přijaté faktury. */
  matched_vendor_name?: string | null
  /** Seznam vystavených faktur uhrazených touto transakcí (sloučená úhrada → víc než 1). */
  matched_invoices?: MatchedInvoice[]
  match_status: MatchStatus
  matched_at: string | null
}

/** Jedna vystavená faktura uhrazená bankovní transakcí (z invoice_payments). */
export interface MatchedInvoice {
  invoice_id: number
  varsymbol: string | null
  invoice_type: string
  amount: number
  client_name: string | null
}

/** Kandidát na spárování dle částky + data (±14 dní) — vystavená i přijatá faktura. */
export interface MatchCandidate {
  type: 'invoice' | 'purchase_invoice'
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny transakce (jen u cross-currency, jinak null). */
  converted_amount: number | null
  converted_currency: string | null
  issue_date: string
  due_date: string | null
  party: string | null
  /** Faktura je už zaplacená — UI zobrazí varovný štítek (duplicitní/druhá platba). */
  paid: boolean
}

/** Jedna faktura v návrhu sloučené úhrady. */
export interface SplitSuggestionInvoice {
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny platby (jen u cross-currency, jinak null). */
  converted: number | null
  /** Faktura je už zaplacená → spárování = rekonciliace existující platby (ne nová úhrada). */
  is_paid?: boolean
  issue_date: string
  due_date: string | null
}

/** Návrh kombinace faktur jednoho klienta, jejíž součet odpovídá příchozí platbě. */
export interface SplitSuggestion {
  client_id: number
  client_name: string | null
  currency: string
  total: number
  count: number
  invoices: SplitSuggestionInvoice[]
}

export interface BankStatementDetail extends BankStatement {
  credit_total: number
  debit_total: number
  transactions: BankTransaction[]
}

export interface ImportResult {
  statement_id: number
  transactions: number
  matched: number
  duplicate: boolean
}

/** Kandidát měnového účtu při nejednoznačném sdíleném čísle účtu (#167). */
export interface AmbiguousAccount {
  account_id: number
  code: string
  label: string
}

/** Účet pro filtr v přehledu výpisů (distinct account_number + jeho label z currencies). */
export interface BankAccountOption {
  account_number: string
  bank_code?: string | null
  label: string | null
}

export interface BankStatementPage {
  items: BankStatement[]
  total: number
  page: number
  limit: number
  /** Roky přítomné ve výpisech (pro filtr rok), descending. */
  years: number[]
  /** Účty přítomné ve výpisech (pro filtr na číslo účtu). */
  accounts: BankAccountOption[]
  /** Je v cfg.php nastavené adresářové skenování (bank_import.scan_root)? Řídí tlačítko „Skenovat adresář". */
  scan_configured: boolean
}

export interface BankListParams {
  page?: number
  year?: number | ''
  month?: number | ''
  account?: string
}

export const bankApi = {
  list: (params: BankListParams = {}) =>
    api.get<BankStatementPage>('/bank-statements', { params: {
      page: params.page ?? 1,
      ...(params.year !== undefined && params.year !== '' ? { 'filter[year]': params.year } : {}),
      ...(params.month !== undefined && params.month !== '' ? { 'filter[month]': params.month } : {}),
      ...(params.account ? { 'filter[account]': params.account } : {}),
    } }).then(r => r.data),
  get: (id: number) => api.get<BankStatementDetail>(`/bank-statements/${id}`).then(r => r.data),
  /**
   * Nahraje GPC/ABO výpis. `accountId` (currencies.id) je volitelný — povinný jen
   * u víceměnového účtu se sdíleným číslem účtu, kdy server vrátí 409
   * `ambiguous_account_currency` se seznamem kandidátů (#167).
   */
  upload: (file: File, accountId?: number) => {
    const fd = new FormData()
    fd.append('file', file)
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  matchCandidates: (txId: number) =>
    api.get<{ candidates: MatchCandidate[] }>(`/bank-transactions/${txId}/match-candidates`)
      .then(r => r.data.candidates),
  matchManual: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; varsymbol?: string }) =>
    api.post<{ matched: true; paid_at?: string; purchase_invoice_id?: number }>(`/bank-transactions/${txId}/match`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.varsymbol ? { varsymbol: ref.varsymbol } : {}),
    }).then(r => r.data),
  /** Sloučená úhrada: jedna příchozí platba → více vystavených faktur (téhož klienta). */
  matchMultiple: (txId: number, invoiceIds: number[]) =>
    api.post<{ matched: true; split: true; paid_at?: string; invoice_ids: number[]; final_draft_ids?: number[] }>(
      `/bank-transactions/${txId}/match`, { invoice_ids: invoiceIds },
    ).then(r => r.data),
  /** Návrhy sloučené úhrady (kombinace faktur jednoho klienta dle částky + okna dní). */
  splitSuggestions: (txId: number, opts: { invoiceId?: number; window?: number; max?: number } = {}) =>
    api.get<{ suggestions: SplitSuggestion[]; window: number; max: number }>(
      `/bank-transactions/${txId}/split-suggestions`,
      { params: {
        ...(opts.invoiceId ? { invoice_id: opts.invoiceId } : {}),
        ...(opts.window ? { window: opts.window } : {}),
        ...(opts.max ? { max: opts.max } : {}),
      } },
    ).then(r => r.data),
  ignore: (txId: number) =>
    api.post<{ ignored: true }>(`/bank-transactions/${txId}/ignore`, {}).then(r => r.data),
  unmatch: (txId: number) =>
    api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unmatch`, {}).then(r => r.data),
  createPurchaseInvoice: (txId: number, vendorId: number) =>
    api.post<{ purchase_invoice_id: number; vendor_id: number; currency: string }>(
      `/bank-transactions/${txId}/create-purchase-invoice`, { vendor_id: vendorId },
    ).then(r => r.data),
  rematch: (statementId: number) =>
    api.post<{ considered: number; newly_matched: number; newly_partial: number; still_unmatched: number }>(
      `/bank-statements/${statementId}/rematch`, {}).then(r => r.data),
  scan: () => api.post<{ scanned: number; imported: number; duplicate: number; errors: number }>(
    '/bank-statements/scan', {},
  ).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}`).then(r => r.data),
  /**
   * Build download URL pro originální GPC. Vrací absolutní URL — UI ji použije
   * v `<a href>` (browser stáhne přímo). Auth cookie se posílá automaticky.
   */
  downloadUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/download`
  },
  /** Download URL přiloženého PDF výpisu (analogie downloadUrl pro GPC). */
  pdfUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/pdf`
  },
  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ uploaded: true; pdf_name: string }>(`/bank-statements/${id}/pdf`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deletePdf: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}/pdf`).then(r => r.data),
}
