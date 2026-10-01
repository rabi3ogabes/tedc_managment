import clsx from 'clsx'
import { Check, MonitorPlay, RotateCcw, Save } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { DEFAULT_TEMPLATE, RoomScreenView, todayIso, type Day, type ScreenTemplate } from '../RoomScreen'

/** A believable live session so the template can be judged without waiting for a real class. */
function sampleDay(now: Date): Day {
  const at = (h: number, m = 0) => { const d = new Date(now); d.setHours(h, m, 0, 0); return d.toISOString() }
  const hour = now.getHours()
  const startH = Math.min(Math.max(hour - 1, 7), 11)
  const names = ['سارة المنصوري', 'خالد الكواري', 'منى النعيمي', 'أحمد الهاجري', 'نورة الكعبي', 'يوسف المري', 'هند الدوسري', 'ناصر العبيدلي']
  return {
    room: { name: 'قاعة الابتكار', code: 'R-01', building: 'المبنى الرئيسي', floor: 'الطابق الثاني', capacity: 40 }, date: todayIso(), is_today: true, now: now.toISOString(),
    sessions: [{
      id: 'sample', title: 'الجلسة الأولى', sequence: 1, mode: 'in_person', program: { code: 'LDR-101', title: 'القيادة التربوية الفعّالة' }, trainer: { name: 'د. نورة المهندي', photo: null },
      starts_at: at(startH), ends_at: at(startH + 5), minutes: 300, state: 'live', counts: { expected: names.length, present: 5, late: 1, absent: 0 },
      trainees: names.map((name, i) => ({ name, school: 'مدرسة الريان الابتدائية للبنات', status: i < 4 ? 'present' : i === 4 ? 'late' : 'expected', check_in_at: i < 5 ? at(startH, 5 + i) : null })),
    }],
  }
}

function Switch({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)} className="flex w-full items-center gap-3 rounded-xl border border-navy-100 bg-white px-3 py-2.5 text-start transition hover:border-gold-300">
      <span className={clsx('inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', on ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', on && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
      <span className="text-sm font-semibold text-navy-900">{label}</span>
    </button>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return <Card className="space-y-3"><h3 className="text-sm font-bold text-navy-900">{title}</h3>{children}</Card>
}

/** Settings → Room screen: edit the template of the classroom screens, with a live preview. */
export default function RoomScreenSettings() {
  const { t } = useTranslation()
  const { data, refetch } = useGet<{ data: ScreenTemplate }>('/admin/settings/room-screen', undefined, { staleTime: 0 })
  const [tpl, setTpl] = useState<ScreenTemplate | null>(null)
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  const [now, setNow] = useState(new Date())
  const frame = useRef<HTMLDivElement>(null)
  const [scale, setScale] = useState(0.5)
  const day = useMemo(() => sampleDay(now), [now])

  useEffect(() => { if (data && !tpl) setTpl({ ...DEFAULT_TEMPLATE, ...data.data }) }, [data, tpl])
  useEffect(() => { const id = window.setInterval(() => setNow(new Date()), 1000); return () => window.clearInterval(id) }, [])
  useEffect(() => {
    const el = frame.current
    if (!el) return
    const update = () => setScale(el.clientWidth / 1440)
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [tpl])

  if (!tpl || !data) return <Spinner />
  const dirty = JSON.stringify(tpl) !== JSON.stringify({ ...DEFAULT_TEMPLATE, ...data.data })
  const set = <K extends keyof ScreenTemplate>(k: K, v: ScreenTemplate[K]) => setTpl({ ...tpl, [k]: v })

  const save = async () => {
    setSaving(true)
    setNotice(null)
    try { await api.put('/admin/settings/room-screen', tpl); await refetch(); setNotice({ ok: true, text: t('studio.tpl.saved') }) } catch (e) { setNotice({ ok: false, text: errorMessage(e) }) } finally { setSaving(false) }
  }
  const reset = async () => {
    if (!window.confirm(t('studio.tpl.confirmReset'))) return
    try { const r = await api.post<{ data: ScreenTemplate }>('/admin/settings/room-screen/reset'); setTpl({ ...DEFAULT_TEMPLATE, ...r.data.data }); await refetch() } catch (e) { setNotice({ ok: false, text: errorMessage(e) }) }
  }

  const layouts = [['classic', t('studio.tpl.layouts.classic'), t('studio.tpl.layouts.classicHint')], ['spotlight', t('studio.tpl.layouts.spotlight'), t('studio.tpl.layouts.spotlightHint')], ['minimal', t('studio.tpl.layouts.minimal'), t('studio.tpl.layouts.minimalHint')]] as const
  const themes = [['brand', t('studio.tpl.themes.brand')], ['midnight', t('studio.tpl.themes.midnight')], ['custom', t('studio.tpl.themes.custom')]] as const

  return (
    <div className="space-y-6">
      <PageHeader title={t('mgmt.settings.sections.roomscreen.title')} subtitle={t('studio.tpl.subtitle')} actions={<>
        <Button variant="ghost" icon={<RotateCcw className="size-4" />} onClick={reset}>{t('studio.tpl.reset')}</Button>
        <Button variant="gold" loading={saving} disabled={!dirty} icon={<Save className="size-4" />} onClick={save}>{t('common.save')}</Button>
      </>} />
      {notice && <div className={clsx('rounded-xl p-3 text-sm', notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{notice.text}</div>}

      <div className="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <div className="space-y-4">
          <Section title={t('studio.tpl.layout')}>
            <div className="grid gap-2">
              {layouts.map(([k, label, hint]) => (
                <button key={k} type="button" aria-pressed={tpl.layout === k} onClick={() => set('layout', k)} className={clsx('flex items-start gap-3 rounded-xl border p-3 text-start transition', tpl.layout === k ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 hover:border-gold-400')}>
                  <span className={clsx('mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2', tpl.layout === k ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-200')}>{tpl.layout === k && <Check className="size-3" />}</span>
                  <span><span className="block text-sm font-bold text-navy-900">{label}</span><span className="block text-xs text-slate-500">{hint}</span></span>
                </button>
              ))}
            </div>
          </Section>

          <Section title={t('studio.tpl.look')}>
            <div className="grid grid-cols-3 gap-2">
              {themes.map(([k, label]) => <button key={k} type="button" aria-pressed={tpl.theme === k} onClick={() => set('theme', k)} className={clsx('rounded-xl border px-2 py-2 text-xs font-bold transition', tpl.theme === k ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 text-navy-800 hover:border-gold-400')}>{label}</button>)}
            </div>
            {tpl.theme === 'brand' && <p className="text-xs text-slate-500">{t('studio.tpl.brandHint')}</p>}
            {tpl.theme === 'custom' && <Field label={t('studio.tpl.background')}><div className="flex items-center gap-2"><input type="color" className="h-10 w-14 cursor-pointer rounded-lg border border-navy-100 bg-white p-1" value={tpl.background || '#8a1538'} onChange={(e) => set('background', e.target.value)} /><span className="font-mono text-xs text-slate-500" dir="ltr">{tpl.background || '#8a1538'}</span></div></Field>}
            <Field label={t('studio.tpl.accent')} hint={t('studio.tpl.accentHint')}><div className="flex items-center gap-2"><input type="color" className="h-10 w-14 cursor-pointer rounded-lg border border-navy-100 bg-white p-1" value={tpl.accent || '#a29475'} onChange={(e) => set('accent', e.target.value)} />{tpl.accent ? <button type="button" className="text-xs font-semibold text-link" onClick={() => set('accent', '')}>{t('studio.tpl.useBrand')}</button> : <span className="text-xs text-slate-500">{t('studio.tpl.fromBrand')}</span>}</div></Field>
          </Section>

          <Section title={t('studio.tpl.show')}>
            <div className="grid gap-2">
              <Switch on={tpl.show_logo} onChange={(v) => set('show_logo', v)} label={t('studio.tpl.logo')} />
              <Switch on={tpl.show_center_name} onChange={(v) => set('show_center_name', v)} label={t('studio.tpl.centerName')} />
              <Switch on={tpl.show_clock} onChange={(v) => set('show_clock', v)} label={t('studio.tpl.clock')} />
              <Switch on={tpl.show_trainer} onChange={(v) => set('show_trainer', v)} label={t('studio.tpl.trainer')} />
              <Switch on={tpl.show_progress} onChange={(v) => set('show_progress', v)} label={t('studio.tpl.progress')} />
              <Switch on={tpl.show_attendance_ring} onChange={(v) => set('show_attendance_ring', v)} label={t('studio.tpl.ring')} />
              <Switch on={tpl.show_trainees} onChange={(v) => set('show_trainees', v)} label={t('studio.tpl.trainees')} />
              <Switch on={tpl.show_school} onChange={(v) => set('show_school', v)} label={t('studio.tpl.school')} />
            </div>
          </Section>

          <Section title={t('studio.tpl.footer')}>
            <Field label={t('studio.tpl.footerAr')}><input dir="rtl" className="input" maxLength={200} value={tpl.footer_ar} onChange={(e) => set('footer_ar', e.target.value)} /></Field>
            <Field label={t('studio.tpl.footerEn')}><input dir="ltr" className="input" maxLength={200} value={tpl.footer_en} onChange={(e) => set('footer_en', e.target.value)} /></Field>
            <p className="text-xs text-slate-500">{t('studio.tpl.footerHint')}</p>
          </Section>
        </div>

        <div className="min-w-0 space-y-3 xl:sticky xl:top-4 xl:self-start">
          <div className="flex items-center gap-2 text-sm font-bold text-navy-900"><MonitorPlay className="size-5 text-gold-600" />{t('studio.tpl.preview')}</div>
          <div ref={frame} className="relative w-full overflow-hidden rounded-2xl border border-navy-100 shadow-glass" style={{ aspectRatio: '16 / 10' }}>
            <div className="pointer-events-none absolute start-0 top-0 origin-top-left rtl:origin-top-right" style={{ width: 1440, height: 900, transform: `scale(${scale})` }}>
              <RoomScreenView preview day={day} now={now} date={todayIso()} today={todayIso()} template={tpl} />
            </div>
          </div>
          <p className="text-xs text-slate-500">{t('studio.tpl.previewHint')}</p>
        </div>
      </div>
    </div>
  )
}
