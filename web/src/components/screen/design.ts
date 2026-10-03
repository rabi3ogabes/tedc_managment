import type { Day, ScreenTemplate, Session } from '@/pages/RoomScreen'

/** The canvas the designer draws on; every element is positioned in percent of it, so any TV shows the same picture. */
export const CANVAS_W = 1920
export const CANVAS_H = 1080

export type ElementType = 'text' | 'logo' | 'clock' | 'date' | 'status' | 'progress' | 'ring' | 'trainees' | 'image' | 'shape'

export type DesignEl = {
  id: string; type: ElementType; x: number; y: number; w: number; h: number
  text: string; color: string; size: number; bold: boolean; align: 'start' | 'center' | 'end'; valign: 'start' | 'center' | 'end'
  fill: string; fill2: string; angle: number; radius: number; opacity: number; border: string; border_width: number
  src: string; columns: number; show_school: boolean; logo_dark: boolean; accent: string
}

export type DesignBackground = {
  type: 'brand' | 'solid' | 'gradient' | 'image'; color: string; color2: string; angle: number
  image: string; image_fit: 'cover' | 'tile'; image_size: number; image_opacity: number; overlay: string; overlay_opacity: number
}

export type ScreenDesign = { enabled: boolean; background: DesignBackground; live: DesignEl[]; idle: DesignEl[] }

export const uid = () => Math.random().toString(36).slice(2, 9)

export const TOKENS = ['room', 'building', 'center', 'program', 'code', 'session', 'trainer', 'start', 'end', 'time_range', 'present', 'expected', 'attendance', 'late', 'remaining', 'date', 'next_time', 'next_program', 'free_for', 'idle_title', 'idle_text', 'footer'] as const

export const defaultBackground = (): DesignBackground => ({ type: 'brand', color: '#8a1538', color2: '#3a0918', angle: 135, image: '', image_fit: 'cover', image_size: 160, image_opacity: 100, overlay: '#000000', overlay_opacity: 0 })

export function makeElement(type: ElementType, patch: Partial<DesignEl> = {}): DesignEl {
  const base: DesignEl = {
    id: uid(), type, x: 35, y: 40, w: 30, h: 10, text: '', color: '#ffffff', size: 48, bold: false, align: 'start', valign: 'center', fill: 'transparent', fill2: '', angle: 135, radius: 0, opacity: 100,
    border: '', border_width: 0, src: '', columns: 3, show_school: true, logo_dark: true, accent: '',
  }
  const defaults: Partial<Record<ElementType, Partial<DesignEl>>> = {
    text: { text: '{{program}}', size: 56, bold: true, w: 40, h: 10 },
    logo: { w: 12, h: 12 },
    clock: { size: 96, bold: true, w: 18, h: 11, align: 'end' },
    date: { size: 28, w: 24, h: 5, align: 'end', color: '#ffffffb3' },
    status: { size: 28, bold: true, w: 15, h: 6, align: 'center' },
    progress: { w: 40, h: 2.5, radius: 20, fill: '#ffffff26' },
    ring: { w: 14, h: 25 },
    trainees: { w: 40, h: 40, size: 26, columns: 2 },
    image: { w: 20, h: 20 },
    shape: { w: 30, h: 20, fill: '#ffffff14', radius: 32, border: '#ffffff26', border_width: 1 },
  }
  return { ...base, ...defaults[type], ...patch }
}

const t = (type: ElementType, x: number, y: number, w: number, h: number, patch: Partial<DesignEl> = {}) => makeElement(type, { x, y, w, h, ...patch })

/** A starting layout that looks like the built-in template, so the designer never starts from a blank page. */
export function presetDesign(tpl: ScreenTemplate): ScreenDesign {
  const live: DesignEl[] = [
    t('shape', 4, 20, 58, 66, { fill: '#ffffff12', radius: 40, border: '#ffffff26', border_width: 1 }),
    ...(tpl.show_logo ? [t('logo', 2.5, 3, 11, 11)] : []),
    ...(tpl.show_center_name ? [t('text', 14, 4, 22, 9, { text: '{{center}}', size: 30, bold: true, color: '#ffffffd9' })] : []),
    t('text', 38, 3, 34, 11, { text: '{{room}}', size: 66, bold: true, align: 'end' }),
    ...(tpl.show_clock ? [t('clock', 78, 3, 19, 11), t('date', 74, 14.5, 23, 4)] : []),
    t('status', 6, 23, 14, 6),
    t('text', 6, 31, 54, 14, { text: '{{program}}', size: 76, bold: true }),
    t('text', 6, 45, 54, 6, { text: '{{session}}', size: 34, color: '#e2c98f' }),
    t('text', 6, 53, 54, 11, { text: '{{time_range}}', size: 88, bold: true }),
    ...(tpl.show_trainer ? [t('text', 6, 66, 54, 7, { text: '{{trainer}}', size: 44, bold: true })] : []),
    ...(tpl.show_progress ? [t('text', 6, 75, 30, 4, { text: '{{remaining}}', size: 26, color: '#ffffffb3' }), t('progress', 6, 80, 54, 2.6)] : []),
    ...(tpl.show_attendance_ring ? [t('ring', 66, 20, 14, 25), t('text', 81, 24, 16, 16, { text: '{{attendance}}', size: 40, bold: true, valign: 'center' })] : []),
    ...(tpl.show_trainees ? [t('trainees', 64, 48, 33, 46, { size: 24, columns: 2, show_school: tpl.show_school })] : []),
    t('text', 4, 92, 56, 5, { text: '{{footer}}', size: 28, color: '#ffffffcc' }),
  ]
  const idle: DesignEl[] = [
    t('shape', 33, 8, 34, 38, { fill: '#ffffff12', radius: 60, border: '#ffffff26', border_width: 1 }),
    t('logo', 36, 11, 28, 32),
    t('status', 42.5, 49, 15, 5.5),
    t('text', 8, 56, 84, 13, { text: '{{idle_title}}', size: 116, bold: true, align: 'center' }),
    t('text', 8, 69, 84, 6, { text: '{{idle_text}}', size: 40, color: '#e2c98f', align: 'center' }),
    t('text', 8, 76, 84, 5, { text: '{{room}}', size: 34, color: '#ffffffb3', align: 'center' }),
    ...(tpl.idle_show_next ? [t('shape', 30, 83, 40, 11, { fill: '#ffffff12', radius: 28, border: '#ffffff26', border_width: 1 }), t('text', 30, 83, 40, 11, { text: '{{next_time}}  {{next_program}}', size: 32, align: 'center', bold: true })] : []),
  ]
  return { enabled: true, background: defaultBackground(), live, idle }
}

/** Everything the elements can show, worked out once per refresh. */
export type ScreenContext = {
  day: Day | null; featured: Session | null; upcoming: Session | null; idle: boolean; now: Date; isToday: boolean
  lang: string; centerName: string; template: ScreenTemplate
}

const hm = (d: Date) => d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })

export function buildContext(day: Day | null, now: Date, isToday: boolean, lang: string, centerName: string, template: ScreenTemplate, forceIdle = false): ScreenContext {
  const sessions = day?.sessions ?? []
  const featured = sessions.find((s) => s.state === 'live') ?? sessions.find((s) => s.state === 'next') ?? sessions.find((s) => s.state === 'upcoming') ?? sessions.at(-1) ?? null
  const upcoming = sessions.find((s) => s.state === 'next' || s.state === 'upcoming') ?? null
  const idle = forceIdle || !featured || (isToday && featured.state !== 'live')
  return { day, featured, upcoming, idle, now, isToday, lang, centerName, template }
}

export function tokenValues(c: ScreenContext, labels: { remaining: (m: number) => string; startsIn: (m: number) => string; freeFor: (t: string) => string; hm: (h: number, m: number) => string; min: (m: number) => string }): Record<string, string> {
  const f = c.featured
  const start = f ? new Date(f.starts_at) : null
  const end = f ? new Date(f.ends_at) : null
  const minutesLeft = end ? Math.max(0, Math.ceil((end.getTime() - c.now.getTime()) / 60000)) : 0
  const next = c.upcoming
  const toNext = next ? Math.max(0, Math.ceil((new Date(next.starts_at).getTime() - c.now.getTime()) / 60000)) : 0
  const ar = c.lang === 'ar'
  return {
    room: c.day?.room.name ?? '', building: [c.day?.room.building, c.day?.room.floor].filter(Boolean).join(' · '), center: c.centerName,
    program: f?.program.title ?? '', code: f?.program.code ?? '', session: f?.title ?? '', trainer: f?.trainer?.name ?? '',
    start: start ? hm(start) : '', end: end ? hm(end) : '', // Isolated left-to-right, so Arabic text around it never swaps the two times.
    time_range: start && end ? `\u2066${hm(start)} → ${hm(end)}\u2069` : '', attendance: f ? `\u2066${f.counts.present} / ${f.counts.expected}\u2069` : '',
    present: String(f?.counts.present ?? 0), expected: String(f?.counts.expected ?? 0), late: String(f?.counts.late ?? 0),
    remaining: f ? (f.state === 'live' ? labels.remaining(minutesLeft) : labels.startsIn(Math.max(0, Math.ceil(((start?.getTime() ?? 0) - c.now.getTime()) / 60000)))) : '',
    date: c.now.toLocaleDateString(ar ? 'ar' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' }),
    next_time: next ? hm(new Date(next.starts_at)) : '', next_program: next?.program.title ?? '',
    free_for: next ? labels.freeFor(toNext >= 60 ? labels.hm(Math.floor(toNext / 60), toNext % 60) : labels.min(toNext)) : '',
    idle_title: (ar ? c.template.idle_title_ar : c.template.idle_title_en) || (ar ? 'القاعة شاغرة الآن' : 'This room is empty'),
    idle_text: ar ? c.template.idle_text_ar : c.template.idle_text_en,
    footer: ar ? c.template.footer_ar : c.template.footer_en,
  }
}
