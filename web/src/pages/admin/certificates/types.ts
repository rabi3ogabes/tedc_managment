export type ElementType = 'text' | 'qr' | 'image' | 'rect'

export type CertElement = {
  id: string
  type: ElementType
  x: number; y: number; w: number; h: number
  text?: string; font?: string; size?: number; color?: string; bold?: boolean
  align?: 'left' | 'right' | 'center' | 'justify'; valign?: 'top' | 'middle' | 'bottom'; dir?: 'rtl' | 'ltr'; line?: number
  src?: string
  stroke?: string; stroke_width?: number; fill?: string; radius?: number
}

export type CertTemplate = {
  id: string; name_ar: string; name_en: string; name: string; kind: 'trainee' | 'trainer'
  width_mm: number; height_mm: number; elements: CertElement[]
  has_background: boolean; has_source_pdf: boolean; is_default: boolean; status: string; updated_at: string | null
  programs_count?: number
}

export type TemplateMeta = { tokens: Record<string, string[]>; fonts: Record<string, string>; sample: Record<string, string> }

export const FONT_STACK: Record<string, string> = {
  xbriyaz: "'Noto Naskh Arabic','Amiri','Geeza Pro',serif",
  dejavusans: "'DejaVu Sans',Verdana,'Segoe UI',sans-serif",
  dejavuserif: "'DejaVu Serif',Georgia,serif",
}

export const PT_TO_MM = 0.352778

/** Replaces {{placeholders}} with sample values (or keeps them visible when no values are given). */
export function fillTokens(text: string, values: Record<string, string> | null): string {
  return values ? text.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, k: string) => values[k] ?? '') : text
}

export const uid = () => Math.random().toString(36).slice(2, 10)
