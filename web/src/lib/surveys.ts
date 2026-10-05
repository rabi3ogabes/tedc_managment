/** Training-needs survey model shared by the builder, the importer, the report and the respondent form. */

export type QuestionType =
  | 'section' | 'short_text' | 'long_text' | 'single' | 'multiple' | 'dropdown' | 'yes_no'
  | 'rating' | 'scale' | 'nps' | 'matrix' | 'ranking' | 'number' | 'date'

export type Choice = { id: string; label: string; skill_id?: string | null; skill_name?: string | null }
export type Scale = { min: number; max: number; min_label?: string; max_label?: string }
export type Logic = { question: string; op: 'equals' | 'not_equals' | 'includes' | 'gte' | 'lte' | 'answered'; value?: string | number | null }

export type Question = {
  id: string
  type: QuestionType
  title: string
  title_en?: string | null
  evidence?: boolean
  description?: string | null
  required?: boolean
  options?: Choice[]
  rows?: Choice[]
  scale?: Scale
  mode?: 'competence' | 'need'
  skill_id?: string | null
  skill_name?: string | null
  max_select?: number | null
  show_if?: Logic | null
}

export type Answer = string | number | string[] | Record<string, number>
export type Answers = Record<string, Answer | undefined>

export type SurveySettings = { anonymous?: boolean; show_progress?: boolean; one_per_page?: boolean; welcome?: string; thank_you?: string; accent?: string }

export type Audience = {
  school_ids?: string[]; regions?: string[]; school_types?: string[]; stages?: string[]; job_title_ids?: string[]
  specializations?: string[]; nationalities?: string[]; genders?: string[]; qualifications?: string[]
  experience_min?: number | null; experience_max?: number | null
}

export type SurveySummary = {
  id: string; title: string; description?: string | null; status: 'draft' | 'published' | 'closed'; source: 'builder' | 'template' | 'import'
  approval_status?: 'draft' | 'pending' | 'approved' | 'returned'; approval_note?: string | null
  template_key?: string | null; questions_count: number; recipients_count: number; responses_count: number; needs_count: number
  audience_summary: string; accent?: string | null; published_at?: string | null; closes_at?: string | null; created_at: string; updated_at: string
}

export type Survey = SurveySummary & { questions: Question[]; audience: Audience; settings: SurveySettings }

export type Template = { key: string; icon: string; accent: string; title: string; description: string; count: number; questions: Question[] }

export type Skill = { id: string; name_ar: string; name_en: string; category?: string }

export const QUESTION_TYPES: { type: QuestionType; group: 'choice' | 'scale' | 'text' | 'layout' }[] = [
  { type: 'single', group: 'choice' },
  { type: 'multiple', group: 'choice' },
  { type: 'dropdown', group: 'choice' },
  { type: 'yes_no', group: 'choice' },
  { type: 'ranking', group: 'choice' },
  { type: 'matrix', group: 'scale' },
  { type: 'rating', group: 'scale' },
  { type: 'scale', group: 'scale' },
  { type: 'nps', group: 'scale' },
  { type: 'short_text', group: 'text' },
  { type: 'long_text', group: 'text' },
  { type: 'number', group: 'text' },
  { type: 'date', group: 'text' },
  { type: 'section', group: 'layout' },
]

export const CHOICE_TYPES: QuestionType[] = ['single', 'multiple', 'dropdown', 'ranking']
export const SCALED_TYPES: QuestionType[] = ['rating', 'scale', 'matrix']

export const uid = (prefix = 'q') => `${prefix}${Math.random().toString(36).slice(2, 9)}`

/** A fresh question of the given type with sensible defaults. */
export function newQuestion(type: QuestionType, t: (k: string) => string): Question {
  const q: Question = { id: uid(), type, title: type === 'section' ? t('surveys.defaults.section') : t('surveys.defaults.question'), required: type !== 'section' }
  if (CHOICE_TYPES.includes(type)) q.options = [1, 2, 3].map((n) => ({ id: uid('o'), label: `${t('surveys.defaults.option')} ${n}` }))
  if (type === 'matrix') {
    q.rows = [1, 2].map((n) => ({ id: uid('r'), label: `${t('surveys.defaults.statement')} ${n}` }))
    q.scale = { min: 1, max: 5, min_label: t('surveys.defaults.low'), max_label: t('surveys.defaults.high') }
    q.mode = 'competence'
  }
  if (type === 'rating') { q.scale = { min: 1, max: 5 }; q.mode = 'competence' }
  if (type === 'scale') { q.scale = { min: 1, max: 5, min_label: t('surveys.defaults.low'), max_label: t('surveys.defaults.high') }; q.mode = 'need' }
  return q
}

export const filled = (v: unknown) => !(v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0) || (typeof v === 'object' && !Array.isArray(v) && Object.keys(v as object).length === 0))

/** Conditional logic, mirrored exactly by the server. */
export function isVisible(q: Question, answers: Answers): boolean {
  const rule = q.show_if
  if (!rule?.question) return true
  const a = answers[rule.question]
  const matches = Array.isArray(a) ? a.map(String).includes(String(rule.value)) : String(a ?? '') === String(rule.value ?? '')
  switch (rule.op) {
    case 'answered': return filled(a)
    case 'not_equals': return filled(a) && !matches
    case 'gte': return typeof a === 'number' && a >= Number(rule.value)
    case 'lte': return typeof a === 'number' && a <= Number(rule.value)
    default: return matches
  }
}

/** Missing required answers among the visible questions. */
export function missingRequired(questions: Question[], answers: Answers): string[] {
  return questions.filter((q) => q.type !== 'section' && q.required && isVisible(q, answers)).filter((q) => {
    const a = answers[q.id]
    if (q.type === 'matrix') return !a || Object.keys(a as object).length < (q.rows?.length ?? 0)
    return !filled(a)
  }).map((q) => q.id)
}

/** Splits questions into pages at each section (or one question per page). */
export function paginate(questions: Question[], onePerPage = false): Question[][] {
  if (onePerPage) {
    const pages: Question[][] = []
    let heading: Question | null = null
    for (const q of questions) {
      if (q.type === 'section') { heading = q; continue }
      pages.push(heading ? [heading, q] : [q])
      heading = null
    }
    return pages.length ? pages : [questions]
  }
  const pages: Question[][] = [[]]
  for (const q of questions) {
    if (q.type === 'section' && pages[pages.length - 1].some((x) => x.type !== 'section')) pages.push([])
    pages[pages.length - 1].push(q)
  }
  return pages.filter((p) => p.length)
}

// ---------------------------------------------------------------------------
// Smart skill linking

const normalize = (s: string) => s.toLowerCase()
  .replace(/[ً-ْـ]/g, '')
  .replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
  .replace(/[^\p{L}\p{N}\s]/gu, ' ')
  .split(/\s+/).map((w) => w.replace(/^(وال|بال|لل|ال)/, '')).filter((w) => w.length > 1)

/** Best matching skill for a label (token overlap), or null. */
export function suggestSkill(label: string, skills: Skill[]): Skill | null {
  const words = new Set(normalize(label))
  if (!words.size) return null
  let best: Skill | null = null
  let bestScore = 0
  for (const s of skills) {
    for (const name of [s.name_ar, s.name_en]) {
      const tokens = normalize(name)
      if (!tokens.length) continue
      const hit = tokens.filter((w) => words.has(w) || [...words].some((x) => x.length > 3 && (x.startsWith(w) || w.startsWith(x)))).length
      const score = hit / tokens.length
      if (score > bestScore) { bestScore = score; best = s }
    }
  }
  return bestScore >= 0.6 ? best : null
}

/** Links unlinked options / statements / scales to catalogue skills. Returns how many links were added. */
export function autoLinkSkills(questions: Question[], skills: Skill[]): { questions: Question[]; linked: number } {
  let linked = 0
  const link = <T extends { label?: string; title?: string; skill_id?: string | null; skill_name?: string | null }>(item: T, text: string): T => {
    if (item.skill_id) return item
    const s = suggestSkill(text, skills)
    if (!s) return item
    linked++
    return { ...item, skill_id: s.id, skill_name: s.name_ar }
  }
  const out = questions.map((q) => {
    let next = { ...q }
    if (next.options && ['multiple', 'single', 'ranking', 'dropdown'].includes(q.type)) next.options = next.options.map((o) => link(o, o.label))
    if (next.rows) next.rows = next.rows.map((r) => link(r, r.label))
    if (['rating', 'scale', 'yes_no'].includes(q.type)) next = link(next, q.title)
    return next
  })
  return { questions: out, linked }
}

// ---------------------------------------------------------------------------
// Import from Word (.docx), Excel (.xlsx), CSV or plain text

const TYPE_ALIASES: Record<string, QuestionType> = {
  single: 'single', radio: 'single', 'اختيار من متعدد': 'single', 'اختيار واحد': 'single', choice: 'single',
  multiple: 'multiple', checkbox: 'multiple', checkboxes: 'multiple', 'اختيارات متعددة': 'multiple', متعدد: 'multiple',
  dropdown: 'dropdown', 'قائمة منسدلة': 'dropdown', قائمة: 'dropdown',
  yes_no: 'yes_no', 'yes/no': 'yes_no', 'نعم/لا': 'yes_no', 'نعم / لا': 'yes_no',
  rating: 'rating', stars: 'rating', تقييم: 'rating', نجوم: 'rating',
  scale: 'scale', likert: 'scale', مقياس: 'scale', ليكرت: 'scale',
  nps: 'nps', matrix: 'matrix', grid: 'matrix', مصفوفة: 'matrix', جدول: 'matrix',
  ranking: 'ranking', rank: 'ranking', ترتيب: 'ranking',
  text: 'short_text', short_text: 'short_text', 'نص قصير': 'short_text', نص: 'short_text',
  paragraph: 'long_text', long_text: 'long_text', 'نص طويل': 'long_text', مقالي: 'long_text',
  number: 'number', رقم: 'number', date: 'date', تاريخ: 'date', section: 'section', قسم: 'section', عنوان: 'section',
}

const splitOptions = (s: string) => s.split(/\s*(?:\n|;|\||؛|،(?=\s*\S))\s*/).map((x) => x.trim()).filter(Boolean)
const truthy = (s: string) => /^(1|yes|y|true|نعم|إلزامي|الزامي|required|x|✓|✔)$/i.test(s.trim())
const yesNoSet = new Set(['نعم', 'لا', 'yes', 'no'])

function inferType(title: string, options: string[]): QuestionType {
  if (!options.length) {
    if (/(اذكر|صف|اقترح|وضح|اشرح|لماذا|describe|explain|why|suggest|comment|ملاحظات)/i.test(title)) return 'long_text'
    if (/(قيّم|قيم|درجة|مستوى|rate|level)/i.test(title)) return 'rating'
    if (/(كم عدد|عدد|how many)/i.test(title)) return 'number'
    return 'short_text'
  }
  if (options.length === 2 && options.every((o) => yesNoSet.has(o.toLowerCase()))) return 'yes_no'
  if (options.every((o) => /^\d+$/.test(o))) return 'scale'
  if (/(اختر كل|يمكن اختيار أكثر|أكثر من إجابة|select all|all that apply|\(متعدد\))/i.test(title)) return 'multiple'
  if (/(رتّب|رتب|rank)/i.test(title)) return 'ranking'
  return 'single'
}

function build(title: string, options: string[], extra: Partial<Question> & { typeHint?: string } = {}): Question {
  const { typeHint, ...rest } = extra
  const clean = title.replace(/^\s*(?:[([]?\s*(?:\d+|[٠-٩]+|س\s*\d+)\s*[.)\]\-–:]\s*)/, '').replace(/\s*\*\s*$/, '').trim()
  let type = (typeHint && TYPE_ALIASES[typeHint.trim().toLowerCase()]) || inferType(clean, options)
  const q: Question = { id: uid(), type, title: clean || title.trim(), required: rest.required ?? /\*\s*$|\(إلزامي\)|\(required\)/i.test(title) }
  if (type === 'scale' && options.every((o) => /^\d+$/.test(o))) {
    const nums = options.map(Number)
    q.scale = { min: Math.min(...nums) === 0 ? 0 : 1, max: Math.min(10, Math.max(...nums)) }
    q.mode = 'need'
  } else if (type === 'matrix') {
    q.rows = options.map((label) => ({ id: uid('r'), label }))
    q.scale = { min: 1, max: 5 }
    q.mode = 'competence'
    if (!q.rows.length) type = q.type = 'rating'
  } else if (CHOICE_TYPES.includes(type)) {
    q.options = options.map((label) => ({ id: uid('o'), label }))
    if (q.options.length < 2) { q.type = 'short_text'; delete q.options }
  } else if (type === 'rating' || type === 'scale') {
    q.scale = { min: 1, max: 5 }
    q.mode = type === 'scale' ? 'need' : 'competence'
  }
  const extras = Object.fromEntries(Object.entries(rest).filter(([, v]) => v !== undefined && v !== null))
  return { ...q, ...extras, type: q.type }
}

/** Rows from a spreadsheet: header-aware (Question / Type / Options / Required / Description / Skill). */
export function questionsFromRows(rows: string[][]): Question[] {
  const cleanRows = rows.map((r) => r.map((c) => (c ?? '').toString().trim())).filter((r) => r.some(Boolean))
  if (!cleanRows.length) return []
  const header = cleanRows[0].map((h) => h.toLowerCase())
  const find = (...names: string[]) => header.findIndex((h) => names.some((n) => h.includes(n)))
  const col = {
    title: find('question', 'السؤال', 'سؤال', 'العبارة'),
    type: find('type', 'النوع', 'نوع'),
    options: find('option', 'الخيارات', 'خيارات', 'البدائل', 'choices'),
    required: find('required', 'إلزامي', 'الزامي', 'مطلوب'),
    description: find('description', 'الوصف', 'وصف', 'توضيح'),
    skill: find('skill', 'المهارة', 'مهارة', 'المجال'),
  }
  const hasHeader = col.title >= 0
  const body = hasHeader ? cleanRows.slice(1) : cleanRows
  return body.map((r) => {
    if (!hasHeader) return build(r[0], r.slice(1).filter(Boolean))
    const options = col.options >= 0 ? splitOptions(r[col.options] ?? '') : r.filter((_, i) => i !== col.title && ![col.type, col.required, col.description, col.skill].includes(i)).filter(Boolean)
    const q = build(r[col.title] ?? '', options, {
      typeHint: col.type >= 0 ? r[col.type] : undefined,
      required: col.required >= 0 ? truthy(r[col.required] ?? '') : undefined,
      description: col.description >= 0 ? r[col.description] || null : null,
    })
    if (col.skill >= 0 && r[col.skill] && ['rating', 'scale', 'yes_no'].includes(q.type)) q.skill_name = r[col.skill]
    return q
  }).filter((q) => q.title)
}

const BULLET = /^\s*(?:[•●○◦▪▫■□☐▢❑✓✔\-–*]|\(\s*\)|\[\s*\]|[a-hA-H][.)]|[أبجدهوزح][.)-]|\d+\s*\))\s*/
const QUESTION_START = /^\s*(?:\d+|[٠-٩]+|س\s*\d+)\s*[.)\-–:]\s*\S/

/** Lines of a Word document or pasted text: questions, their options and section headings. */
export function questionsFromLines(lines: { text: string; heading?: boolean; list?: boolean }[]): Question[] {
  const out: Question[] = []
  let current: { title: string; options: string[] } | null = null
  const flush = () => { if (current) out.push(build(current.title, current.options)); current = null }
  for (const { text, heading, list } of lines) {
    const line = text.trim()
    if (!line) continue
    if (heading && !/[?؟]\s*\*?$/.test(line)) { flush(); out.push({ id: uid(), type: 'section', title: line }); continue }
    const looksQuestion = /[?؟:]\s*\*?$/.test(line) || QUESTION_START.test(line)
    const looksOption = BULLET.test(line) || (!!list && !looksQuestion && !!current && line.length < 120)
    if (current && looksOption && !QUESTION_START.test(line)) { current.options.push(line.replace(BULLET, '').trim()); continue }
    if (looksQuestion || !current) { flush(); current = { title: line, options: [] }; continue }
    // A short line right after a question without bullets is still an option (e.g. "Yes" / "No").
    if (line.length < 60) current.options.push(line)
    else { flush(); current = { title: line, options: [] } }
  }
  flush()
  return out
}

const xmlText = (xml: string) => new DOMParser().parseFromString(xml, 'application/xml')

async function unzip(file: File): Promise<Record<string, Uint8Array>> {
  const { unzipSync } = await import('fflate')
  return unzipSync(new Uint8Array(await file.arrayBuffer()))
}

const decode = (b?: Uint8Array) => (b ? new TextDecoder('utf-8').decode(b) : '')

async function readDocx(file: File) {
  const files = await unzip(file)
  const doc = xmlText(decode(files['word/document.xml']))
  const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
  const lines: { text: string; heading?: boolean; list?: boolean }[] = []
  for (const p of Array.from(doc.getElementsByTagNameNS(W, 'p'))) {
    const style = p.getElementsByTagNameNS(W, 'pStyle')[0]?.getAttributeNS(W, 'val') ?? p.getElementsByTagNameNS(W, 'pStyle')[0]?.getAttribute('w:val') ?? ''
    const text = Array.from(p.getElementsByTagNameNS(W, 't')).map((n) => n.textContent ?? '').join('')
    // Symbols used as check boxes in forms.
    const box = p.getElementsByTagNameNS(W, 'sym').length || p.getElementsByTagNameNS('http://schemas.microsoft.com/office/word/2010/wordml', 'checkbox').length
    lines.push({ text: box ? `☐ ${text}` : text, heading: /heading|title|عنوان/i.test(style), list: p.getElementsByTagNameNS(W, 'numPr').length > 0 })
  }
  return questionsFromLines(lines)
}

async function readXlsx(file: File) {
  const files = await unzip(file)
  const shared = Array.from(xmlText(decode(files['xl/sharedStrings.xml'])).getElementsByTagName('si')).map((si) => Array.from(si.getElementsByTagName('t')).map((t) => t.textContent ?? '').join(''))
  const sheetPath = Object.keys(files).filter((k) => /^xl\/worksheets\/sheet\d+\.xml$/.test(k)).sort((a, b) => Number(a.match(/\d+/)![0]) - Number(b.match(/\d+/)![0]))[0]
  const sheet = xmlText(decode(files[sheetPath]))
  const colIndex = (ref: string) => ref.replace(/\d+/g, '').split('').reduce((n, c) => n * 26 + c.charCodeAt(0) - 64, 0) - 1
  const rows: string[][] = []
  for (const row of Array.from(sheet.getElementsByTagName('row'))) {
    const cells: string[] = []
    for (const c of Array.from(row.getElementsByTagName('c'))) {
      const type = c.getAttribute('t')
      const v = c.getElementsByTagName('v')[0]?.textContent ?? ''
      const value = type === 's' ? shared[Number(v)] ?? '' : type === 'inlineStr' ? Array.from(c.getElementsByTagName('t')).map((t) => t.textContent).join('') : type === 'b' ? (v === '1' ? 'TRUE' : 'FALSE') : v
      cells[colIndex(c.getAttribute('r') ?? 'A')] = value
    }
    rows.push(Array.from(cells, (x) => x ?? ''))
  }
  return questionsFromRows(rows)
}

function parseCsv(text: string): string[][] {
  const delimiter = (text.split('\n')[0].match(/;/g)?.length ?? 0) > (text.split('\n')[0].match(/,/g)?.length ?? 0) ? ';' : text.includes('\t') ? '\t' : ','
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  for (let i = 0; i < text.length; i++) {
    const ch = text[i]
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++ } else if (ch === '"') quoted = false
      else cell += ch
    } else if (ch === '"') quoted = true
    else if (ch === delimiter) { row.push(cell); cell = '' }
    else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = '' }
    else cell += ch
  }
  row.push(cell)
  rows.push(row)
  return rows
}

export class ImportError extends Error {}

/** Reads questions from a Word, Excel, CSV or text file. */
export async function importQuestions(file: File): Promise<Question[]> {
  const name = file.name.toLowerCase()
  let questions: Question[]
  if (name.endsWith('.docx')) questions = await readDocx(file)
  else if (name.endsWith('.xlsx')) questions = await readXlsx(file)
  else if (name.endsWith('.csv') || name.endsWith('.tsv')) questions = questionsFromRows(parseCsv((await file.text()).replace(/^﻿/, '')))
  else if (name.endsWith('.txt') || name.endsWith('.md')) questions = questionsFromLines((await file.text()).split(/\r?\n/).map((text) => ({ text })))
  else throw new ImportError('format')
  if (!questions.some((q) => q.type !== 'section')) throw new ImportError('empty')
  return questions.slice(0, 150)
}

/** Excel-ready template (CSV with BOM) that the importer understands. */
export function importTemplateCsv(): string {
  const rows = [
    ['السؤال', 'النوع', 'الخيارات', 'إلزامي', 'الوصف', 'المهارة'],
    ['بيانات الاحتياج', 'قسم', '', '', 'قسم يفصل بين مجموعات الأسئلة', ''],
    ['ما مستوى تمكنك في المجالات الآتية؟', 'مصفوفة', 'إدارة الصف; التعليم المتمايز; التقويم من أجل التعلم', 'نعم', 'من 1 (مبتدئ) إلى 5 (متمكن)', ''],
    ['اختر أهم مجالات التدريب لديك', 'اختيارات متعددة', 'الذكاء الاصطناعي في التعليم; التعليم الدامج; الرفاه النفسي للطلاب', 'نعم', '', ''],
    ['ما صيغة التدريب المفضلة؟', 'اختيار واحد', 'حضوري; عن بعد; مدمج', 'نعم', '', ''],
    ['هل تستخدم أدوات الذكاء الاصطناعي؟', 'نعم/لا', '', 'نعم', '', 'الذكاء الاصطناعي في التعليم'],
    ['اقترح موضوعاً تدريبياً', 'نص طويل', '', 'لا', '', ''],
  ]
  return '﻿' + rows.map((r) => r.map((c) => (/[",\n]/.test(c) ? `"${c.replace(/"/g, '""')}"` : c)).join(',')).join('\r\n')
}

export const PRIORITY_TONE: Record<string, string> = {
  critical: 'from-red-500 to-rose-600', high: 'from-amber-400 to-orange-500', medium: 'from-sky-400 to-blue-500', low: 'from-slate-300 to-slate-400',
}

export function downloadText(filename: string, content: string, type = 'text/csv;charset=utf-8') {
  const url = URL.createObjectURL(new Blob([content], { type }))
  const a = Object.assign(document.createElement('a'), { href: url, download: filename })
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
