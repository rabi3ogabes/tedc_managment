import clsx from 'clsx'
import { Check, MonitorPlay, RotateCcw } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, PageHeader, Spinner, SaveDock } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { ImageField } from './brand/controls'
import { presetDesign } from '@/components/screen/design'
import { sampleDay } from '@/components/screen/sample'
import ScreenDesigner from './screen/ScreenDesigner'
import { DEFAULT_TEMPLATE, RoomScreenView, todayIso, type ScreenTemplate } from '../RoomScreen'

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
  const [previewIdle, setPreviewIdle] = useState(false)
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
        <SaveDock label={t('common.save')} loading={saving} disabled={!dirty} onClick={save} />
      </>} />
      {notice && <div className={clsx('rounded-xl p-3 text-sm', notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{notice.text}</div>}

      <div className="grid gap-3 sm:grid-cols-2">
        {([[false, t('studio.designer2.modeTemplate'), t('studio.designer2.modeTemplateHint')], [true, t('studio.designer2.modeFree'), t('studio.designer2.modeFreeHint')]] as const).map(([free, label, hint]) => {
          const on = !!tpl.design?.enabled === free
          return (
            <button key={String(free)} type="button" aria-pressed={on} onClick={() => setTpl({ ...tpl, design: free ? { ...(tpl.design ?? presetDesign(tpl)), enabled: true } : tpl.design ? { ...tpl.design, enabled: false } : null })} className={clsx('flex items-start gap-3 rounded-2xl border p-4 text-start transition', on ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white hover:border-gold-400')}>
              <span className={clsx('mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2', on ? 'border-gold-300 bg-gold-300 text-navy-950' : 'border-navy-200')}>{on && <Check className="size-3" />}</span>
              <span><span className="block font-bold">{label}</span><span className={clsx('block text-xs', on ? 'text-white/70' : 'text-slate-500')}>{hint}</span></span>
            </button>
          )
        })}
      </div>

      {tpl.design?.enabled ? <ScreenDesigner template={tpl} design={tpl.design} onChange={(d) => setTpl({ ...tpl, design: d })} /> : (
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

          <Section title={t('studio.tpl.idle.title')}>
            <p className="text-xs leading-relaxed text-slate-500">{t('studio.tpl.idle.hint')}</p>
            <Switch on={tpl.idle_enabled} onChange={(v) => { set('idle_enabled', v); if (v) setPreviewIdle(true) }} label={t('studio.tpl.idle.enable')} />
            {tpl.idle_enabled && (
              <>
                <Field label={t('studio.tpl.idle.titleAr')}><input dir="rtl" className="input" maxLength={160} value={tpl.idle_title_ar} onChange={(e) => set('idle_title_ar', e.target.value)} /></Field>
                <Field label={t('studio.tpl.idle.titleEn')}><input dir="ltr" className="input" maxLength={160} value={tpl.idle_title_en} onChange={(e) => set('idle_title_en', e.target.value)} /></Field>
                <Field label={t('studio.tpl.idle.textAr')}><input dir="rtl" className="input" maxLength={160} value={tpl.idle_text_ar} onChange={(e) => set('idle_text_ar', e.target.value)} /></Field>
                <Field label={t('studio.tpl.idle.textEn')}><input dir="ltr" className="input" maxLength={160} value={tpl.idle_text_en} onChange={(e) => set('idle_text_en', e.target.value)} /></Field>
                <Switch on={tpl.idle_show_next} onChange={(v) => set('idle_show_next', v)} label={t('studio.tpl.idle.showNext')} />
              </>
            )}
          </Section>

          <Section title={t('studio.tpl.bg.title')}>
            <p className="text-xs leading-relaxed text-slate-500">{t('studio.tpl.bg.hint')}</p>
            <ImageField kind="pattern" label={t('studio.tpl.bg.image')} value={tpl.bg_image || null} aspect="aspect-[3/1]" contain onChange={(url) => set('bg_image', url ?? '')} />
            {tpl.bg_image && (
              <>
                <div className="flex gap-2">{(['tile', 'cover'] as const).map((m) => <button key={m} type="button" aria-pressed={tpl.bg_mode === m} onClick={() => set('bg_mode', m)} className={clsx('flex-1 rounded-xl border px-3 py-2 text-sm font-semibold transition', tpl.bg_mode === m ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 text-navy-800 hover:border-gold-400')}>{t(`studio.tpl.bg.${m}`)}</button>)}</div>
                <Field label={`${t('studio.tpl.bg.opacity')} · ${tpl.bg_opacity}%`}><input type="range" min={0} max={100} className="w-full accent-gold-600" value={tpl.bg_opacity} onChange={(e) => set('bg_opacity', Number(e.target.value))} /></Field>
                {tpl.bg_mode === 'tile' && <Field label={`${t('studio.tpl.bg.size')} · ${tpl.bg_size}px`}><input type="range" min={16} max={400} className="w-full accent-gold-600" value={tpl.bg_size} onChange={(e) => set('bg_size', Number(e.target.value))} /></Field>}
                <label className="flex cursor-pointer items-center gap-3 text-sm font-semibold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={!!tpl.bg_tint} onChange={(e) => set('bg_tint', e.target.checked ? '#ffffff' : '')} />{t('studio.tpl.bg.tint')}{tpl.bg_tint && <input type="color" className="h-8 w-12 cursor-pointer rounded border border-navy-100 bg-white p-0.5" value={tpl.bg_tint} onChange={(e) => set('bg_tint', e.target.value)} />}</label>
              </>
            )}
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
          <div className="flex flex-wrap items-center justify-between gap-2"><div className="flex items-center gap-2 text-sm font-bold text-navy-900"><MonitorPlay className="size-5 text-gold-600" />{t('studio.tpl.preview')}</div>
            <div className="flex gap-1 rounded-xl border border-navy-100 bg-white p-1">{([[false, t('studio.tpl.idle.previewBusy')], [true, t('studio.tpl.idle.previewIdle')]] as const).map(([v, label]) => <button key={String(v)} type="button" aria-pressed={previewIdle === v} onClick={() => setPreviewIdle(v)} className={clsx('rounded-lg px-3 py-1 text-xs font-bold transition', previewIdle === v ? 'bg-navy-900 text-white' : 'text-slate-600 hover:bg-ivory')}>{label}</button>)}</div></div>
          <div ref={frame} className="relative w-full overflow-hidden rounded-2xl border border-navy-100 shadow-glass" style={{ aspectRatio: '16 / 10' }}>
            <div className="pointer-events-none absolute start-0 top-0 origin-top-left rtl:origin-top-right" style={{ width: 1440, height: 900, transform: `scale(${scale})` }}>
              <RoomScreenView preview forceIdle={previewIdle} day={day} now={now} date={todayIso()} today={todayIso()} template={tpl} />
            </div>
          </div>
          <p className="text-xs text-slate-500">{t('studio.tpl.previewHint')}</p>
        </div>
      </div>
      )}
    </div>
  )
}
