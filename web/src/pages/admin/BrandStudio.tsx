import clsx from 'clsx'
import { BadgeCheck, Check, CircleAlert, CircleCheck, Droplets, ExternalLink, Image as ImageIcon, LayoutTemplate, MousePointerClick, Palette, RotateCcw, Shapes, Sparkles, Type, Undo2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { useTheme } from '@/lib/ThemeProvider'
import { useSettingsTab, useTabDirty } from './settings/tabContext'
import { contrast, mergeTheme, patternImage, PRESETS, type PatternType, type Theme } from '@/lib/theme'
import BrandPreview from './brand/BrandPreview'
import { ColorField, FontField, ImageField, Segmented, SliderField } from './brand/controls'
import { dialogs } from '@/lib/dialogs'

type Section = 'presets' | 'identity' | 'typography' | 'colors' | 'buttons' | 'banners' | 'background' | 'shape'

const SECTIONS: { id: Section; icon: ComponentType<{ className?: string }> }[] = [
  { id: 'presets', icon: Sparkles },
  { id: 'identity', icon: BadgeCheck },
  { id: 'typography', icon: Type },
  { id: 'colors', icon: Palette },
  { id: 'buttons', icon: MousePointerClick },
  { id: 'banners', icon: LayoutTemplate },
  { id: 'background', icon: Shapes },
  { id: 'shape', icon: Droplets },
]

const PATTERNS: PatternType[] = ['none', 'serrated', 'dots', 'grid', 'islamic_star', 'arabesque', 'diagonal', 'custom']

/** Brand Studio: live, previewable control of the platform's visual identity. */
export default function BrandStudio() {
  const { t, i18n } = useTranslation()
  const { theme: published, setPreview, refresh } = useTheme()
  const [draft, setDraft] = useState<Theme>(published)
  const [section, setSection] = useState<Section>('presets')
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  const lang = i18n.language as 'ar' | 'en'
  const dirty = useMemo(() => JSON.stringify(draft) !== JSON.stringify(published), [draft, published])

  // Live preview across the whole app while editing; revert when leaving the studio.
  const { active } = useSettingsTab()
  useTabDirty(dirty)
  useEffect(() => {
    // Inside the settings workspace the draft is only previewed while this tab is visible.
    setPreview(active ? draft : null)
  }, [draft, active, setPreview])
  useEffect(() => () => setPreview(null), [setPreview])

  const update = <K extends keyof Theme>(key: K, patch: Partial<Theme[K]>) =>
    setDraft((d) => ({ ...d, [key]: { ...(d[key] as object), ...patch }, preset: key === 'colors' ? 'custom' : d.preset }))

  const applyPreset = (id: string) => {
    const p = PRESETS.find((x) => x.id === id)!
    setDraft((d) => ({
      ...d,
      preset: p.id,
      colors: p.colors,
      buttons: { ...d.buttons, accent_text: p.accent_text },
      banners: { ...d.banners, overlay_color: p.overlay },
      pattern: { ...d.pattern, type: p.pattern, color: p.colors.accent },
    }))
  }

  const publish = async () => {
    setSaving(true)
    setNotice(null)
    try {
      const { data } = await api.put('/admin/theme', draft)
      setDraft(mergeTheme(data.data))
      await refresh()
      setNotice({ ok: true, text: t('admin.brand.published') })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const reset = async () => {
    if (!await dialogs.confirm(t('admin.brand.confirmReset'))) return
    const { data } = await api.post('/admin/theme/reset')
    setDraft(mergeTheme(data.data))
    await refresh()
    setNotice({ ok: true, text: t('admin.brand.resetDone') })
  }

  const checks = [
    { label: t('admin.brand.contrast.text'), ratio: contrast(draft.colors.text, draft.colors.background) },
    { label: t('admin.brand.contrast.primary'), ratio: contrast('#FFFFFF', draft.colors.primary) },
    { label: t('admin.brand.contrast.accent'), ratio: contrast(draft.buttons.accent_text, draft.colors.accent) },
    { label: t('admin.brand.contrast.link'), ratio: contrast(draft.colors.link, draft.colors.surface) },
  ]

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300 shadow-glass"><Palette className="size-5" /></span>{t('admin.brand.title')}</span>}
        subtitle={t('admin.brand.subtitle')}
        actions={<>
          {dirty && <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-600/20"><span className="size-1.5 animate-pulse rounded-full bg-amber-500" />{t('admin.brand.unsaved')}</span>}
          <Button variant="ghost" icon={<RotateCcw className="size-4" />} onClick={reset}>{t('admin.brand.reset')}</Button>
          <Button variant="outline" icon={<Undo2 className="size-4" />} disabled={!dirty} onClick={() => setDraft(published)}>{t('admin.brand.discard')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={saving} disabled={!dirty} onClick={publish}>{t('admin.brand.publish')}</Button>
        </>}
      />
      {notice && <div className={clsx('mb-5 rounded-2xl p-3 text-sm font-semibold', notice.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')}>{notice.text}</div>}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
        <div className="space-y-5">
          {/* Section rail */}
          <div className="grid grid-cols-4 gap-2 sm:grid-cols-8">
            {SECTIONS.map(({ id, icon: Icon }) => (
              <button key={id} onClick={() => setSection(id)}
                className={clsx('flex flex-col items-center gap-1.5 rounded-2xl border px-2 py-3 text-xs font-bold transition',
                  section === id ? 'border-transparent bg-navy-900 text-gold-300 shadow-glass' : 'border-navy-100 bg-white text-slate-500 hover:border-gold-300 hover:text-navy-900')}>
                <Icon className="size-5" />
                <span className="text-center leading-tight">{t(`admin.brand.sections.${id}`)}</span>
              </button>
            ))}
          </div>

          <Card>
            {section === 'presets' && (
              <Panel title={t('admin.brand.sections.presets')} hint={t('admin.brand.presetsHint')}>
                <div className="grid gap-3 sm:grid-cols-2">
                  {PRESETS.map((p) => (
                    <button key={p.id} onClick={() => applyPreset(p.id)}
                      className={clsx('group relative overflow-hidden rounded-2xl border-2 text-start transition hover:-translate-y-0.5',
                        draft.preset === p.id ? 'border-gold-500 shadow-gold' : 'border-transparent ring-1 ring-navy-100')}>
                      <div className="relative h-20" style={{ background: p.colors.primary }}>
                        <div className="absolute inset-0" style={{ backgroundImage: patternImage({ type: p.pattern, color: p.colors.accent, opacity: 35, size: 22, image: null }) }} />
                        <div className="absolute bottom-3 start-3 h-1 w-10 rounded-full" style={{ background: p.colors.accent }} />
                        {draft.preset === p.id && <span className="absolute end-2 top-2 grid size-6 place-items-center rounded-full" style={{ background: p.colors.accent, color: p.accent_text }}><Check className="size-4" /></span>}
                      </div>
                      <div className="flex items-center justify-between p-3" style={{ background: p.colors.background }}>
                        <span className="text-sm font-bold" style={{ color: p.colors.text }}>{p.name[lang]}</span>
                        <span className="flex -space-x-1.5 rtl:space-x-reverse">
                          {[p.colors.primary, p.colors.accent, p.colors.link, p.colors.background].map((c, i) => <span key={`${i}-${c}`} className="size-5 rounded-full ring-2 ring-white" style={{ background: c }} />)}
                        </span>
                      </div>
                    </button>
                  ))}
                </div>
              </Panel>
            )}

            {section === 'identity' && (
              <Panel title={t('admin.brand.sections.identity')} hint={t('admin.brand.identity.hint')}>
                <a href="https://gba.gco.gov.qa/ar/guidelines/brand-identity/" target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-xl bg-ivory px-3 py-2 text-xs font-bold text-link ring-1 ring-navy-100 hover:ring-gold-400">
                  <ExternalLink className="size-3.5" />{t('admin.brand.identity.guidelines')}
                </a>
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-slate-500">{t('admin.brand.identity.nameAr')}</span>
                    <input dir="rtl" maxLength={120} className="input" value={draft.identity.name_ar} onChange={(e) => update('identity', { name_ar: e.target.value })} />
                  </label>
                  <label className="block">
                    <span className="mb-1 block text-xs font-semibold text-slate-500">{t('admin.brand.identity.nameEn')}</span>
                    <input dir="ltr" maxLength={120} className="input" value={draft.identity.name_en} onChange={(e) => update('identity', { name_en: e.target.value })} />
                  </label>
                  <p className="text-xs text-slate-400 sm:col-span-2">{t('admin.brand.identity.nameHint')}</p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <ImageField kind="logo" contain aspect="aspect-[16/7]" label={t('admin.brand.identity.logoAr')} value={draft.identity.logo_ar} onChange={(url) => update('identity', { logo_ar: url })} />
                  <ImageField kind="logo" contain aspect="aspect-[16/7]" label={t('admin.brand.identity.logoEn')} value={draft.identity.logo_en} onChange={(url) => update('identity', { logo_en: url })} />
                  <div className="rounded-2xl bg-navy-900 p-3"><ImageField kind="logo" contain onDark aspect="aspect-[16/7]" label={t('admin.brand.identity.logoArLight')} value={draft.identity.logo_ar_light} onChange={(url) => update('identity', { logo_ar_light: url })} /></div>
                  <div className="rounded-2xl bg-navy-900 p-3"><ImageField kind="logo" contain onDark aspect="aspect-[16/7]" label={t('admin.brand.identity.logoEnLight')} value={draft.identity.logo_en_light} onChange={(url) => update('identity', { logo_en_light: url })} /></div>
                </div>
                <label className="flex items-center gap-2 text-sm text-navy-800"><input type="checkbox" className="size-4 accent-[var(--color-gold-600)]" checked={draft.identity.show_center_name} onChange={(e) => update('identity', { show_center_name: e.target.checked })} />{t('admin.brand.identity.showName')}</label>
                <p className="text-xs text-slate-400">{t('admin.brand.identity.filesHint')}</p>
              </Panel>
            )}

            {section === 'typography' && (
              <Panel title={t('admin.brand.sections.typography')} hint={t('admin.brand.typography.hint')}>
                {(['arabic', 'latin'] as const).map((script) => (
                  <div key={script} className="space-y-3 rounded-2xl border border-navy-100 p-4">
                    <div className="text-sm font-bold text-navy-900">{t(`admin.brand.typography.${script}`)}</div>
                    <label className="block">
                      <span className="mb-1 block text-xs font-semibold text-slate-500">{t('admin.brand.typography.family')}</span>
                      <input dir="ltr" className="input" value={draft.typography[`${script}_family`]} onChange={(e) => update('typography', { [`${script}_family`]: e.target.value.replace(/[^\p{L}\p{N} -]/gu, '') })} />
                    </label>
                    <FontField label={t('admin.brand.typography.file')} value={draft.typography[`${script}_font_url`]} onChange={(url) => update('typography', { [`${script}_font_url`]: url })} />
                    <div className="rounded-xl bg-ivory p-4" dir={script === 'arabic' ? 'rtl' : 'ltr'} style={{ fontFamily: `"${draft.typography[`${script}_family`]}", ${script === 'arabic' ? 'Tajawal' : 'Inter'}, sans-serif` }}>
                      <div className="text-xl" style={{ fontWeight: draft.typography.heading_weight }}>{script === 'arabic' ? t('admin.brand.typography.specimenAr') : t('admin.brand.typography.specimenEn')}</div>
                      <div className="mt-1 text-sm text-slate-500">{script === 'arabic' ? 'أ ب ت ث ج ح خ د ذ ر ز س ش ص ض — 0123456789' : 'Aa Bb Cc Dd Ee Ff Gg — 0123456789'}</div>
                    </div>
                  </div>
                ))}
                <Field label={t('admin.brand.typography.headingWeight')}>
                  <Segmented value={String(draft.typography.heading_weight)} onChange={(v) => update('typography', { heading_weight: Number(v) })}
                    options={['500', '600', '700', '800', '900'].map((w) => ({ id: w, label: <span style={{ fontWeight: Number(w) }}>{w}</span> }))} />
                </Field>
              </Panel>
            )}

            {section === 'colors' && (
              <Panel title={t('admin.brand.sections.colors')}>
                <div className="grid gap-3">
                  {(['primary', 'accent', 'background', 'surface', 'text', 'link'] as const).map((k) => (
                    <ColorField key={k} label={t(`admin.brand.colors.${k}`)} hint={t(`admin.brand.colors.${k}Hint`)} value={draft.colors[k]} onChange={(v) => update('colors', { [k]: v })} />
                  ))}
                </div>
              </Panel>
            )}

            {section === 'buttons' && (
              <Panel title={t('admin.brand.sections.buttons')}>
                <Field label={t('admin.brand.buttons.style')}>
                  <Segmented value={draft.buttons.style} onChange={(v) => update('buttons', { style: v })}
                    options={(['gradient', 'solid', 'outline'] as const).map((id) => ({ id, label: t(`admin.brand.buttons.${id}`) }))} />
                </Field>
                <SliderField label={t('admin.brand.buttons.radius')} value={draft.buttons.radius} min={0} max={32} unit="px" onChange={(v) => update('buttons', { radius: v })} />
                <ColorField label={t('admin.brand.buttons.accentText')} value={draft.buttons.accent_text} onChange={(v) => update('buttons', { accent_text: v })} />
                <ColorField label={t('admin.brand.colors.link')} hint={t('admin.brand.colors.linkHint')} value={draft.colors.link} onChange={(v) => update('colors', { link: v })} />
                <label className="flex items-center gap-2 text-sm text-navy-800"><input type="checkbox" className="size-4 accent-[var(--color-gold-600)]" checked={draft.buttons.uppercase} onChange={(e) => update('buttons', { uppercase: e.target.checked })} />{t('admin.brand.buttons.uppercase')}</label>
                <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-ivory p-4">
                  <Button>{t('admin.brand.buttons.sample')}</Button>
                  <Button variant="gold">{t('admin.brand.buttons.sampleAccent')}</Button>
                  <Button variant="outline">{t('common.cancel')}</Button>
                  <span className="text-sm font-bold text-link underline-offset-4 hover:underline">{t('admin.brand.buttons.sampleLink')}</span>
                </div>
              </Panel>
            )}

            {section === 'banners' && (
              <Panel title={t('admin.brand.sections.banners')}>
                <ColorField label={t('admin.brand.banners.overlay')} value={draft.banners.overlay_color} onChange={(v) => update('banners', { overlay_color: v })} />
                <SliderField label={t('admin.brand.banners.overlayOpacity')} value={draft.banners.overlay_opacity} min={0} max={95} unit="%" onChange={(v) => update('banners', { overlay_opacity: v })} />
                <div>
                  <div className="text-sm font-bold text-navy-900">{t('admin.brand.banners.hero')}</div>
                  <p className="mb-3 text-xs text-slate-400">{t('admin.brand.banners.heroHint')}</p>
                  <div className="grid gap-4 sm:grid-cols-2">
                    {(t('admin.brand.banners.slides', { returnObjects: true }) as string[]).map((label, i) => (
                      <ImageField key={i} kind="hero" label={label} value={draft.banners.hero_images[i]}
                        onChange={(url) => update('banners', { hero_images: draft.banners.hero_images.map((x, j) => (j === i ? url : x)) })} />
                    ))}
                  </div>
                </div>
                <ImageField kind="banner" label={t('admin.brand.banners.pageBanner')} value={draft.banners.page_banner_image} aspect="aspect-[3/1]" onChange={(url) => update('banners', { page_banner_image: url })} />
                <Field label={t('admin.brand.banners.cta')}>
                  <Segmented value={draft.banners.cta_style} onChange={(v) => update('banners', { cta_style: v })}
                    options={[{ id: 'gradient' as const, label: t('admin.brand.banners.ctaGradient') }, { id: 'accent' as const, label: t('admin.brand.banners.ctaAccent') }, { id: 'image' as const, label: t('admin.brand.banners.ctaImage') }]} />
                </Field>
              </Panel>
            )}

            {section === 'background' && (
              <Panel title={t('admin.brand.sections.background')}>
                <ColorField label={t('admin.brand.colors.background')} hint={t('admin.brand.colors.backgroundHint')} value={draft.colors.background} onChange={(v) => update('colors', { background: v })} />
                <Field label={t('admin.brand.pattern.type')}>
                  <div className="grid grid-cols-4 gap-2 sm:grid-cols-7">
                    {PATTERNS.map((type) => (
                      <button key={type} onClick={() => update('pattern', { type })} className={clsx('overflow-hidden rounded-xl border-2 transition', draft.pattern.type === type ? 'border-gold-500 shadow-gold' : 'border-transparent ring-1 ring-navy-100 hover:ring-gold-300')}>
                        <div className="grid aspect-square place-items-center bg-navy-900" style={{ backgroundImage: type === 'custom' ? (draft.pattern.image ? `url("${draft.pattern.image}")` : undefined) : patternImage({ ...draft.pattern, type, opacity: Math.max(35, draft.pattern.opacity) }), backgroundSize: '18px' }}>
                          {type === 'custom' && !draft.pattern.image && <ImageIcon className="size-4 text-gold-300" />}
                        </div>
                        <div className="truncate bg-white px-1 py-1 text-[10px] font-bold text-navy-800">{t(`admin.brand.pattern.types.${type}`)}</div>
                      </button>
                    ))}
                  </div>
                </Field>
                {draft.pattern.type === 'custom' && (
                  <div className="space-y-4 rounded-2xl bg-ivory/70 p-4">
                    <p className="text-xs leading-relaxed text-slate-500">{t('admin.brand.pattern.customHint')}</p>
                    <ImageField kind="pattern" label={t('admin.brand.pattern.custom')} value={draft.pattern.image} aspect="aspect-[4/1]" contain onChange={(url) => update('pattern', { image: url })} />
                    {draft.pattern.image && (
                      <>
                        <div className="flex gap-2">
                          {(['tile', 'cover'] as const).map((r) => <button key={r} type="button" aria-pressed={(draft.pattern.repeat ?? 'tile') === r} onClick={() => update('pattern', { repeat: r })} className={clsx('flex-1 rounded-xl border px-3 py-2 text-sm font-semibold transition', (draft.pattern.repeat ?? 'tile') === r ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>{t(`admin.brand.pattern.${r}`)}</button>)}
                        </div>
                        <label className="flex cursor-pointer items-start gap-3"><input type="checkbox" className="mt-1 size-4 accent-gold-600" checked={!!draft.pattern.tint} onChange={(e) => update('pattern', { tint: e.target.checked ? draft.colors.accent : null })} /><span><span className="block text-sm font-semibold text-navy-900">{t('admin.brand.pattern.tintOn')}</span><span className="block text-xs text-slate-500">{t('admin.brand.pattern.tintHint')}</span></span></label>
                        {draft.pattern.tint && <ColorField label={t('admin.brand.pattern.tintColor')} value={draft.pattern.tint} onChange={(v) => update('pattern', { tint: v })} />}
                        <div><div className="mb-1 text-xs font-semibold text-slate-500">{t('admin.brand.pattern.previewTile')}</div><div className="pattern-bg h-24 rounded-xl border border-navy-100 bg-white" /></div>
                      </>
                    )}
                  </div>
                )}
                {draft.pattern.type !== 'none' && draft.pattern.type !== 'custom' && <ColorField label={t('admin.brand.pattern.color')} value={draft.pattern.color} onChange={(v) => update('pattern', { color: v })} />}
                {draft.pattern.type !== 'none' && <>
                  <SliderField label={t('admin.brand.pattern.opacity')} value={draft.pattern.opacity} min={0} max={100} unit="%" onChange={(v) => update('pattern', { opacity: v })} />
                  {!(draft.pattern.type === 'custom' && draft.pattern.repeat === 'cover') && <SliderField label={t('admin.brand.pattern.size')} value={draft.pattern.size} min={8} max={draft.pattern.type === 'custom' ? 400 : 120} unit="px" onChange={(v) => update('pattern', { size: v })} />}
                </>}
              </Panel>
            )}

            {section === 'shape' && (
              <Panel title={t('admin.brand.sections.shape')}>
                <SliderField label={t('admin.brand.shape.card')} value={draft.shape.card_radius} min={0} max={40} unit="px" onChange={(v) => update('shape', { card_radius: v })} />
                <SliderField label={t('admin.brand.buttons.radius')} value={draft.buttons.radius} min={0} max={32} unit="px" onChange={(v) => update('buttons', { radius: v })} />
                <SliderField label={t('admin.brand.shape.blur')} value={draft.shape.glass_blur} min={0} max={40} unit="px" onChange={(v) => update('shape', { glass_blur: v })} />
                <ColorField label={t('admin.brand.colors.surface')} hint={t('admin.brand.colors.surfaceHint')} value={draft.colors.surface} onChange={(v) => update('colors', { surface: v })} />
              </Panel>
            )}
          </Card>
        </div>

        <div className="space-y-5 xl:sticky xl:top-24 xl:self-start">
          <BrandPreview theme={draft} />
          <Card>
            <h3 className="mb-3 text-sm font-bold text-navy-900">{t('admin.brand.contrast.title')}</h3>
            <ul className="space-y-2">
              {checks.map((c) => {
                const level = c.ratio >= 7 ? 'pass' : c.ratio >= 4.5 ? 'warn' : 'fail'
                return (
                  <li key={c.label} className="flex items-center gap-3 text-sm">
                    {level === 'fail' ? <CircleAlert className="size-4 text-danger" /> : <CircleCheck className={clsx('size-4', level === 'pass' ? 'text-emerald-600' : 'text-amber-600')} />}
                    <span className="flex-1 text-slate-600">{c.label}</span>
                    <span className="font-mono text-xs text-slate-500" dir="ltr">{c.ratio.toFixed(1)}:1</span>
                    <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-bold', level === 'pass' ? 'bg-emerald-50 text-emerald-700' : level === 'warn' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-danger')}>{t(`admin.brand.contrast.${level}`)}</span>
                  </li>
                )
              })}
            </ul>
          </Card>
        </div>
      </div>
    </>
  )
}

function Panel({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <div className="space-y-5">
      <div>
        <h3 className="text-lg font-bold text-navy-900">{title}</h3>
        {hint && <p className="mt-1 text-sm text-slate-500">{hint}</p>}
        <div className="gold-line mt-3" />
      </div>
      {children}
    </div>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <div className="mb-2 text-sm font-semibold text-navy-800">{label}</div>
      {children}
    </div>
  )
}
