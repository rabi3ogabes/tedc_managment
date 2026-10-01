import clsx from 'clsx'
import { AlertCircle, CalendarClock, Clock, Lock, LockKeyhole, LogOut, PlusCircle, SquarePen, ShieldCheck, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Card, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'

type Field = { key: string; label: string; type: 'text' | 'phone' | 'email' | 'number' | 'date' | 'select' | 'relation'; value: string | null; display: string | null; missing: boolean; options: { value: string; label: string }[] | null; pending: { id: string; requested_value: string; created_at: string } | null }
type Account = {
  identity: { name: string; email: string; subtitle: string | null; employee_no: string | null; status: string; supervisor: string | null; nationality?: { name: string; flag: string | null } | null; is_trainee: boolean; is_trainer: boolean }
  sections: { key: string; title: string; fields: Field[] }[]
  skills: { name: string; level: number }[]
  account: { roles: string[]; locale: string; member_since: string | null; last_login_at: string | null }
  stats: { missing: number; pending: number }
}
type Req = { id: string; label: string; kind: string; status: 'pending' | 'approved' | 'rejected' | 'cancelled'; requested_value: string; applied: boolean; review_note: string | null; created_at: string }

/** My profile: everything about the account, read-only; wrong or missing data is corrected through a request. */
export default function Profile() {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const account = useGet<{ data: Account }>('/me/account', undefined, { staleTime: 0 })
  const requests = useGet<{ data: Req[] }>('/me/account/requests', undefined, { staleTime: 0 })
  const [editing, setEditing] = useState<Field | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const a = account.data?.data
  const refresh = () => { void account.refetch(); void requests.refetch() }

  if (account.isLoading || !a) return <Spinner />
  const value = (f: Field) => (f.type === 'date' && f.value ? fmt.date(f.value) : f.type === 'number' && f.value ? fmt.number(Number(f.value), 1) : f.display ?? f.value)
  const ltr = (f: Field) => f.type === 'email' || f.type === 'phone'

  return (
    <div className="space-y-6">
      <PageHeader title={t('profile.title')} subtitle={t('profile.subtitle')} />

      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass sm:p-8">
        <div className="pattern-bg absolute inset-0 opacity-20" />
        <div className="pointer-events-none absolute -end-16 -top-20 size-72 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative flex flex-wrap items-center gap-6">
          <Avatar name={a.identity.name} size={84} />
          <div className="min-w-0 flex-1 basis-64">
            <h2 className="text-2xl font-bold sm:text-3xl">{a.identity.name}</h2>
            {a.identity.subtitle && <p className="mt-1 text-gold-300">{a.identity.subtitle}</p>}
            <p className="mt-0.5 text-sm text-white/70" dir="ltr">{a.identity.email}</p>
            <div className="mt-3 flex flex-wrap gap-2">
              {a.identity.nationality && <Chip>{a.identity.nationality.flag} {a.identity.nationality.name}</Chip>}
              {a.account.roles.map((r) => <Chip key={r} gold>{r}</Chip>)}
              {a.identity.employee_no && <Chip><span dir="ltr">{a.identity.employee_no}</span></Chip>}
              <Chip><ShieldCheck className="size-3.5 text-gold-300" />{t('profile.protected')}</Chip>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="light" icon={<LockKeyhole className="size-4" />} onClick={() => window.dispatchEvent(new Event('tedc:lock-now'))}>{t('userMenu.lock')}</Button>
            <Button variant="light" icon={<LogOut className="size-4" />} onClick={logout}>{t('nav.logout')}</Button>
          </div>
        </div>
      </section>

      <div className="rounded-2xl border border-gold-300 bg-gold-100/50 p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Lock className="size-5 text-gold-700" />
          <div className="min-w-0 flex-1 basis-64"><div className="font-bold text-navy-900">{t('profile.lockedTitle')}</div><p className="text-sm text-slate-600">{t('profile.lockedText')}</p></div>
          {a.stats.missing > 0 && <Badge color="amber">{t('profile.missing', { count: a.stats.missing })}</Badge>}
          {a.stats.pending > 0 && <Badge color="navy">{t('profile.pending', { count: a.stats.pending })}</Badge>}
        </div>
      </div>
      {toast && <div role="status" className="rounded-2xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">{toast}</div>}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="grid gap-6 md:grid-cols-2">
          {a.sections.map((s) => (
            <Card key={s.key} padded={false}>
              <h3 className="px-5 pt-5 text-lg font-bold text-navy-900">{s.title}</h3>
              <ul className="mt-3 divide-y divide-navy-100">
                {s.fields.map((f) => (
                  <li key={f.key} className={clsx('flex items-center gap-3 px-5 py-3', f.missing && 'bg-gold-100/40')}>
                    {f.missing ? <AlertCircle className="size-4 shrink-0 text-amber-600" /> : <Lock className="size-4 shrink-0 text-slate-300" />}
                    <div className="min-w-0 flex-1">
                      <div className="text-xs font-semibold text-slate-500">{f.label}</div>
                      <div className={clsx('truncate font-bold', f.missing ? 'italic text-amber-700' : 'text-navy-900')} dir={ltr(f) ? 'ltr' : undefined} style={ltr(f) ? { textAlign: 'start' } : undefined}>{f.missing ? t('profile.notRecorded') : value(f)}</div>
                    </div>
                    {f.pending ? <Badge color="navy">{t('profile.underReview')}</Badge> : (
                      <button type="button" onClick={() => setEditing(f)} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-bold text-navy-900 transition hover:bg-navy-100/60">
                        {f.missing ? <PlusCircle className="size-4" /> : <SquarePen className="size-4" />}{f.missing ? t('profile.complete') : t('profile.requestChange')}
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          ))}
        </div>

        <div className="space-y-6">
          <Card>
            <h3 className="mb-3 text-lg font-bold text-navy-900">{t('profile.session')}</h3>
            <dl className="space-y-3 text-sm">
              <div className="flex items-center gap-3"><Clock className="size-4 text-gold-600" /><dt className="text-slate-500">{t('profile.lastLogin')}</dt><dd className="ms-auto font-semibold text-navy-900">{a.account.last_login_at ? fmt.dateTime(a.account.last_login_at) : '—'}</dd></div>
              <div className="flex items-center gap-3"><CalendarClock className="size-4 text-gold-600" /><dt className="text-slate-500">{t('profile.memberSince')}</dt><dd className="ms-auto font-semibold text-navy-900">{a.account.member_since ? fmt.date(a.account.member_since) : '—'}</dd></div>
            </dl>
            <p className="mt-4 rounded-xl bg-ivory p-3 text-xs leading-relaxed text-slate-500">{t('profile.lockHint')}</p>
          </Card>
          {a.skills.length > 0 && (
            <Card><h3 className="mb-3 text-lg font-bold text-navy-900">{t('profile.skills')}</h3>
              <div className="flex flex-wrap gap-2">{a.skills.map((s) => <span key={s.name} className="inline-flex items-center gap-2 rounded-full border border-navy-100 bg-white py-1 pe-3 ps-1 text-sm font-semibold text-navy-900"><span className="grid size-6 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{s.level}</span>{s.name}</span>)}</div></Card>
          )}
          <RequestsCard items={requests.data?.data ?? []} onChange={refresh} />
        </div>
      </div>

      {editing && <RequestModal field={editing} onClose={() => setEditing(null)} onSent={() => { setEditing(null); setToast(t('profile.sent')); refresh(); setTimeout(() => setToast(null), 5000) }} />}
    </div>
  )
}

function Chip({ children, gold }: { children: React.ReactNode; gold?: boolean }) {
  return <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold', gold ? 'bg-gold-500 text-navy-950' : 'bg-white/15 text-white ring-1 ring-white/20')}>{children}</span>
}

function RequestsCard({ items, onChange }: { items: Req[]; onChange: () => void }) {
  const { t } = useTranslation()
  return (
    <Card>
      <h3 className="mb-3 text-lg font-bold text-navy-900">{t('profile.requests')}</h3>
      {items.length === 0 ? <p className="text-sm text-slate-400">{t('profile.noRequests')}</p> : (
        <ul className="space-y-3">
          {items.slice(0, 8).map((r) => (
            <li key={r.id} className="rounded-xl border border-navy-100 p-3">
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-navy-900">{r.label} › {r.requested_value}</div><div className="text-xs text-slate-400">{fmt.dateTime(r.created_at)}{r.review_note && ` · ${r.review_note}`}</div></div>
                <Badge color={r.status === 'approved' ? 'green' : r.status === 'rejected' ? 'red' : r.status === 'pending' ? 'amber' : 'gray'}>{t(`profile.statuses.${r.status}`)}</Badge>
                {r.status === 'pending' && <button type="button" aria-label={t('profile.withdraw')} title={t('profile.withdraw')} onClick={async () => { await api.delete(`/me/account/requests/${r.id}`); onChange() }} className="text-slate-400 hover:text-danger"><X className="size-4" /></button>}
              </div>
              {r.applied && <div className="mt-1 text-[11px] font-semibold text-emerald-600">{t('profile.applied')}</div>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function RequestModal({ field, onClose, onSent }: { field: Field; onClose: () => void; onSent: () => void }) {
  const { t } = useTranslation()
  const [kind, setKind] = useState<'wrong' | 'missing' | 'update'>(field.missing ? 'missing' : 'wrong')
  const [answer, setAnswer] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const send = async () => {
    setBusy(true); setError(null)
    try { await api.post('/me/account/requests', { field: field.key, kind, requested_value: answer.trim(), note: note.trim() || undefined }); onSent() } catch (e) { setError(errorMessage(e)); setBusy(false) }
  }
  const inputType = field.type === 'date' ? 'date' : field.type === 'number' ? 'number' : field.type === 'email' ? 'email' : field.type === 'phone' ? 'tel' : 'text'

  return (
    <Modal open onClose={onClose} title={`${t('profile.requestChange')}: ${field.label}`}>
      <div className="space-y-4">
        <p className="text-sm text-slate-500">{t('profile.sheetHint')}</p>
        <div className="flex items-center gap-3 rounded-xl bg-navy-100/50 p-3"><Lock className="size-4 text-slate-400" /><div><div className="text-[11px] font-semibold text-slate-500">{t('profile.currentValue')}</div><div className={clsx('font-bold', field.missing ? 'italic text-amber-700' : 'text-navy-900')}>{field.missing ? t('profile.notRecorded') : field.display ?? field.value}</div></div></div>
        <div role="radiogroup" className="grid grid-cols-3 gap-1 rounded-xl bg-navy-100/50 p-1 text-xs font-bold">
          {(['wrong', 'missing', 'update'] as const).map((k) => <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => setKind(k)} className={clsx('rounded-lg py-1.5 transition', kind === k ? 'bg-white text-navy-900 shadow-sm' : 'text-slate-500')}>{t(`profile.kinds.${k}`)}</button>)}
        </div>
        <Field label={t('profile.correctValue')}>
          {field.type === 'select'
            ? <select className="input" value={answer} onChange={(e) => setAnswer(e.target.value)}><option value="">—</option>{field.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
            : <input className="input" type={inputType} dir={field.type === 'email' || field.type === 'phone' ? 'ltr' : undefined} max={field.type === 'date' ? new Date().toISOString().slice(0, 10) : undefined} maxLength={255} value={answer} onChange={(e) => setAnswer(e.target.value)} />}
        </Field>
        <Field label={t('profile.noteOptional')}><textarea className="input min-h-20" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        {error && <p className="text-sm text-danger">{error}</p>}
        <div className="flex justify-end gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!answer.trim()} onClick={send}>{t('profile.send')}</Button></div>
      </div>
    </Modal>
  )
}
