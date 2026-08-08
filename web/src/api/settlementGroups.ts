// FORK 0922 — vyúčtovací skupiny (Dokument 1, B1 + C1–C3 + D1)
import { api } from './client'

export type SettlementDirection = 'purchase' | 'sale'
export type SettlementRole = 'advance' | 'advance_tax_document' | 'final' | 'credit_note'
export type SettlementGroupStatus = 'open' | 'settled' | 'mismatch'

/** Kompaktní řádek dokladu ve skupině / mezi samostatnými (shodný tvar obou směrů). */
export interface SettlementMember {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  /** purchase: document_kind; sale: invoice_type (proforma/tax_document/invoice/credit_note) */
  document_kind: string
  settlement_role: SettlementRole | null
  issue_date: string
  tax_date: string | null
  due_date: string | null
  total_with_vat: number
  rounding: number
  amount_to_pay: number
  status: string
  paid_at: string | null
  currency: string
  counterparty_name?: string
}

export interface SettlementGroup {
  id: number
  label: string | null
  external_ref: string | null
  counterparty_id: number | null
  counterparty_name: string | null
  final_document_id: number | null
  final_varsymbol: string | null
  final_amount_to_pay: number | null
  status: SettlementGroupStatus
  total_amount: number
  currency: string
  sort_date: string | null
  members: SettlementMember[]
}

export interface SettlementGroupsResponse {
  groups: SettlementGroup[]
  standalone: SettlementMember[]
}

export interface SettlementChainStep extends SettlementMember {}

const base = (direction: SettlementDirection) =>
  direction === 'purchase' ? '/purchase-invoices' : '/invoices'

export const settlementGroupsApi = {
  list: (direction: SettlementDirection) =>
    api.get<SettlementGroupsResponse>(`${base(direction)}/settlement-groups`).then(r => r.data),
  chain: (direction: SettlementDirection, id: number) =>
    api.get<{ steps: SettlementChainStep[] }>(`${base(direction)}/${id}/settlement-chain`).then(r => r.data.steps),
}
