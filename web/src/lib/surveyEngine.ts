/**
 * Survey Studio v2: the question model and a faithful client-side port of the server's LogicEngine and the
 * per-type answer checks, so the studio preview and the respondent form show exactly what the server validates.
 * The server stays the authority: every submission is validated there again.
 */
export type QuestionType = 'section' | 'instructions' | 'page_break' | 'short_text' | 'long_text' | 'email' | 'phone' | 'number' | 'date' | 'time'
  | 'single' | 'multiple' | 'dropdown' | 'yes_no' | 'rating' | 'scale' | 'likert' | 'nps' | 'matrix' | 'ranking' | 'file_upload'
export type Operator = 'equals' | 'not_equals' | 'includes' | 'not_includes' | 'gt' | 'gte' | 'lt' | 'lte' | 'contains' | 'answered' | 'not_answered'
export type Item = { id: string; label: string; label_en?: string; skill_id?: string | null; skill_name?: string | null }
export type Condition = { question: string; op: Operator; value?: unknown }
export type VisibleIf = { match: 'all' | 'any'; conditions: Condition[] }
export type Jump = { op: Operator; value?: unknown; to: string }
export type Question = {
  id: string; type: QuestionType; title?: string | null; title_en?: string | null; description?: string | null; description_en?: string | null; required?: boolean
  options?: Item[]; rows?: Item[]; scale?: { min: number; max: number; min_label?: string; max_label?: string }; mode?: 'competence' | 'need'
  max_select?: number; min_select?: number; other?: boolean; max_length?: number; placeholder?: string | null; min?: number | null; max?: number | null
  max_files?: number; max_mb?: number; accept?: string[]; visible_if?: VisibleIf; jumps?: Jump[]; skill_id?: string | null; skill_name?: string | null
}
export type Answer = string | number | string[] | Record<string, number> | { other: string } | undefined
export type Answers = Record<string, Answer>
export type Settings = { edit_policy?: 'until_close' | 'never'; requires_approval?: boolean; segment_fields?: string[]; segment_threshold?: number; one_per_page?: boolean; progress?: boolean; notify?: boolean; ai_opt_in?: boolean
  welcome?: string | null; welcome_en?: string | null; thank_you?: string | null; thank_you_en?: string | null; accent?: string | null }

export const NO_ANSWER: QuestionType[] = ['section', 'instructions', 'page_break']
export const CHOICE_TYPES: QuestionType[] = ['single', 'multiple', 'dropdown', 'ranking', 'likert']
export const SCALED_TYPES: QuestionType[] = ['rating', 'scale', 'matrix']
export const JUMP_TYPES: QuestionType[] = ['single', 'dropdown', 'yes_no', 'rating', 'scale', 'nps', 'number', 'likert']
export const OPERATORS: Operator[] = ['equals', 'not_equals', 'includes', 'not_includes', 'gt', 'gte', 'lt', 'lte', 'contains', 'answered', 'not_answered']
export const ALL_TYPES: QuestionType[] = ['section', 'instructions', 'page_break', 'short_text', 'long_text', 'email', 'phone', 'number', 'date', 'time', 'single', 'multiple', 'dropdown', 'yes_no', 'rating', 'scale', 'likert', 'nps', 'matrix', 'ranking', 'file_upload']

export const uid = (prefix = 'q') => `${prefix}${Math.random().toString(36).slice(2, 10)}`
export const filled = (v: unknown) => !(v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0))

const matches = (answer: unknown, value: unknown) => {
  if (Array.isArray(answer)) return answer.map(String).includes(String(value))
  if (answer && typeof answer === 'object') return Object.values(answer as Record<string, unknown>).map(String).includes(String(value))
  return answer !== undefined && answer !== null && String(answer) === String(value)
}

export function conditionMet(c: { op: Operator; value?: unknown }, answer: unknown): boolean {
  const v = c.value
  switch (c.op) {
    case 'answered': return filled(answer)
    case 'not_answered': return !filled(answer)
    case 'equals': case 'includes': return matches(answer, v)
    case 'not_equals': case 'not_includes': return filled(answer) && !matches(answer, v)
    case 'gt': return typeof answer === 'number' && answer > Number(v)
    case 'gte': return typeof answer === 'number' && answer >= Number(v)
    case 'lt': return typeof answer === 'number' && answer < Number(v)
    case 'lte': return typeof answer === 'number' && answer <= Number(v)
    case 'contains': return typeof answer === 'string' && !!v && answer.toLowerCase().includes(String(v).toLowerCase())
    default: return true
  }
}

export function groupMet(group: VisibleIf | undefined, answers: Answers): boolean {
  if (!group || !group.conditions?.length) return true
  const results = group.conditions.map((c) => conditionMet(c, answers[c.question]))
  return group.match === 'any' ? results.includes(true) : !results.includes(false)
}

/** The same walk as the server: visible questions, pages, whether a jump ended the survey. Hidden answers never count. */
export function evaluate(questions: Question[], answers: Answers): { visible: string[]; pages: string[][]; ended: boolean; known: Answers } {
  const visible: string[] = []
  const pages: string[][] = [[]]
  const known: Answers = {}
  let skipTo: string | null = null
  let pageHidden = false
  let ended = false
  for (const q of questions) {
    if (ended) break
    if (skipTo !== null) { if (q.id !== skipTo) continue; skipTo = null }
    if (q.type === 'page_break') {
      pageHidden = !groupMet(q.visible_if, known)
      if (!pageHidden) { visible.push(q.id); pages.push([]) }
      continue
    }
    if (pageHidden || !groupMet(q.visible_if, known)) continue
    visible.push(q.id)
    pages[pages.length - 1].push(q.id)
    if (NO_ANSWER.includes(q.type)) continue
    const value = answers[q.id]
    if (!filled(value)) continue
    known[q.id] = value
    if (!q.jumps?.length) continue
    for (const j of q.jumps) {
      if (conditionMet({ op: j.op, value: j.value }, value)) { if (j.to === 'end') ended = true; else skipTo = j.to; break }
    }
  }
  return { visible, pages: pages.filter((p) => p.length), ended, known }
}

/** Why an answer is not acceptable, as a translation key, or null when fine (mirrors the server's AnswerValidator). */
export function problem(q: Question, value: Answer): string | null {
  if (!filled(value)) return q.required ? 'required' : null
  const ids = (q.options ?? []).map((o) => o.id)
  const scale = q.scale ?? { min: 1, max: 5 }
  switch (q.type) {
    case 'short_text': case 'long_text': return typeof value === 'string' && value.length <= (q.max_length ?? 5000) ? null : 'invalid'
    case 'email': return typeof value === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim()) ? null : 'invalid'
    case 'phone': return typeof value === 'string' && /^\+?[0-9]{7,15}$/.test(value.replace(/[\s().-]/g, '')) ? null : 'invalid'
    case 'number': return typeof value === 'number' && (q.min == null || value >= q.min) && (q.max == null || value <= q.max) ? null : 'invalid'
    case 'date': return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? null : 'invalid'
    case 'time': return typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value) ? null : 'invalid'
    case 'single': case 'dropdown': case 'likert': return (typeof value === 'string' && ids.includes(value)) || (q.other && typeof value === 'object' && !Array.isArray(value) && 'other' in value && !!(value as { other: string }).other.trim()) ? null : 'invalid'
    case 'multiple': { const list = value as string[]; if (!Array.isArray(list) || list.some((x) => !ids.includes(x))) return 'invalid'; if (q.max_select && list.length > q.max_select) return 'invalid'; if (q.min_select && list.length < q.min_select) return 'select_min'; return null }
    case 'ranking': return Array.isArray(value) && value.length === ids.length && !value.some((x) => !ids.includes(x)) ? null : 'invalid'
    case 'yes_no': return value === 'yes' || value === 'no' ? null : 'invalid'
    case 'rating': case 'scale': return typeof value === 'number' && Number.isInteger(value) && value >= scale.min && value <= scale.max ? null : 'invalid'
    case 'nps': return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 10 ? null : 'invalid'
    case 'matrix': { const grid = value as Record<string, number>; const rows = (q.rows ?? []).map((r) => r.id); if (typeof grid !== 'object' || Array.isArray(grid)) return 'invalid'; if (Object.keys(grid).some((k) => !rows.includes(k))) return 'invalid'; if (q.required && Object.keys(grid).length < rows.length) return 'matrix_incomplete'; return null }
    case 'file_upload': return Array.isArray(value) && value.length <= (q.max_files ?? 1) ? null : 'invalid'
    default: return null
  }
}

/** Problems on the given page for the given answers, keyed by question id. */
export function pageProblems(questions: Question[], ids: string[], answers: Answers): Record<string, string> {
  const byId = Object.fromEntries(questions.map((q) => [q.id, q]))
  const out: Record<string, string> = {}
  for (const id of ids) { const q = byId[id]; if (!q || NO_ANSWER.includes(q.type)) continue; const p = problem(q, answers[id]); if (p) out[id] = p }
  return out
}

/** A fresh question of a type with sensible defaults (used by the studio palette and type conversion). */
export function blank(type: QuestionType, label: (k: string, n?: number) => string, from?: Question): Question {
  const q: Question = { id: from?.id ?? uid(), type, title: from?.title ?? '', title_en: from?.title_en, description: from?.description, required: NO_ANSWER.includes(type) ? false : !!from?.required, visible_if: from?.visible_if }
  const items = from?.options ?? from?.rows
  if (CHOICE_TYPES.includes(type)) q.options = type === 'likert' ? [1, 2, 3, 4, 5].map((n) => ({ id: `o${n}`, label: label('likert', n) })) : (items?.length ? items.map((o) => ({ ...o })) : [1, 2, 3].map((n) => ({ id: uid('o'), label: `${label('option')} ${n}` })))
  if (type === 'matrix') { q.rows = items?.length ? items.map((o) => ({ ...o })) : [{ id: uid('r'), label: `${label('statement')} 1` }]; q.scale = from?.scale ?? { min: 1, max: 5 } }
  if (type === 'rating' || type === 'scale') q.scale = from?.scale ?? { min: 1, max: 5 }
  if (type === 'multiple') q.max_select = from?.max_select
  if (type === 'file_upload') { q.max_files = 1; q.max_mb = 10; q.accept = ['pdf', 'image'] }
  if (type === 'number') { q.min = from?.min ?? null; q.max = from?.max ?? null }
  if (JUMP_TYPES.includes(type)) q.jumps = from?.jumps
  return q
}

export const titleOf = (q: Question, en: boolean) => (en && q.title_en ? q.title_en : q.title) ?? ''
export const descOf = (q: Question, en: boolean) => (en && q.description_en ? q.description_en : q.description) ?? ''
export const labelOf = (o: Item, en: boolean) => (en && o.label_en ? o.label_en : o.label)
