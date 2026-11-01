import clsx from 'clsx'
import { CircleAlert, CircleCheck, CloudUpload, Crown, FileType2, Globe, LayoutDashboard, Plus, Smartphone, Trash2, Type } from 'lucide-react'
import { useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { addFiles, familyFromName, familyInfo, formatOf, hasFamily, italicFromName, readSfnt, safeName, weightFromName, WEIGHTS, type FontFamily, type FontFile } from '@/lib/fontLibrary'
import { lusailOn } from '@/lib/fonts'
import { FONT_CHOICES, type Theme } from '@/lib/theme'
import { Segmented } from './controls'

type Typography = Theme['typography']
type Pending = { file: File; family: string; weight: number; style: 'normal' | 'italic'; format: NonNullable<ReturnType<typeof formatOf>> }

const BUNDLED = ['Qatar Sans', 'Tajawal', 'Inter']
const MINISTRY = 'Lusail'

/**
 * Typography in the Brand Studio (docs/FONTS.md): the font library (families uploaded with every weight), the family each role uses
 * (Arabic text, English text, headings) and the heading weight. The website, the dashboard, the portal and the mobile app all follow it.
 */
export default function FontsPanel({ value, onChange }: { value: Typography; onChange: (patch: Partial<Typography>) => void }) {
  const { t } = useTranslation()
  const library = value.library ?? []
  const input = useRef<HTMLInputElement>(null)
  const [pending, setPending] = useState<Pending[] | null>(null)
  const [dragging, setDragging] = useState(false)

  const pick = async (list: FileList | File[]) => {
    const files = [...list].filter((f) => formatOf(f.name))
    if (!files.length) return
    const read = await Promise.all(files.map(async (file) => {
      const fmt = formatOf(file.name)!
      const inside = fmt === 'otf' || fmt === 'ttf' ? readSfnt(await file.arrayBuffer()) : null
      return { file, format: fmt, family: inside?.family || familyFromName(file.name), weight: inside?.weight ?? weightFromName(file.name), style: (inside?.italic || italicFromName(file.name) ? 'italic' : 'normal') as Pending['style'] }
    }))
    // Web files carry no readable name: they take the family read inside an OpenType file of the same stem (Lusail-Bold.woff2 ↔ Lusail-Bold.otf).
    const stem = (n: string) => n.replace(/\.[a-z0-9]+$/i, '').toLowerCase()
    const known = new Map(read.filter((r) => r.format === 'otf' || r.format === 'ttf').map((r) => [stem(r.file.name), r.family]))
    setPending(read.map((r) => ({ ...r, family: known.get(stem(r.file.name)) ?? r.family })))
  }

  const drop = (e: DragEvent) => { e.preventDefault(); setDragging(false); void pick(e.dataTransfer.files) }

  const setMain = (family: string) => onChange({ arabic_family: family, latin_family: family, arabic_font_url: null, latin_font_url: null })

  const remove = async (fam: FontFamily) => {
    if (!await dialogs.confirm(t('admin.brand.fonts.confirmRemove', { family: fam.family }))) return
    const fallback = fam.family === MINISTRY ? 'Qatar Sans' : MINISTRY
    const same = (f?: string | null) => (f ?? '').toLowerCase() === fam.family.toLowerCase()
    onChange({
      library: library.filter((f) => f.id !== fam.id),
      ...(same(value.arabic_family) ? { arabic_family: fallback } : {}),
      ...(same(value.latin_family) ? { latin_family: fallback } : {}),
      ...(same(value.display_family) ? { display_family: null } : {}),
    })
  }

  const main = value.arabic_family
  const mainFam = library.find((f) => f.family.toLowerCase() === main.toLowerCase())
  const mainWeights = mainFam ? familyInfo(mainFam).weights : [400, 700]
  const mainMissing = main === MINISTRY && !mainFam && !lusailOn()

  return (
    <div className="space-y-6" onDragOver={(e) => { e.preventDefault(); setDragging(true) }} onDragLeave={(e) => { if (e.currentTarget === e.target) setDragging(false) }} onDrop={drop}>
      {/* The family in force, as every visitor reads it */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass">
        <div className="pointer-events-none absolute -end-16 -top-20 size-64 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 text-xs font-bold tracking-wide text-gold-300"><Crown className="size-4" />{t('admin.brand.fonts.main')}</div>
            <div className="mt-2 text-4xl font-bold" style={{ fontFamily: `"${main}", "Qatar Sans", Tajawal, sans-serif` }}>{main}</div>
          </div>
          <div className="flex flex-wrap gap-2 text-[11px] font-bold">
            {[{ icon: Globe, label: t('admin.brand.fonts.site'), ok: !mainMissing }, { icon: LayoutDashboard, label: t('admin.brand.fonts.dashboard'), ok: !mainMissing }, { icon: Smartphone, label: t('admin.brand.fonts.app'), ok: !!mainFam && familyInfo(mainFam).app || BUNDLED.includes(main) }].map(({ icon: Icon, label, ok }) => (
              <span key={label} className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 ring-1', ok ? 'bg-emerald-400/10 text-emerald-200 ring-emerald-300/30' : 'bg-amber-400/10 text-amber-200 ring-amber-300/30')}>
                <Icon className="size-3.5" />{label}{ok ? <CircleCheck className="size-3.5" /> : <CircleAlert className="size-3.5" />}
              </span>
            ))}
          </div>
        </div>
        <p className="relative mt-4 max-w-2xl text-lg leading-9 text-white/90" style={{ fontFamily: `"${main}", "Qatar Sans", Tajawal, sans-serif` }}>{t('admin.brand.typography.specimenAr')}</p>
        <div className="relative mt-4 flex flex-wrap gap-2">
          {mainWeights.map((w) => (
            <span key={w} className="rounded-xl bg-white/8 px-3 py-2 ring-1 ring-white/10" style={{ fontFamily: `"${main}", "Qatar Sans", Tajawal, sans-serif`, fontWeight: w }}>
              <span className="text-base">شاهين Shaheen</span><span className="ms-2 font-mono text-[10px] text-gold-300" style={{ fontWeight: 400 }}>{w}</span>
            </span>
          ))}
        </div>
        {mainMissing && (
          <div className="relative mt-5 flex flex-wrap items-center gap-3 rounded-2xl bg-amber-400/10 p-3 text-sm text-amber-100 ring-1 ring-amber-300/30">
            <CircleAlert className="size-5 shrink-0 text-amber-300" />
            <span className="min-w-0 flex-1">{t('admin.brand.fonts.lusailMissing')}</span>
            <Button size="sm" variant="gold" icon={<CloudUpload className="size-4" />} onClick={() => input.current?.click()}>{t('admin.brand.fonts.uploadLusail')}</Button>
          </div>
        )}
      </section>

      {/* Which family each role uses */}
      <section className="space-y-4 rounded-2xl border border-navy-100 p-4">
        <div className="text-sm font-bold text-navy-900">{t('admin.brand.fonts.roles')}</div>
        <div className="grid gap-3 sm:grid-cols-3">
          <RoleSelect label={t('admin.brand.typography.arabic')} script="ar" value={value.arabic_family} library={library} onChange={(f) => onChange({ arabic_family: f, arabic_font_url: null })} />
          <RoleSelect label={t('admin.brand.typography.latin')} script="en" value={value.latin_family} library={library} onChange={(f) => onChange({ latin_family: f, latin_font_url: null })} />
          <RoleSelect label={t('admin.brand.fonts.headings')} script="ar" value={value.display_family ?? ''} library={library} onChange={(f) => onChange({ display_family: f || null })} same />
        </div>
        <div>
          <div className="mb-2 text-xs font-semibold text-slate-500">{t('admin.brand.typography.headingWeight')}</div>
          <Segmented value={String(value.heading_weight)} onChange={(v) => onChange({ heading_weight: Number(v) })}
            options={['500', '600', '700', '800', '900'].map((w) => ({ id: w, label: <span style={{ fontWeight: Number(w) }}>{w}</span> }))} />
        </div>
      </section>

      {/* The library */}
      <section className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div className="text-sm font-bold text-navy-900">{t('admin.brand.fonts.library')}</div>
            <p className="text-xs text-slate-500">{t('admin.brand.fonts.libraryHint')}</p>
          </div>
          <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => input.current?.click()}>{t('admin.brand.fonts.add')}</Button>
        </div>
        <input ref={input} type="file" multiple accept=".woff2,.woff,.ttf,.otf" className="hidden" onChange={(e) => { if (e.target.files) void pick(e.target.files); e.target.value = '' }} />

        <div className="grid gap-3 lg:grid-cols-2">
          {library.map((fam) => {
            const info = familyInfo(fam)
            const roles = [value.arabic_family === fam.family && t('admin.brand.typography.arabic'), value.latin_family === fam.family && t('admin.brand.typography.latin'), value.display_family === fam.family && t('admin.brand.fonts.headings')].filter(Boolean) as string[]
            return (
              <article key={fam.id} className={clsx('group rounded-2xl border bg-white p-4 shadow-sm transition hover:shadow-md', roles.length ? 'border-gold-300 ring-1 ring-gold-200' : 'border-navy-100')}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="truncate text-xl font-bold text-navy-900" style={{ fontFamily: `"${fam.family}", sans-serif` }}>{fam.family}</div>
                    <div className="mt-1 flex flex-wrap gap-1">
                      {roles.map((r) => <span key={r} className="rounded-full bg-gold-50 px-2 py-0.5 text-[10px] font-bold text-gold-800 ring-1 ring-gold-200">{r}</span>)}
                    </div>
                  </div>
                  <div className="text-4xl leading-none text-navy-900/80" style={{ fontFamily: `"${fam.family}", sans-serif` }}>أب Aa</div>
                </div>
                <p className="mt-3 line-clamp-1 text-sm text-slate-600" style={{ fontFamily: `"${fam.family}", sans-serif` }}>{t('admin.brand.typography.specimenAr')}</p>
                <div className="mt-3 flex flex-wrap items-center gap-1.5 text-[11px]">
                  {info.weights.map((w) => <span key={w} className="rounded-lg bg-navy-50 px-2 py-0.5 font-semibold text-navy-800" style={{ fontFamily: `"${fam.family}", sans-serif`, fontWeight: w }}>{t(`admin.brand.fonts.w${w}`)} {w}</span>)}
                  <span className="mx-1 h-3 w-px bg-navy-100" />
                  {info.formats.map((f) => <span key={f} className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono uppercase text-slate-500">{f}</span>)}
                </div>
                {!info.app && <p className="mt-2 flex items-center gap-1.5 text-[11px] font-semibold text-amber-700"><CircleAlert className="size-3.5" />{t('admin.brand.fonts.noApp')}</p>}
                {!info.web && <p className="mt-2 flex items-center gap-1.5 text-[11px] font-semibold text-amber-700"><CircleAlert className="size-3.5" />{t('admin.brand.fonts.noWeb')}</p>}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                  {value.arabic_family === fam.family && value.latin_family === fam.family
                    ? <span className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700"><CircleCheck className="size-4" />{t('admin.brand.fonts.isMain')}</span>
                    : <Button size="sm" variant="gold" icon={<Crown className="size-4" />} onClick={() => setMain(fam.family)}>{t('admin.brand.fonts.makeMain')}</Button>}
                  <button type="button" onClick={() => remove(fam)} className="ms-auto rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-danger" aria-label={t('admin.brand.remove')}><Trash2 className="size-4" /></button>
                </div>
              </article>
            )
          })}
          <button type="button" onClick={() => input.current?.click()} className={clsx('flex min-h-44 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition', dragging ? 'border-gold-500 bg-gold-50' : 'border-navy-100 bg-ivory/50 hover:border-gold-400 hover:bg-gold-50/40')}>
            <span className="grid size-12 place-items-center rounded-2xl bg-navy-900 text-gold-300"><CloudUpload className="size-6" /></span>
            <span className="text-sm font-bold text-navy-900">{t('admin.brand.fonts.drop')}</span>
            <span className="text-xs text-slate-500">{t('admin.brand.fonts.dropHint')}</span>
          </button>
        </div>
        {!hasFamily(library, MINISTRY) && <p className="text-xs text-slate-400">{t('admin.brand.fonts.licence')}</p>}
      </section>

      {pending && <UploadReview files={pending} onClose={() => setPending(null)} onDone={(family, files, makeMain) => {
        const next = addFiles(library, family, files)
        onChange({ library: next, ...(makeMain ? { arabic_family: family, latin_family: family, arabic_font_url: null, latin_font_url: null } : {}) })
        setPending(null)
      }} mainMissing={mainMissing || !library.length} />}
    </div>
  )
}

function RoleSelect({ label, script, value, library, onChange, same }: { label: string; script: 'ar' | 'en'; value: string; library: FontFamily[]; onChange: (family: string) => void; same?: boolean }) {
  const { t } = useTranslation()
  const choices = FONT_CHOICES.filter((f) => f.script === script && !hasFamily(library, f.family))
  const listed = !value || hasFamily(library, value) || value === MINISTRY || choices.some((c) => c.family === value)
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-semibold text-slate-500">{label}</span>
      <select className="input" value={value} onChange={(e) => onChange(e.target.value)} style={{ fontFamily: value ? `"${value}", sans-serif` : undefined }}>
        {same && <option value="">{t('admin.brand.fonts.sameAsText')}</option>}
        {library.length > 0 && <optgroup label={t('admin.brand.fonts.library')}>{library.map((f) => <option key={f.id} value={f.family}>{f.family}</option>)}</optgroup>}
        <optgroup label={t('admin.brand.fonts.builtin')}>
          {!hasFamily(library, MINISTRY) && <option value={MINISTRY}>{MINISTRY} — {t('admin.brand.fonts.ministry')}</option>}
          {choices.map((f) => <option key={f.family} value={f.family}>{f.family}{f.note ? ` (${t('apa.bundled')})` : ''}</option>)}
        </optgroup>
        {!listed && <option value={value}>{value}</option>}
      </select>
    </label>
  )
}

function UploadReview({ files, onClose, onDone, mainMissing }: { files: Pending[]; onClose: () => void; onDone: (family: string, files: FontFile[], makeMain: boolean) => void; mainMissing: boolean }) {
  const { t } = useTranslation()
  const [rows, setRows] = useState(files)
  const [family, setFamily] = useState(() => {
    const counts = new Map<string, number>()
    files.forEach((f) => counts.set(f.family, (counts.get(f.family) ?? 0) + 1))
    return [...counts.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? ''
  })
  const [makeMain, setMakeMain] = useState(mainMissing || family.toLowerCase() === MINISTRY.toLowerCase())
  const [done, setDone] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const name = safeName(family)

  const upload = async () => {
    setBusy(true)
    setError(null)
    setDone(0)
    try {
      const out: FontFile[] = []
      for (const r of rows) {
        const body = new FormData()
        body.append('file', r.file)
        body.append('kind', 'font')
        const { data } = await api.post('/admin/theme/assets', body)
        out.push({ weight: r.weight, style: r.style, format: r.format, url: data.data.url })
        setDone((d) => d + 1)
      }
      onDone(name, out, makeMain)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open wide onClose={busy ? () => {} : onClose} title={<span className="flex items-center gap-2"><Type className="size-5 text-gold-600" />{t('admin.brand.fonts.reviewTitle')}</span>}>
      <div className="space-y-4">
        <label className="block">
          <span className="mb-1 block text-xs font-semibold text-slate-500">{t('admin.brand.typography.family')}</span>
          <input dir="ltr" className="input text-lg font-bold" value={family} onChange={(e) => setFamily(e.target.value)} style={{ fontFamily: `"${name}", sans-serif` }} />
        </label>
        <div className="overflow-hidden rounded-2xl border border-navy-100">
          {rows.map((r, i) => (
            <div key={i} className="flex flex-wrap items-center gap-3 border-b border-navy-50 px-3 py-2 last:border-0">
              <FileType2 className="size-4 shrink-0 text-gold-600" />
              <span className="min-w-0 flex-1 truncate font-mono text-xs text-slate-600" dir="ltr">{r.file.name}</span>
              <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] uppercase text-slate-500">{r.format}</span>
              <select className="input !w-auto !py-1 text-xs" value={r.weight} disabled={busy} onChange={(e) => setRows((rs) => rs.map((x, k) => k === i ? { ...x, weight: Number(e.target.value) } : x))} aria-label={t('admin.brand.fonts.weight')}>
                {WEIGHTS.map((w) => <option key={w} value={w}>{t(`admin.brand.fonts.w${w}`)} · {w}</option>)}
              </select>
              <select className="input !w-auto !py-1 text-xs" value={r.style} disabled={busy} onChange={(e) => setRows((rs) => rs.map((x, k) => k === i ? { ...x, style: e.target.value as Pending['style'] } : x))} aria-label={t('admin.brand.fonts.style')}>
                <option value="normal">{t('admin.brand.fonts.normal')}</option><option value="italic">{t('admin.brand.fonts.italic')}</option>
              </select>
              <button type="button" disabled={busy} onClick={() => setRows((rs) => rs.filter((_, k) => k !== i))} className="p-1 text-slate-400 hover:text-danger" aria-label={t('admin.brand.remove')}><Trash2 className="size-4" /></button>
            </div>
          ))}
        </div>
        {!rows.some((r) => r.format === 'otf' || r.format === 'ttf') && <p className="flex items-center gap-1.5 text-xs font-semibold text-amber-700"><CircleAlert className="size-4" />{t('admin.brand.fonts.noApp')}</p>}
        <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={makeMain} onChange={(e) => setMakeMain(e.target.checked)} />{t('admin.brand.fonts.makeMainAfter', { family: name || '—' })}</label>
        {busy && <div className="h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-gold-500 transition-all" style={{ width: `${(done / rows.length) * 100}%` }} /></div>}
        {error && <p className="rounded-xl bg-red-50 p-3 text-sm font-semibold text-danger">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" disabled={busy} onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="gold" loading={busy} disabled={!name || !rows.length} icon={<CloudUpload className="size-4" />} onClick={() => void upload()}>{busy ? t('admin.brand.fonts.uploading', { done, total: rows.length }) : t('admin.brand.fonts.uploadN', { count: rows.length })}</Button>
        </div>
      </div>
    </Modal>
  )
}
