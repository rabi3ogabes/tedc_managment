import clsx from 'clsx'
import { AlertTriangle, ArrowLeftRight, ArrowRight, Building2, CheckCircle2, Download, FileSpreadsheet, MinusCircle, PlusCircle, ShieldCheck } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useRef, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { refreshRoomLists } from '@/lib/rooms'
import { toast } from '@/lib/toast'

type Field = 'name' | 'name_en' | 'code' | 'room_type' | 'tag' | 'capacity' | 'building' | 'floor' | 'status'
type Value = string | number | null
type AddRow = { key: string; name: string; name_en?: string | null; code?: string | null; room_type: 'training' | 'meeting'; tag?: string | null; capacity?: number | null; building?: string | null; floor?: string | null; exists_code?: boolean }
type UpdateRow = { id: string; name: string; changes: Partial<Record<Field, { from: Value; to: Value; conflict?: boolean }>> }
type RemoveRow = { id: string; name: string; room_type: string; action: 'delete' | 'deactivate'; upcoming: boolean; sessions: number; upcoming_sessions: number; bookings: number; upcoming_bookings: number; groups: number; drafts: number }
type Plan = { source: 'grid' | 'list'; file_rooms: number; add: AddRow[]; update: UpdateRow[]; same: { id: string; name: string }[]; remove: RemoveRow[]; kept: { id: string; name: string }[]; inactive: number }

const toggle = <T,>(set: Set<T>, v: T) => { const n = new Set(set); if (n.has(v)) n.delete(v); else n.add(v); return n }

/**
 * «مزامنة القاعات من ملف» (docs/saber/06-rooms-sync.md): the rooms calendar workbook or a room list → a preview of the rooms added, the
 * changes per room (each one can be left out) and the rooms no longer in the file (deleted when never used, otherwise deactivated) → apply.
 */
export default function RoomSyncDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [plan, setPlan] = useState<Plan | null>(null)
  const [busy, setBusy] = useState(false)
  const [adds, setAdds] = useState<Set<string>>(new Set())
  const [fields, setFields] = useState<Record<string, Set<Field>>>({})
  const [removes, setRemoves] = useState<Set<string>>(new Set())
  const input = useRef<HTMLInputElement>(null)
  const qc = useQueryClient()

  const read = async (file: File) => {
    setBusy(true)
    try {
      const body = new FormData(); body.append('file', file)
      const p: Plan = (await api.post('/admin/rooms/sync/preview', body)).data.data
      setPlan(p)
      setAdds(new Set(p.add.map((a) => a.key)))
      // Every change is chosen except a code another room already holds; a room with upcoming use is only removed when ticked on purpose.
      setFields(Object.fromEntries(p.update.map((u) => [u.id, new Set((Object.keys(u.changes) as Field[]).filter((f) => !u.changes[f]?.conflict))])))
      setRemoves(new Set(p.remove.filter((r) => !r.upcoming).map((r) => r.id)))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const changed = plan ? plan.update.filter((u) => (fields[u.id]?.size ?? 0) > 0) : []
  const total = adds.size + changed.length + removes.size
  const show = (f: Field, v: Value) => v === null || v === '' ? '—' : f === 'room_type' ? t(`mgmt.rooms.sync.types.${v}`, { defaultValue: String(v) }) : f === 'status' ? t(`mgmt.rooms.sync.statuses.${v}`) : f === 'capacity' ? fmt.number(Number(v)) : String(v)

  const apply = async () => {
    if (!plan || !total) return
    const chosen = plan.remove.filter((r) => removes.has(r.id))
    const del = chosen.filter((r) => r.action === 'delete').length
    if (chosen.length && !await dialogs.confirm(t('mgmt.rooms.sync.confirm', { del, off: chosen.length - del }))) return
    setBusy(true)
    try {
      const r = (await api.post('/admin/rooms/sync/apply', {
        add: plan.add.filter((a) => adds.has(a.key)).map(({ key: _key, exists_code: _taken, ...room }) => room),
        update: changed.map((u) => ({ id: u.id, changes: Object.fromEntries([...fields[u.id]].map((f) => [f, u.changes[f]!.to])) })),
        remove: chosen.map((x) => x.id),
      })).data.data
      toast(t('mgmt.rooms.sync.done', { added: r.added, updated: r.updated, deleted: r.deleted, deactivated: r.deactivated }))
      if (r.skipped?.length) toast(t('mgmt.rooms.sync.skipped', { names: r.skipped.join('، ') }), 'info')
      void refreshRoomLists(qc)
      onDone(); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <Modal open wide onClose={onClose} title={<span className="inline-flex items-center gap-2"><Building2 className="size-5 text-gold-600" />{t('mgmt.rooms.sync.title')}</span>}>
      {!plan ? (
        <div className="space-y-4">
          <button type="button" disabled={busy} onClick={() => input.current?.click()} className="flex w-full flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-gold-300 bg-gradient-to-br from-gold-50/70 to-ivory px-6 py-10 transition hover:border-gold-400 hover:shadow-sm disabled:opacity-70">
            <span className="grid size-12 place-items-center rounded-2xl bg-white text-gold-600 shadow-sm">{busy ? <span className="size-5 animate-spin rounded-full border-2 border-gold-500 border-t-transparent" /> : <FileSpreadsheet className="size-6" />}</span>
            <span className="text-sm font-bold text-navy-900">{busy ? t('mgmt.rooms.sync.reading') : t('mgmt.rooms.sync.drop')}</span>
            <span className="text-xs text-slate-400">.xlsx · .xls · .csv</span>
          </button>
          <input ref={input} type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) void read(f); e.target.value = '' }} />
          <p className="text-xs leading-relaxed text-slate-500">{t('mgmt.rooms.sync.dropHint')}</p>
          <Button variant="outline" size="sm" icon={<Download className="size-4" />} onClick={() => void downloadFile('/admin/rooms/sync/template', 'rooms-template.xlsx').catch((e) => toast(errorMessage(e), 'error'))}>{t('mgmt.rooms.sync.template')}</Button>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-navy-50 px-3 py-1 text-xs font-bold text-navy-800"><FileSpreadsheet className="size-3.5" />{t(`mgmt.rooms.sync.source.${plan.source}`, { n: plan.file_rooms })}</span>
            <button type="button" className="text-xs font-bold text-navy-700 hover:text-gold-700" onClick={() => setPlan(null)}>{t('mgmt.rooms.sync.another')}</button>
          </div>

          <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <Stat icon={PlusCircle} tone="emerald" n={plan.add.length} label={t('mgmt.rooms.sync.summary.add')} />
            <Stat icon={ArrowLeftRight} tone="gold" n={plan.update.length} label={t('mgmt.rooms.sync.summary.update')} />
            <Stat icon={MinusCircle} tone="rose" n={plan.remove.length} label={t('mgmt.rooms.sync.summary.remove')} />
            <Stat icon={CheckCircle2} tone="slate" n={plan.same.length} label={t('mgmt.rooms.sync.summary.same')} />
            <Stat icon={ShieldCheck} tone="sky" n={plan.kept.length} label={t('mgmt.rooms.sync.summary.kept')} />
          </div>

          {!plan.add.length && !plan.update.length && !plan.remove.length && <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{t('mgmt.rooms.sync.nothing')}</p>}

          {!!plan.add.length && (
            <Section tone="emerald" title={t('mgmt.rooms.sync.addTitle', { n: plan.add.length })} onAll={() => setAdds(new Set(plan.add.map((a) => a.key)))} onClear={() => setAdds(new Set())}>
              {plan.add.map((a) => (
                <label key={a.key} className="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2.5 text-sm">
                  <input type="checkbox" checked={adds.has(a.key)} onChange={() => setAdds((s) => toggle(s, a.key))} />
                  <span className="min-w-0 flex-1 truncate font-bold text-navy-900">{a.name}</span>
                  <Chip>{t(`mgmt.rooms.sync.types.${a.room_type}`)}</Chip>
                  {a.tag && <Chip>{a.tag}</Chip>}
                  {a.capacity && <Chip>{t('mgmt.rooms.sync.seats', { n: a.capacity })}</Chip>}
                  {a.code && <Chip warn={a.exists_code} title={a.exists_code ? t('mgmt.rooms.sync.codeTaken') : undefined}>{a.code}</Chip>}
                </label>
              ))}
            </Section>
          )}

          {!!plan.update.length && (
            <Section tone="gold" title={t('mgmt.rooms.sync.updateTitle', { n: plan.update.length })} hint={t('mgmt.rooms.sync.updateHint')}
              onAll={() => setFields(Object.fromEntries(plan.update.map((u) => [u.id, new Set((Object.keys(u.changes) as Field[]).filter((f) => !u.changes[f]?.conflict))])))}
              onClear={() => setFields({})}>
              {plan.update.map((u) => {
                const on = fields[u.id] ?? new Set<Field>()
                const all = (Object.keys(u.changes) as Field[]).filter((f) => !u.changes[f]?.conflict)
                return (
                  <div key={u.id} className="flex flex-wrap items-center gap-2 px-3 py-2.5 text-sm">
                    <input type="checkbox" aria-label={u.name} checked={on.size > 0} onChange={() => setFields((s) => ({ ...s, [u.id]: new Set(on.size ? [] : all) }))} />
                    <span className="min-w-28 font-bold text-navy-900">{u.name}</span>
                    <span className="flex flex-1 flex-wrap gap-1.5">
                      {(Object.entries(u.changes) as [Field, { from: Value; to: Value; conflict?: boolean }][]).map(([f, c]) => (
                        <button key={f} type="button" disabled={c.conflict} aria-pressed={on.has(f)} title={c.conflict ? t('mgmt.rooms.sync.codeTaken') : undefined}
                          onClick={() => setFields((s) => ({ ...s, [u.id]: toggle(on, f) }))}
                          className={clsx('inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset transition',
                            c.conflict ? 'cursor-not-allowed bg-rose-50 text-rose-600 ring-rose-200' : on.has(f) ? 'bg-gold-50 text-navy-900 ring-gold-300 hover:bg-gold-100' : 'bg-white text-slate-400 line-through ring-slate-200 hover:text-slate-600')}>
                          <span className="text-slate-500">{t(`mgmt.rooms.sync.fields.${f}`)}</span>
                          {f === 'status' ? <span>{t('mgmt.rooms.sync.reactivate')}</span> : <><span className="text-slate-400">{show(f, c.from)}</span><ArrowRight aria-hidden className="size-3 text-slate-400 rtl:rotate-180" /><span className="font-bold">{show(f, c.to)}</span></>}
                        </button>
                      ))}
                    </span>
                  </div>
                )
              })}
            </Section>
          )}

          {!!plan.remove.length && (
            <Section tone="rose" title={t('mgmt.rooms.sync.removeTitle', { n: plan.remove.length })} hint={t('mgmt.rooms.sync.removeHint')} onAll={() => setRemoves(new Set(plan.remove.map((r) => r.id)))} onClear={() => setRemoves(new Set())}>
              {plan.remove.map((r) => (
                <label key={r.id} className="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2.5 text-sm">
                  <input type="checkbox" checked={removes.has(r.id)} onChange={() => setRemoves((s) => toggle(s, r.id))} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-bold text-navy-900">{r.name}</span>
                    <span className="block text-[11px] text-slate-500">{r.action === 'delete' ? t('mgmt.rooms.sync.unused') : t('mgmt.rooms.sync.usage', r)}</span>
                    {r.upcoming && <span className="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700"><AlertTriangle className="size-3.5" />{t('mgmt.rooms.sync.upcoming')}</span>}
                  </span>
                  <span className={clsx('rounded-full px-2.5 py-1 text-[11px] font-bold', r.action === 'delete' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700')}>{t(`mgmt.rooms.sync.${r.action}`)}</span>
                </label>
              ))}
            </Section>
          )}

          {!!plan.kept.length && <p className="flex items-start gap-2 rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-800"><ShieldCheck className="mt-0.5 size-4 shrink-0" />{t('mgmt.rooms.sync.keptLine', { names: plan.kept.map((k) => k.name).join('، ') })}</p>}
          {plan.inactive > 0 && <p className="px-1 text-xs text-slate-400">{t('mgmt.rooms.sync.inactiveLine', { n: plan.inactive })}</p>}
          {!!plan.same.length && (
            <details className="rounded-xl border border-navy-100 bg-white px-3 py-2">
              <summary className="cursor-pointer text-xs font-bold text-slate-500">{t('mgmt.rooms.sync.sameTitle', { n: plan.same.length })}</summary>
              <div className="mt-2 flex flex-wrap gap-1.5">{plan.same.map((s) => <Chip key={s.id}>{s.name}</Chip>)}</div>
            </details>
          )}

          <div className="sticky bottom-0 -mx-1 flex justify-end gap-2 border-t border-navy-100 bg-white/95 px-1 pt-3 backdrop-blur">
            <Button variant="ghost" onClick={onClose}>{t('mgmt.common.cancel')}</Button>
            <Button variant="gold" loading={busy} disabled={!total} onClick={() => void apply()}>{t('mgmt.rooms.sync.apply', { n: total })}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

const tones = {
  emerald: { box: 'border-emerald-200 bg-emerald-50/40', title: 'text-emerald-900', stat: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  gold: { box: 'border-gold-200 bg-gold-50/40', title: 'text-navy-900', stat: 'bg-gold-50 text-gold-800 ring-gold-200' },
  rose: { box: 'border-rose-200 bg-rose-50/40', title: 'text-rose-900', stat: 'bg-rose-50 text-rose-700 ring-rose-200' },
  slate: { box: 'border-slate-200 bg-slate-50', title: 'text-slate-700', stat: 'bg-slate-50 text-slate-600 ring-slate-200' },
  sky: { box: 'border-sky-200 bg-sky-50/40', title: 'text-sky-900', stat: 'bg-sky-50 text-sky-700 ring-sky-200' },
} as const

function Stat({ icon: Icon, tone, n, label }: { icon: ComponentType<{ className?: string }>; tone: keyof typeof tones; n: number; label: string }) {
  return (
    <div className={clsx('flex items-center gap-2.5 rounded-xl px-3 py-2.5 ring-1 ring-inset', tones[tone].stat)}>
      <Icon className="size-5 shrink-0" />
      <span><span className="block text-lg font-extrabold leading-none tabular-nums">{fmt.number(n)}</span><span className="text-[11px] font-semibold">{label}</span></span>
    </div>
  )
}

function Section({ tone, title, hint, onAll, onClear, children }: { tone: keyof typeof tones; title: string; hint?: string; onAll: () => void; onClear: () => void; children: ReactNode }) {
  const { t } = useTranslation()
  return (
    <section className={clsx('rounded-2xl border p-3', tones[tone].box)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className={clsx('text-sm font-bold', tones[tone].title)}>{title}</h3>
        <span className="flex gap-3 text-[11px] font-bold">
          <button type="button" className="text-navy-700 hover:text-gold-700" onClick={onAll}>{t('mgmt.rooms.sync.selectAll')}</button>
          <button type="button" className="text-navy-700 hover:text-gold-700" onClick={onClear}>{t('mgmt.rooms.sync.clear')}</button>
        </span>
      </div>
      {hint && <p className="mt-1 text-[11px] text-slate-600">{hint}</p>}
      <div className="mt-2 max-h-72 divide-y divide-navy-50 overflow-y-auto rounded-xl bg-white">{children}</div>
    </section>
  )
}

function Chip({ children, warn, title }: { children: ReactNode; warn?: boolean; title?: string }) {
  return <span title={title} className={clsx('rounded-full px-2 py-0.5 text-[11px] font-semibold', warn ? 'bg-rose-50 text-rose-700' : 'bg-navy-50 text-navy-700')}>{children}</span>
}
