import clsx from 'clsx'
import { CalendarDays, Check, ClipboardList, Copy, Crown, DoorOpen, GraduationCap, KeyRound, LogIn, Presentation, RefreshCcw, ShieldCheck, Smartphone, Sparkles, Trash2, UserRound, UsersRound } from 'lucide-react'
import { useState, type ComponentType } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Modal, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { impersonation } from '@/lib/impersonation'
import { toast } from '@/lib/toast'

type Group = 'leadership' | 'supervision' | 'training' | 'learners'
type Account = { key: string; group: Group; email: string; id: string | null; name: string; roles: string[]; public: boolean; app: boolean; app_ready: boolean; last_opened_at: string | null }
type Program = { id: string; code: string; title: string; status: string; start_date: string | null; end_date: string | null; trainees: number }
type Payload = { exists: boolean; public_sign_in: boolean; leadership: { enabled: boolean; code: string | null }; accounts: Account[]; programs: Program[] }

const GROUPS: { id: Group; icon: ComponentType<{ className?: string }>; tone: string }[] = [
  { id: 'leadership', icon: Crown, tone: 'from-navy-900 to-navy-800 text-gold-300' },
  { id: 'supervision', icon: ClipboardList, tone: 'from-gold-500 to-gold-400 text-navy-950' },
  { id: 'training', icon: Presentation, tone: 'from-emerald-700 to-emerald-600 text-white' },
  { id: 'learners', icon: GraduationCap, tone: 'from-sky-700 to-sky-600 text-white' },
]

/** Settings → Showcase accounts (system administrator only): one account per role with sample training, opened in one tap. */
export default function ShowcaseAccounts() {
  const { t } = useTranslation()
  const { data, isLoading, isError, error: loadError, refetch } = useGet<{ data: Payload }>('/admin/showcase-accounts')
  const [busy, setBusy] = useState<'build' | 'remove' | 'public' | 'lead' | null>(null)
  const [opening, setOpening] = useState<string | null>(null)
  const [password, setPassword] = useState<{ key: string; name: string; email: string; password: string } | null>(null)
  const [asking, setAsking] = useState<string | null>(null)
  const [copied, setCopied] = useState<string | null>(null)

  if (isError) return <Card><div className="py-10 text-center"><Sparkles className="mx-auto size-10 text-gold-500" /><p className="mx-auto mt-3 max-w-md text-sm font-semibold text-danger">{errorMessage(loadError)}</p></div></Card>
  if (isLoading || !data) return <Spinner />
  const d = data.data

  const build = async () => {
    setBusy('build')
    try { await api.post('/admin/showcase-accounts'); await refetch(); toast(t(d.exists ? 'mgmt.showcase.refreshed' : 'mgmt.showcase.created')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const remove = async () => {
    if (!(await dialogs.confirm(t('mgmt.showcase.removeConfirm')))) return
    setBusy('remove')
    try { await api.delete('/admin/showcase-accounts'); await refetch(); toast(t('mgmt.showcase.removed')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const open = async (a: Account) => {
    setOpening(a.key)
    try {
      const { landing } = await impersonation.start(a.id ?? a.key, { open: `/admin/showcase-accounts/${a.key}/open`, stop: `/admin/showcase-accounts/${a.key}/close`, returnTo: '/admin/settings?tab=showcase' })
      window.location.href = landing === 'admin' ? '/admin' : '/portal'
    } catch (e) { toast(errorMessage(e), 'error'); setOpening(null) }
  }
  const appPassword = async (a: Account) => {
    setAsking(a.key)
    try { const { data: r } = await api.post(`/admin/showcase-accounts/${a.key}/app-password`); setPassword({ key: a.key, name: a.name, ...r.data }); void refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setAsking(null) }
  }
  const togglePublic = async () => {
    setBusy('public')
    try { await api.put('/admin/showcase-accounts/public-sign-in', { enabled: !d.public_sign_in }); await refetch(); toast(t(d.public_sign_in ? 'mgmt.showcase.publicOff' : 'mgmt.showcase.publicOn')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const leadership = async (body: { enabled?: boolean; regenerate?: boolean }, done: string) => {
    setBusy('lead')
    try { await api.put('/admin/showcase-accounts/leadership', body); await refetch(); toast(t(done)) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const newCode = async () => { if (await dialogs.confirm(t('mgmt.showcase.leadNewConfirm'))) await leadership({ regenerate: true }, 'mgmt.showcase.leadNewDone') }
  const copy = (text: string) => { void navigator.clipboard?.writeText(text); setCopied(text); setTimeout(() => setCopied(null), 1500) }

  return (
    <div className="space-y-6">
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Sparkles className="size-5" /></span>{t('mgmt.settings.sections.showcase.title')}</span>}
        subtitle={t('mgmt.showcase.subtitle')}
        actions={d.exists ? <Button variant="outline" icon={<RefreshCcw className="size-4" />} loading={busy === 'build'} disabled={busy !== null} onClick={build}>{t('mgmt.showcase.refresh')}</Button> : undefined}
      />

      {/* What these accounts are, before anything else */}
      <div className="flex items-start gap-3 rounded-2xl border border-gold-300/60 bg-gradient-to-l from-gold-100/60 to-white p-4">
        <ShieldCheck className="mt-0.5 size-5 shrink-0 text-gold-700" />
        <p className="text-sm leading-relaxed text-navy-900">{t('mgmt.showcase.notice')}</p>
      </div>

      {!d.exists ? (
        <Card>
          <div className="mx-auto max-w-2xl py-8 text-center">
            <span className="mx-auto grid size-16 place-items-center rounded-3xl bg-navy-900 text-gold-300 shadow-glass"><UsersRound className="size-7" /></span>
            <h2 className="mt-4 text-2xl font-extrabold text-navy-900">{t('mgmt.showcase.emptyTitle')}</h2>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-slate-600">{t('mgmt.showcase.emptyText')}</p>
            <div className="mt-6 flex flex-wrap justify-center gap-2">
              {d.accounts.map((a) => <span key={a.key} className="rounded-full bg-ivory px-3 py-1 text-xs font-semibold text-navy-800 ring-1 ring-navy-100">{a.name}</span>)}
            </div>
            <Button className="mt-7" size="lg" variant="gold" icon={<Sparkles className="size-4" />} loading={busy === 'build'} onClick={build}>{t('mgmt.showcase.create')}</Button>
          </div>
        </Card>
      ) : (
        <>
          {/* The supervisors, trainers and trainees on the sign-in page, for anyone to open in one tap */}
          <Card>
            <div className="flex flex-wrap items-center gap-4">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-gold-100 text-gold-700"><DoorOpen className="size-5" /></span>
              <div className="min-w-0 flex-1 basis-64"><div className="font-bold text-navy-900">{t('mgmt.showcase.publicTitle')}</div><p className="mt-0.5 text-sm text-slate-600">{t('mgmt.showcase.publicText')}</p></div>
              <button type="button" role="switch" aria-checked={d.public_sign_in} aria-label={t('mgmt.showcase.publicTitle')} disabled={busy !== null} onClick={togglePublic} className={clsx('relative inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors disabled:opacity-60', d.public_sign_in ? 'justify-end bg-gold-500' : 'justify-start bg-slate-300')}><span className="inline-block size-6 rounded-full bg-white shadow" /></button>
            </div>
          </Card>

          {/* The leadership accounts on the sign-in page, behind a code */}
          <Card>
            <div className="flex flex-wrap items-center gap-4">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-navy-900 text-gold-300"><KeyRound className="size-5" /></span>
              <div className="min-w-0 flex-1 basis-64">
                <div className="font-bold text-navy-900">{t('mgmt.showcase.leadTitle')}</div>
                <p className="mt-0.5 text-sm text-slate-600">{t('mgmt.showcase.leadText')}</p>
                {d.leadership.code && (
                  <div className="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" onClick={() => copy(d.leadership.code!)} className="inline-flex items-center gap-2 rounded-xl bg-ivory px-4 py-2 ring-1 ring-gold-300 transition hover:ring-gold-500" aria-label={t('mgmt.showcase.leadCode')}>
                      <span className="text-xs font-semibold text-slate-500">{t('mgmt.showcase.leadCode')}</span><bdi dir="ltr" className="font-mono text-base font-extrabold tracking-widest text-navy-900">{d.leadership.code}</bdi>
                      {copied === d.leadership.code ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4 text-slate-400" />}
                    </button>
                    <Button size="sm" variant="outline" icon={<RefreshCcw className="size-4" />} disabled={busy !== null} onClick={newCode}>{t('mgmt.showcase.leadNew')}</Button>
                  </div>
                )}
              </div>
              <button type="button" role="switch" aria-checked={d.leadership.enabled} aria-label={t('mgmt.showcase.leadTitle')} disabled={busy !== null} onClick={() => void leadership({ enabled: !d.leadership.enabled }, d.leadership.enabled ? 'mgmt.showcase.leadOff' : 'mgmt.showcase.leadOn')} className={clsx('relative inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors disabled:opacity-60', d.leadership.enabled ? 'justify-end bg-gold-500' : 'justify-start bg-slate-300')}><span className="inline-block size-6 rounded-full bg-white shadow" /></button>
            </div>
          </Card>

          {GROUPS.map(({ id, icon: Icon, tone }) => (
            <section key={id}>
              <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-navy-900"><Icon className="size-5 text-gold-600" />{t(`mgmt.showcase.groups.${id}`)}</h2>
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {d.accounts.filter((a) => a.group === id).map((a) => (
                  <article key={a.key} className="group flex flex-col overflow-hidden rounded-3xl border border-navy-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-gold-300 hover:shadow-md">
                    <div className={clsx('h-1.5 bg-gradient-to-l', tone)} />
                    <div className="flex flex-1 flex-col p-5">
                      <div className="flex items-center gap-3">
                        <span className={clsx('grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br shadow-sm', tone)}><Icon className="size-5" /></span>
                        <div className="min-w-0 flex-1">
                          <div className="font-bold leading-snug text-navy-900">{a.name}</div>
                          <div className="mt-1 flex flex-wrap gap-1">{a.roles.map((r) => <Badge key={r} color={id === 'leadership' ? 'navy' : 'gold'}>{r}</Badge>)}{a.public && d.public_sign_in && <Badge color="green">{t('mgmt.showcase.onSignIn')}</Badge>}</div>
                        </div>
                      </div>
                      <p className="mt-3 flex-1 text-sm leading-relaxed text-slate-600">{t(`mgmt.showcase.hints.${a.key}`)}</p>
                      <button type="button" onClick={() => copy(a.email)} dir="ltr" className="mt-3 flex w-full items-center justify-between gap-2 rounded-xl bg-ivory px-3 py-2 text-start font-mono text-xs text-navy-800 transition hover:bg-gold-100/60" aria-label={t('mgmt.showcase.copyEmail')}>
                        <span className="truncate">{a.email}</span>{copied === a.email ? <Check className="size-3.5 shrink-0 text-emerald-600" /> : <Copy className="size-3.5 shrink-0 text-slate-400" />}
                      </button>
                      <Button className="mt-4 w-full" icon={<LogIn className="size-4" />} loading={opening === a.key} disabled={opening !== null || busy !== null} onClick={() => open(a)}>{t('mgmt.showcase.open')}</Button>
                      {a.app && (
                        <Button className="mt-2 w-full" variant="ghost" size="sm" icon={<Smartphone className="size-4" />} loading={asking === a.key} disabled={busy !== null} onClick={() => appPassword(a)}>
                          {t(a.app_ready ? 'mgmt.showcase.newAppPassword' : 'mgmt.showcase.appPassword')}
                        </Button>
                      )}
                      {a.last_opened_at && <p className="mt-2 text-center text-[11px] text-slate-400">{t('mgmt.showcase.lastOpened', { when: fmt.dateTime(a.last_opened_at) })}</p>}
                    </div>
                  </article>
                ))}
              </div>
            </section>
          ))}

          {/* The sample training they all take part in */}
          <section>
            <h2 className="mb-1 flex items-center gap-2 text-lg font-bold text-navy-900"><CalendarDays className="size-5 text-gold-600" />{t('mgmt.showcase.programs')}</h2>
            <p className="mb-3 text-sm text-slate-500">{t('mgmt.showcase.programsHint')}</p>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {d.programs.map((p) => (
                <Link key={p.id} to={`/admin/programs/${p.id}`} className="flex flex-col gap-2 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm transition hover:border-gold-400">
                  <div className="flex items-center justify-between gap-2"><StatusBadge status={p.status} /><span className="font-mono text-[11px] text-slate-400" dir="ltr">{p.code}</span></div>
                  <div className="font-bold leading-snug text-navy-900">{p.title}</div>
                  <div className="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                    {p.start_date && <span className="inline-flex items-center gap-1"><CalendarDays className="size-3.5" />{fmt.date(p.start_date)}</span>}
                    <span className="inline-flex items-center gap-1"><UserRound className="size-3.5" />{t('mgmt.showcase.trainees', { count: p.trainees })}</span>
                  </div>
                </Link>
              ))}
            </div>
          </section>

          {/* Removing everything */}
          <Card className="border-red-100">
            <div className="flex flex-wrap items-center gap-4">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-red-50 text-danger"><Trash2 className="size-5" /></span>
              <div className="min-w-0 flex-1 basis-64"><div className="font-bold text-navy-900">{t('mgmt.showcase.removeTitle')}</div><p className="mt-0.5 text-sm text-slate-600">{t('mgmt.showcase.removeText')}</p></div>
              <Button variant="danger" icon={<Trash2 className="size-4" />} loading={busy === 'remove'} disabled={busy !== null} onClick={remove}>{t('mgmt.showcase.remove')}</Button>
            </div>
          </Card>
        </>
      )}

      <Modal open={password !== null} onClose={() => setPassword(null)} title={<span className="flex items-center gap-2"><Smartphone className="size-5 text-gold-600" />{t('mgmt.showcase.appTitle')}</span>}>
        {password && (
          <div className="space-y-4">
            <p className="text-sm leading-relaxed text-slate-600">{t('mgmt.showcase.appText', { name: password.name })}</p>
            {([['email', password.email], ['password', password.password]] as const).map(([label, value]) => (
              <button key={label} type="button" onClick={() => copy(value)} className="flex w-full items-center justify-between gap-3 rounded-2xl bg-ivory px-4 py-3 text-start ring-1 ring-navy-100 transition hover:ring-gold-400">
                <span><span className="block text-xs font-semibold text-slate-500">{t(`mgmt.showcase.${label}`)}</span><bdi dir="ltr" className="mt-0.5 block font-mono text-sm font-extrabold text-navy-900">{value}</bdi></span>
                {copied === value ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4 text-slate-400" />}
              </button>
            ))}
            <p className="text-xs text-slate-500">{t('mgmt.showcase.appNote')}</p>
          </div>
        )}
      </Modal>
    </div>
  )
}
