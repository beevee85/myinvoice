// FORK 0925 — compliance vrstva (Dokumenty 4/5/7): trvalé příznaky, přehled rizik.
import { api } from './client'

export type ComplianceSeverity = 'high' | 'medium' | 'low'
export type ComplianceStatus = 'open' | 'acknowledged' | 'explained' | 'resolved' | 'superseded'

export interface ComplianceCheck {
  type: string
  rule?: string
  title: string
  message: string
  legal_reference?: string | null
  choices: Array<{ value: string; note_required?: boolean; note_min?: number }>
  context?: Record<string, unknown>
}

/** Klíč = typ rizika; hodnota = volba uživatele z modalu. */
export type ComplianceAck = Record<string, { choice: string; note?: string }>

export interface ComplianceFlag {
  id: number
  type: string
  severity: ComplianceSeverity
  status: ComplianceStatus
  detected_at: string
  detected_rule: string | null
  subject_type: 'invoice' | 'purchase_invoice' | 'cash_document' | 'payment' | 'client' | 'project'
  subject_id: number
  project_id: number | null
  project_name?: string | null
  project_car_vin?: string | null
  project_car_registration?: string | null
  client_id: number | null
  client_company_name?: string | null
  settlement_group_id: number | null
  car_vin: string | null
  context: Record<string, unknown> | null
  message: string
  legal_reference: string | null
  deadline: string | null
  acknowledged_by: number | null
  acknowledged_by_name?: string | null
  acknowledged_at: string | null
  acknowledgement_choice: string | null
  acknowledgement_note: string | null
}

export interface ComplianceSummary {
  open_high: number
  open_medium: number
  open_low: number
  deadlines_30: number
  handled_total: number
}

export const complianceApi = {
  summary: () => api.get<ComplianceSummary>('/compliance/summary').then(r => r.data),

  flags: (filters: { severity?: string; status?: string; type?: string; client_id?: number; year?: number } = {}) =>
    api.get<ComplianceFlag[]>('/compliance/flags', { params: filters }).then(r => r.data),

  acknowledge: (id: number, choice: string, note?: string, status?: string) =>
    api.post<ComplianceFlag>(`/compliance/flags/${id}/acknowledge`, { choice, note, status }).then(r => r.data),
}

/** 409 z platebních akcí: vyžaduje rozhodnutí uživatele (vrací checks pro modal). */
export function extractComplianceChecks(err: any): ComplianceCheck[] | null {
  const e = err?.response?.data?.error
  if (err?.response?.status === 409 && e?.code === 'compliance_ack_required' && Array.isArray(e?.checks)) {
    return e.checks as ComplianceCheck[]
  }
  return null
}
