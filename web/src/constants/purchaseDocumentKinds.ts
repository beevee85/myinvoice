import type { PurchaseDocumentKind } from '../api/purchaseInvoices'

/**
 * Jediný FE zdroj pravdy pro typy přijatých dokladů — zrcadlí backend
 * PurchaseInvoiceValidation::ALLOWED_DOC_KINDS. Selecty (editor, filtr seznamu,
 * hromadná změna, AI import) čtou odsud, aby se nabídky nerozjely.
 *
 * 'tax_document' = daňový doklad k přijaté záloze (DDKPZ, § 28/1/d ZDPH).
 */
export const PURCHASE_DOCUMENT_KINDS: readonly PurchaseDocumentKind[] = [
  'invoice',
  'receipt',
  'credit_note',
  'advance',
  'tax_document',
] as const

/** i18n klíč labelu typu dokladu (cs.json / en.json: purchase_invoice.document_kind.*). */
export const purchaseDocumentKindLabelKey = (kind: PurchaseDocumentKind | string): string =>
  `purchase_invoice.document_kind.${kind}`
