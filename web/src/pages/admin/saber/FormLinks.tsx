import clsx from 'clsx'
import { BarChart3, ClipboardCheck, Copy, Download, ExternalLink, FileSpreadsheet, Lock, NotebookPen, Pencil, Printer, QrCode, Search, Unlock } from 'lucide-react'
import { QRCodeCanvas, QRCodeSVG } from 'qrcode.react'
import { useRef, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

export type TraineeForm = { url: string; enabled: boolean; status: 'open' | 'scheduled' | 'closed' | 'off'; opens_at: string | null; mode: string; expected: number; answered: number }
export type ReflectionForm = { url: string; enabled: boolean; status: 'waiting' | 'open' | 'done' | 'off'; trainers: number; assigned: number; answered: number; groups: number; groups_done: number; form: { id: string; title: string } }
export type Forms = { trainee: TraineeForm; reflection: ReflectionForm }
type Head = { id: string; code: string; title: string; start_date: string | null; end_date: string | null; status: string }
type Row = Forms & { program: Head }

const tone = { open: 'bg-emerald-50 text-emerald-700 ring-emerald-200', done: 'bg-emerald-50 text-emerald-700 ring-emerald-200', scheduled: 'bg-sky-50 text-sky-700 ring-sky-200', waiting: 'bg-amber-50 text-amber-700 ring-amber-200', closed: 'bg-slate-100 text-slate-600 ring-slate-200', off: 'bg-slate-100 text-slate-500 ring-slate-200' } as const

/** The status of one form in a word, with the date when it is scheduled. */
function StatusPill({ kind, form }: { kind: 'trainee' | 'reflection'; form: TraineeForm | ReflectionForm }) {
  const { t } = useTranslation()
  const label = kind === 'trainee' && form.status === 'open' && (form as TraineeForm).mode === 'always' ? t('saber.forms.status.always')
    : form.status === 'scheduled' && (form as TraineeForm).opens_at ? t('saber.forms.status.opensAt', { date: fmt.date((form as TraineeForm).opens_at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) })
      : t(`saber.forms.status.${form.status}`)
  return <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset', tone[form.status])}><span className="size-1.5 rounded-full bg-current" />{label}</span>
}

const copy = async (url: string, done: string) => { try { await navigator.clipboard.writeText(url); toast(done) } catch { toast(url, 'info') } }

/**
 * One form of a programme: where it stands, how many answered, its link (copy, open), its QR code (download) and what the person may do
 * about it — open or close the trainee survey, see the results, edit the self-reflection form.
 */
function FormCard({ kind, form, program, onChanged }: { kind: 'trainee' | 'reflection'; form: TraineeForm | ReflectionForm; program: Head; onChanged: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const qr = useRef<HTMLDivElement>(null)
  const [busy, setBusy] = useState(false)
  const Icon = kind === 'trainee' ? ClipboardCheck : NotebookPen
  const trainee = kind === 'trainee' ? (form as TraineeForm) : null
  const reflection = kind === 'reflection' ? (form as ReflectionForm) : null
  const of = trainee ? trainee.expected : reflection!.assigned
  const pct = of > 0 ? Math.round((form.answered / of) * 100) : 0

  const download = () => {
    const c = qr.current?.querySelector('canvas')
    if (!c) return
    const a = document.createElement('a')
    a.href = c.toDataURL('image/png'); a.download = `${kind === 'trainee' ? 'trainee' : 'reflection'}-${program.code}.png`; a.click()
  }
  const survey = async (action: 'open' | 'close') => {
    setBusy(true)
    try { await api.post(`/admin/programs/${program.id}/survey/${action}`, action === 'open' ? { notify: true } : {}); toast(t(action === 'open' ? 'saber.forms.opened' : 'saber.forms.closedMsg')); onChanged() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <section className={clsx('flex flex-col rounded-2xl border bg-white p-4 shadow-sm', form.status === 'off' ? 'border-slate-200 opacity-80' : 'border-navy-100')}>
      <header className="flex items-start gap-3">
        <span className={clsx('grid size-11 shrink-0 place-items-center rounded-2xl ring-1 ring-inset', kind === 'trainee' ? 'bg-gradient-to-br from-gold-50 to-ivory text-gold-700 ring-gold-200' : 'bg-gradient-to-br from-navy-50 to-white text-navy-700 ring-navy-100')}><Icon className="size-5" /></span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-extrabold text-navy-900">{t(`saber.forms.${kind}.title`)}</span>
          <span className="block text-[11px] leading-relaxed text-slate-500">{reflection ? reflection.form.title : t('saber.forms.trainee.hint')}</span>
        </span>
        <StatusPill kind={kind} form={form} />
      </header>

      <div className="mt-3">
        <div className="flex items-baseline justify-between text-[11px] font-semibold text-slate-500">
          <span>{reflection && reflection.status === 'waiting' ? t('saber.forms.reflection.waitingLine', { done: reflection.groups_done, total: reflection.groups, trainers: reflection.trainers }) : t('saber.forms.answered', { n: form.answered, of })}</span>
          {of > 0 && <span className="tabular-nums text-navy-900">{pct}%</span>}
        </div>
        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className={clsx('h-full rounded-full transition-all', kind === 'trainee' ? 'bg-gold-500' : 'bg-navy-600')} style={{ width: `${pct}%` }} /></div>
      </div>

      <div className="mt-3 flex items-center gap-1 rounded-xl border border-navy-100 bg-ivory/60 py-1 pe-1 ps-3">
        <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-navy-800" dir="ltr">{form.url}</span>
        <button type="button" onClick={() => void copy(form.url, t('saber.forms.copied'))} title={t('saber.forms.copy')} aria-label={t('saber.forms.copy')} className="grid size-8 place-items-center rounded-lg text-slate-500 transition hover:bg-white hover:text-navy-900"><Copy className="size-4" /></button>
        <a href={form.url} target="_blank" rel="noopener noreferrer" title={t('saber.forms.open')} aria-label={t('saber.forms.open')} className="grid size-8 place-items-center rounded-lg text-slate-500 transition hover:bg-white hover:text-navy-900"><ExternalLink className="size-4" /></a>
      </div>

      <div className="mt-3 flex items-center gap-3">
        <div ref={qr} className="shrink-0 rounded-xl bg-white p-2 ring-1 ring-navy-100"><QRCodeCanvas value={form.url} size={112} marginSize={1} /></div>
        <div className="min-w-0 space-y-2">
          <p className="text-[11px] leading-relaxed text-slate-500">{t(`saber.forms.${kind}.qrHint`)}</p>
          <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={download}>{t('saber.forms.downloadQr')}</Button>
        </div>
      </div>

      <footer className="mt-4 flex flex-wrap gap-2 border-t border-navy-50 pt-3">
        {trainee && can('programs.manage') && trainee.enabled && trainee.status !== 'open' && <Button size="sm" variant="gold" loading={busy} icon={<Unlock className="size-4" />} onClick={() => void survey('open')}>{t('saber.forms.openNow')}</Button>}
        {trainee && can('programs.manage') && trainee.status === 'open' && trainee.mode !== 'always' && <Button size="sm" variant="outline" loading={busy} icon={<Lock className="size-4" />} onClick={() => void survey('close')}>{t('saber.forms.close')}</Button>}
        {(can('evaluations.manage') || can('evaluation_reports.prepare') || can('impact.view')) && <Button size="sm" variant="ghost" icon={<BarChart3 className="size-4" />} to={`/admin/programs/${program.id}?tab=evaluation`}>{t('saber.forms.results')}</Button>}
        {reflection && can('evaluations.manage') && <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} to="/admin/evaluation-forms">{t('saber.forms.editForm')}</Button>}
      </footer>
    </section>
  )
}

/** Hidden QR codes of the given programmes, printed as an A4 sheet with one card per programme (both forms). */
function usePrintSheet() {
  const { t, i18n } = useTranslation()
  const box = useRef<HTMLDivElement>(null)
  const sheet = (rows: { program: Head; trainee: { url: string }; reflection: { url: string } }[]) => (
    <div ref={box} className="hidden" aria-hidden>
      {rows.map((r) => (
        <div key={r.program.id} data-program={r.program.id}>
          <div data-kind="trainee"><QRCodeSVG value={r.trainee.url} size={150} marginSize={1} /></div>
          <div data-kind="reflection"><QRCodeSVG value={r.reflection.url} size={150} marginSize={1} /></div>
        </div>
      ))}
    </div>
  )
  const print = (rows: { program: Head; trainee: { url: string }; reflection: { url: string } }[]) => {
    const w = window.open('', '_blank')
    if (!w || !box.current) { toast(t('saber.forms.popup'), 'error'); return }
    const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!)
    const svg = (id: string, kind: string) => box.current!.querySelector(`[data-program="${id}"] [data-kind="${kind}"]`)?.innerHTML ?? ''
    const cards = rows.map((r) => `
      <section class="card">
        <h2>${esc(r.program.title)}</h2><p class="code">${esc(r.program.code)}</p>
        <div class="pair">
          <figure>${svg(r.program.id, 'trainee')}<figcaption>${esc(t('saber.forms.trainee.title'))}</figcaption><small>${esc(r.trainee.url)}</small></figure>
          <figure>${svg(r.program.id, 'reflection')}<figcaption>${esc(t('saber.forms.reflection.title'))}</figcaption><small>${esc(r.reflection.url)}</small></figure>
        </div>
        <p class="hint">${esc(t('saber.forms.printHint'))}</p>
      </section>`).join('')
    const rtl = i18n.dir() === 'rtl'
    w.document.write(`<!doctype html><html lang="${i18n.language}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><title>${esc(t('saber.forms.sheetTitle'))}</title>
      <style>
        @page { size: A4; margin: 12mm }
        * { box-sizing: border-box } body { margin: 0; font-family: 'Segoe UI', Tahoma, sans-serif; color: #1f2a44 }
        .card { break-inside: avoid; border: 1px solid #e6d9b8; border-radius: 16px; padding: 16px 20px; margin-bottom: 14px }
        h2 { margin: 0; font-size: 17px; color: #8a1538 } .code { margin: 2px 0 10px; font: 12px monospace; color: #64748b; direction: ltr; text-align: ${rtl ? 'right' : 'left'} }
        .pair { display: flex; gap: 24px; justify-content: space-around } figure { margin: 0; text-align: center; width: 45% }
        figcaption { margin-top: 6px; font-weight: 700; font-size: 13px } small { display: block; margin-top: 2px; font: 9px monospace; color: #94a3b8; direction: ltr; word-break: break-all }
        .hint { margin: 10px 0 0; font-size: 11px; color: #64748b; text-align: center }
      </style></head><body>${cards}</body></html>`)
    w.document.close()
    w.focus()
    setTimeout(() => w.print(), 250)
  }
  return { sheet, print }
}

/** «استمارات البرنامج»: the programme's trainee survey and trainer self-reflection, side by side, with a printable QR card. */
export function ProgramFormsModal({ programId, onClose }: { programId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, refetch } = useGet<{ data: Row }>(`/admin/saber/programs/${programId}/forms`, undefined, { staleTime: 0 })
  const row = data?.data
  const { sheet, print } = usePrintSheet()

  return (
    <Modal open wide onClose={onClose} title={row ? <span className="flex items-center gap-2">{t('saber.forms.title')}<span className="text-sm font-semibold text-slate-500">· {row.program.title}</span></span> : t('saber.forms.title')}>
      {!row ? <div className="grid gap-4 md:grid-cols-2"><div className="h-80 animate-pulse rounded-2xl bg-ivory" /><div className="h-80 animate-pulse rounded-2xl bg-ivory" /></div> : (
        <div className="space-y-4">
          <p className="text-xs leading-relaxed text-slate-500">{t('saber.forms.intro')}</p>
          <div className="grid gap-4 md:grid-cols-2">
            <FormCard kind="trainee" form={row.trainee} program={row.program} onChanged={() => void refetch()} />
            <FormCard kind="reflection" form={row.reflection} program={row.program} onChanged={() => void refetch()} />
          </div>
          <div className="flex justify-end"><Button variant="outline" icon={<Printer className="size-4" />} onClick={() => print([row])}>{t('saber.forms.printCard')}</Button></div>
          {sheet([row])}
        </div>
      )}
    </Modal>
  )
}

/** The workbench chip of a programme: its two forms at a glance; opens «استمارات البرنامج». */
export function FormsChip({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white/70 px-2.5 py-1.5 text-xs font-semibold text-navy-900 transition hover:border-gold-300 hover:bg-white">
        <QrCode className="size-4 text-gold-600" />{t('saber.forms.chip')}
      </button>
      {open && <ProgramFormsModal programId={programId} onClose={() => setOpen(false)} />}
    </>
  )
}

/** The two forms of a programme in the details window: status and a link each. */
export function FormsSummary({ forms }: { forms: Forms }) {
  const { t } = useTranslation()
  return (
    <span className="flex flex-col gap-1.5">
      {(['trainee', 'reflection'] as const).map((k) => (
        <span key={k} className="flex flex-wrap items-center gap-2">
          <a href={forms[k].url} target="_blank" rel="noopener noreferrer" className="font-semibold text-navy-700 underline decoration-gold-400 underline-offset-2">{t(`saber.forms.${k}.title`)}</a>
          <StatusPill kind={k} form={forms[k]} />
        </span>
      ))}
    </span>
  )
}

const WHEN = ['all', 'running', 'upcoming', 'ended'] as const

/** «استمارات التقييم» (data tools): every programme with its two generated links — search, filter, copy, QR, export and a printable sheet. */
export function FormsDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [when, setWhen] = useState<(typeof WHEN)[number]>('all')
  const [page, setPage] = useState(1)
  const [openId, setOpenId] = useState<string | null>(null)
  const params = { q: q.trim() || undefined, when, page }
  const { data, isFetching } = useGet<{ data: Row[]; meta: { total: number; page: number; last_page: number } }>('/admin/saber/forms', params, { staleTime: 15_000, placeholderData: (prev) => prev })
  const rows = data?.data ?? []
  const { sheet, print } = usePrintSheet()

  return (
    <Modal open wide onClose={onClose} title={t('saber.forms.listTitle')}>
      <div className="space-y-3">
        <p className="rounded-xl bg-gradient-to-l from-gold-50/80 to-ivory px-4 py-3 text-xs leading-relaxed text-navy-800 ring-1 ring-inset ring-gold-200">{t('saber.forms.listIntro')}</p>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex h-10 min-w-56 flex-1 items-center gap-2 rounded-xl border border-navy-100 bg-white px-3 focus-within:border-gold-400"><Search className="size-4 text-slate-400" /><input value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('saber.forms.search')} className="w-full bg-transparent text-sm outline-none" /></label>
          <div className="flex rounded-xl border border-navy-100 bg-white p-0.5">
            {WHEN.map((w) => <button key={w} type="button" aria-pressed={when === w} onClick={() => { setWhen(w); setPage(1) }} className={clsx('rounded-lg px-3 py-1.5 text-xs font-bold transition', when === w ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}>{t(`saber.forms.when.${w}`)}</button>)}
          </div>
          <Button size="sm" variant="outline" icon={<FileSpreadsheet className="size-4" />} onClick={() => void downloadFile(`/admin/saber/forms/export?${new URLSearchParams({ when, ...(q.trim() ? { q: q.trim() } : {}) })}`, 'saber-forms.xlsx').catch((e) => toast(errorMessage(e), 'error'))}>{t('saber.forms.export')}</Button>
          <Button size="sm" variant="outline" icon={<Printer className="size-4" />} disabled={!rows.length} onClick={() => print(rows)}>{t('saber.forms.printPage')}</Button>
        </div>

        <div className={clsx('overflow-hidden rounded-2xl border border-navy-100 bg-white transition-opacity', isFetching && 'opacity-70')}>
          {!data ? <div className="h-64 animate-pulse bg-ivory" /> : rows.length === 0 ? <p className="py-14 text-center text-sm text-slate-400">{t('saber.forms.none')}</p> : (
            <ul className="divide-y divide-navy-50">
              {rows.map((r) => (
                <li key={r.program.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                  <button type="button" onClick={() => setOpenId(r.program.id)} className="min-w-48 flex-1 text-start">
                    <span className="block truncate text-sm font-bold text-navy-900 hover:text-gold-700">{r.program.title}</span>
                    <span className="block text-[11px] text-slate-400"><span className="font-mono" dir="ltr">{r.program.code}</span>{r.program.start_date ? ` · ${fmt.date(r.program.start_date)} – ${fmt.date(r.program.end_date)}` : ''}</span>
                  </button>
                  <LinkCell icon={ClipboardCheck} label={t('saber.forms.trainee.short')} form={r.trainee} kind="trainee" count={`${r.trainee.answered}/${r.trainee.expected}`} />
                  <LinkCell icon={NotebookPen} label={t('saber.forms.reflection.short')} form={r.reflection} kind="reflection" count={`${r.reflection.answered}/${r.reflection.assigned}`} />
                </li>
              ))}
            </ul>
          )}
        </div>

        {data && data.meta.last_page > 1 && (
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>{t('saber.forms.total', { n: data.meta.total })}</span>
            <span className="flex items-center gap-2">
              <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('saber.forms.prev')}</Button>
              <span className="tabular-nums">{page} / {data.meta.last_page}</span>
              <Button size="sm" variant="ghost" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)}>{t('saber.forms.next')}</Button>
            </span>
          </div>
        )}
        {sheet(rows)}
      </div>
      {openId && <ProgramFormsModal programId={openId} onClose={() => setOpenId(null)} />}
    </Modal>
  )
}

function LinkCell({ icon: Icon, label, form, kind, count }: { icon: ComponentType<{ className?: string }>; label: string; form: TraineeForm | ReflectionForm; kind: 'trainee' | 'reflection'; count: ReactNode }) {
  const { t } = useTranslation()
  return (
    <span className="flex items-center gap-2 rounded-xl bg-ivory/70 px-2.5 py-1.5">
      <Icon className="size-4 text-gold-600" />
      <span className="flex flex-col">
        <span className="text-[11px] font-bold text-navy-900">{label} <span className="font-semibold tabular-nums text-slate-500">{count}</span></span>
        <StatusPill kind={kind} form={form} />
      </span>
      <button type="button" onClick={() => void copy(form.url, t('saber.forms.copied'))} title={t('saber.forms.copy')} aria-label={`${t('saber.forms.copy')} — ${label}`} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-white hover:text-navy-900"><Copy className="size-3.5" /></button>
    </span>
  )
}
