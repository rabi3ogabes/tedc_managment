import clsx from 'clsx'
import { Check, Layers, Loader2, Plus, Trash2, Wand2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'

/** A group of the programme as the form holds it. `auto` = the name still follows the suggestion from the audience. */
export type GroupDraft = { key: string; title_ar: string; title_en: string; auto: boolean }
export type Audience = { jobTitleIds: string[]; genders: string[] }
type Suggestion = { sequence: number; title_ar: string; title_en: string }
type Saved = { id: string; sequence: number; title: string; title_ar: string | null; title_en: string | null; status: string; seats_taken: number; sessions_count?: number; capacity: number }

export const newDraft = (): GroupDraft => ({ key: Math.random().toString(36).slice(2, 9), title_ar: '', title_en: '', auto: true })

/** Suggested names («معلمون 1»، «منسقات 2»، «قادة 3») for sequences 1…count from the audience; they follow it while the audience is being filled in. */
function useSuggestions(audience: Audience, count: number): Suggestion[] {
  const [s, setS] = useState<Suggestion[]>([])
  const ids = audience.jobTitleIds.join(',')
  const genders = audience.genders.join(',')
  useEffect(() => {
    let live = true
    const id = window.setTimeout(() => {
      api.post('/admin/programs/group-names', { job_title_ids: audience.jobTitleIds, genders: audience.genders, from: 1, count: Math.max(1, Math.min(30, count)) })
        .then((r) => { if (live) setS(r.data.data as Suggestion[]) }).catch(() => undefined)
    }, 300)
    return () => { live = false; window.clearTimeout(id) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ids, genders, count])
  return s
}

/**
 * The programme's groups on the form — add, rename and delete — above the target skills. A name can be suggested from the target
 * audience and numbered («معلمون 1 · منسقات 2 · قادة 3»), and an administrator can rename a group at any time. On a new programme the groups
 * are held on the form and saved with it; on an existing programme each change is saved at once.
 */
export default function GroupsCard({ programId, audience, english, rows, onRows, programme }: {
  programId?: string
  audience: Audience
  english: boolean
  rows: GroupDraft[]
  onRows: (r: GroupDraft[]) => void
  programme: { start_date: string; end_date: string; capacity: number | string }
}) {
  const { t } = useTranslation()
  return (
    <Card>
      {programId ? <SavedGroups programId={programId} audience={audience} english={english} programme={programme} /> : <DraftGroups audience={audience} english={english} rows={rows} onRows={onRows} />}
      <p className="mt-3 text-[11px] leading-5 text-slate-400">{t('admin.programs.groups.hint')}</p>
    </Card>
  )
}

function Header({ count, action }: { count: number; action: React.ReactNode }) {
  const { t } = useTranslation()
  return <CardTitle action={action}><span className="inline-flex items-center gap-2"><Layers className="size-5 text-gold-600" />{t('admin.programs.groups.title')}<Badge color="gold">{count}</Badge></span></CardTitle>
}

function NameFields({ ar, en, english, onAr, onEn, onCommit, disabled }: { ar: string; en: string; english: boolean; onAr: (v: string) => void; onEn: (v: string) => void; onCommit?: () => void; disabled?: boolean }) {
  const { t } = useTranslation()
  const key = (e: React.KeyboardEvent) => { if (e.key === 'Enter') { e.preventDefault(); onCommit?.() } }
  return (
    <div className="min-w-0 flex-1 space-y-1.5">
      <input className="input" value={ar} disabled={disabled} placeholder={String(t('admin.programs.groups.name'))} onChange={(e) => onAr(e.target.value)} onBlur={onCommit} onKeyDown={key} maxLength={255} aria-label={String(t('admin.programs.groups.name'))} />
      {english && <input className="input" dir="ltr" value={en} disabled={disabled} placeholder={String(t('admin.programs.groups.nameEn'))} onChange={(e) => onEn(e.target.value)} onBlur={onCommit} onKeyDown={key} maxLength={255} aria-label={String(t('admin.programs.groups.nameEn'))} />}
    </div>
  )
}

function IconBtn({ label, onClick, children, danger, disabled }: { label: string; onClick: () => void; children: React.ReactNode; danger?: boolean; disabled?: boolean }) {
  return <button type="button" title={label} aria-label={label} onClick={onClick} disabled={disabled} className={clsx('grid size-9 shrink-0 place-items-center rounded-xl text-slate-400 transition disabled:cursor-not-allowed disabled:opacity-40', danger ? 'hover:bg-red-50 hover:text-danger' : 'hover:bg-gold-50 hover:text-gold-700')}>{children}</button>
}

// ───────────────────────────── a new programme: the groups ride along with the form

function DraftGroups({ audience, english, rows, onRows }: { audience: Audience; english: boolean; rows: GroupDraft[]; onRows: (r: GroupDraft[]) => void }) {
  const { t } = useTranslation()
  const sugg = useSuggestions(audience, rows.length + 1)

  // Names that have not been touched follow the suggestion (and its numbering) as the audience or the number of groups changes.
  useEffect(() => {
    const cur = rows
    const next = cur.map((r, i) => (r.auto && sugg[i] && (r.title_ar !== sugg[i].title_ar || r.title_en !== sugg[i].title_en) ? { ...r, title_ar: sugg[i].title_ar, title_en: sugg[i].title_en } : r))
    if (next.some((r, i) => r !== cur[i])) onRows(next)
  }, [sugg, rows, onRows])

  const upd = (i: number, patch: Partial<GroupDraft>) => onRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const suggestAll = () => onRows(rows.map((r, i) => (sugg[i] ? { ...r, title_ar: sugg[i].title_ar, title_en: sugg[i].title_en, auto: true } : r)))
  return (
    <>
      <Header count={rows.length} action={<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => onRows([...rows, newDraft()])} aria-label={String(t('admin.programs.groups.add'))} />} />
      <ul className="space-y-3">
        {rows.map((r, i) => (
          <li key={r.key} className="flex items-start gap-2 rounded-xl bg-ivory p-2.5">
            <span className="mt-1.5 grid size-6 shrink-0 place-items-center rounded-full bg-navy-900 text-[11px] font-bold text-gold-300">{i + 1}</span>
            <NameFields ar={r.title_ar} en={r.title_en} english={english} onAr={(v) => upd(i, { title_ar: v, auto: false })} onEn={(v) => upd(i, { title_en: v, auto: false })} />
            <IconBtn label={t('admin.programs.groups.suggest')} onClick={() => sugg[i] && upd(i, { title_ar: sugg[i].title_ar, title_en: sugg[i].title_en, auto: true })}><Wand2 className="size-4" /></IconBtn>
            <IconBtn label={t('common.delete')} danger disabled={rows.length <= 1} onClick={() => onRows(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconBtn>
          </li>
        ))}
      </ul>
      <button type="button" onClick={suggestAll} className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-gold-700 hover:underline"><Wand2 className="size-3.5" />{t('admin.programs.groups.suggestAll')}</button>
    </>
  )
}

// ───────────────────────────── an existing programme: each change is saved at once

function SavedGroups({ programId, audience, english, programme }: { programId: string; audience: Audience; english: boolean; programme: { start_date: string; end_date: string; capacity: number | string } }) {
  const { t } = useTranslation()
  const list = useGet<{ data: Saved[] }>(`/admin/programs/${programId}/groups`, undefined, { staleTime: 0 })
  const groups = [...(list.data?.data ?? [])].sort((a, b) => a.sequence - b.sequence)
  const top = groups.reduce((m, g) => Math.max(m, g.sequence), 0)
  const sugg = useSuggestions(audience, top + 2)
  const [edit, setEdit] = useState<Record<string, { ar: string; en: string }>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const suggestionFor = (seq: number) => sugg.find((x) => x.sequence === seq)

  const save = useCallback(async (g: Saved, ar: string, en: string) => {
    if (ar.trim() === (g.title_ar ?? '') && (en.trim() || ar.trim()) === (g.title_en ?? '')) return
    setBusy(g.id)
    try {
      await api.put(`/admin/groups/${g.id}`, { title_ar: ar.trim() || null, title_en: en.trim() || ar.trim() || null })
      await list.refetch()
      setEdit((e) => { const n = { ...e }; delete n[g.id]; return n })
      setDone(g.id); window.setTimeout(() => setDone((d) => (d === g.id ? null : d)), 1600)
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }, [list])

  const add = async () => {
    const s = suggestionFor(top + 1)
    setBusy('new')
    try {
      await api.post(`/admin/programs/${programId}/groups`, { title_ar: s?.title_ar, title_en: s?.title_en, start_date: programme.start_date || undefined, end_date: programme.end_date || undefined, capacity: Number(programme.capacity) || undefined })
      await list.refetch()
      toast(t('admin.programs.groups.added'))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const remove = async (g: Saved) => {
    if (!(await dialogs.confirm(t('admin.programs.groups.confirmDelete', { name: g.title })))) return
    setBusy(g.id)
    try { await api.delete(`/admin/groups/${g.id}`); await list.refetch(); toast(t('admin.programs.groups.deleted')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  return (
    <>
      <Header count={groups.length} action={<Button size="sm" variant="outline" loading={busy === 'new'} icon={<Plus className="size-4" />} onClick={add} aria-label={String(t('admin.programs.groups.add'))} />} />
      <ul className="space-y-3">
        {groups.map((g) => {
          const e = edit[g.id] ?? { ar: g.title_ar ?? '', en: g.title_en ?? '' }
          const suggestion = suggestionFor(g.sequence)
          return (
            <li key={g.id} className="rounded-xl bg-ivory p-2.5">
              <div className="flex items-start gap-2">
                <span className="mt-1.5 grid size-6 shrink-0 place-items-center rounded-full bg-navy-900 text-[11px] font-bold text-gold-300">{g.sequence}</span>
                <NameFields ar={e.ar} en={e.en} english={english} disabled={busy === g.id}
                  onAr={(v) => setEdit({ ...edit, [g.id]: { ...e, ar: v } })} onEn={(v) => setEdit({ ...edit, [g.id]: { ...e, en: v } })} onCommit={() => void save(g, e.ar, e.en)} />
                <span className="mt-2 grid size-5 place-items-center">{busy === g.id ? <Loader2 className="size-4 animate-spin text-slate-400" /> : done === g.id ? <Check className="size-4 text-emerald-600" /> : null}</span>
                <IconBtn label={t('admin.programs.groups.suggest')} disabled={!suggestion} onClick={() => suggestion && setEdit({ ...edit, [g.id]: { ar: suggestion.title_ar, en: suggestion.title_en } })}><Wand2 className="size-4" /></IconBtn>
                <IconBtn label={g.seats_taken > 0 ? t('admin.programs.groups.hasRegs') : t('common.delete')} danger disabled={groups.length <= 1 || g.seats_taken > 0 || busy === g.id} onClick={() => void remove(g)}><Trash2 className="size-4" /></IconBtn>
              </div>
              <div className="mt-1.5 flex flex-wrap items-center gap-1.5 ps-8 text-[11px] text-slate-500">
                <StatusBadge status={g.status} />
                <span>{t('admin.programs.groups.seats', { n: g.seats_taken, total: g.capacity })}</span>
                <span>· {t('admin.programs.groups.sessions', { n: g.sessions_count ?? 0 })}</span>
                {!g.title_ar && <span className="text-amber-700">· {t('admin.programs.groups.unnamed')}</span>}
              </div>
            </li>
          )
        })}
      </ul>
    </>
  )
}
