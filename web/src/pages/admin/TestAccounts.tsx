import clsx from 'clsx'
import { BookOpenCheck, Check, Copy, FlaskConical, GraduationCap, Presentation, RefreshCcw, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type Account = { kind: 'trainee' | 'trainer'; n: number; name: string | null; email: string; programs: number[] }
type Program = { n: number; id: string; code: string; title: string; trainers: string[]; trainees: number }
type Course = { id: string; code: string; title: string; lessons: number; trainees: number }
type Payload = { seeded: boolean; password: string; accounts: Account[]; programs: Program[]; courses?: Course[] }

const PROGRAM_TONE = ['bg-navy-900 text-gold-300', 'bg-gold-500 text-navy-950', 'bg-emerald-600 text-white', 'bg-sky-600 text-white']

/** Settings → Test accounts: the demo trainees and trainers for the mobile app, and exactly which programs each is assigned to. */
export default function TestAccounts() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/test-accounts')
  const [busy, setBusy] = useState(false)
  const [copied, setCopied] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (isLoading || !data) return <Spinner />
  const d = data.data

  const seed = async () => {
    setBusy(true); setError(null)
    try { await api.post('/admin/test-accounts'); await refetch() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const copy = (text: string) => { void navigator.clipboard?.writeText(text); setCopied(text); setTimeout(() => setCopied(null), 1500) }
  const list = (n: number[]) => n.join('، ')

  return (
    <div className="space-y-6">
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><FlaskConical className="size-5" /></span>{t('mgmt.settings.sections.testaccounts.title')}</span>}
        subtitle={t('mgmt.testAccounts.subtitle')}
        actions={<Button variant="gold" icon={<RefreshCcw className="size-4" />} loading={busy} onClick={seed}>{d.seeded ? t('mgmt.testAccounts.refresh') : t('mgmt.testAccounts.create')}</Button>}
      />
      {error && <div className="rounded-2xl bg-red-50 p-3 text-sm font-semibold text-danger">{error}</div>}

      {!d.seeded ? (
        <Card><div className="py-10 text-center"><FlaskConical className="mx-auto size-10 text-gold-500" /><h2 className="mt-3 text-xl font-bold text-navy-900">{t('mgmt.testAccounts.emptyTitle')}</h2><p className="mx-auto mt-1 max-w-md text-sm text-slate-500">{t('mgmt.testAccounts.emptyText')}</p></div></Card>
      ) : (
        <>
          <Card className="bg-gradient-to-l from-gold-100/50 to-white">
            <div className="flex flex-wrap items-center gap-4">
              <div className="min-w-0 flex-1 basis-64"><div className="font-bold text-navy-900">{t('mgmt.testAccounts.howTo')}</div><p className="mt-0.5 text-sm text-slate-600">{t('mgmt.testAccounts.howToText')}</p></div>
              <button type="button" onClick={() => copy(d.password)} className="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 ring-1 ring-gold-300 transition hover:ring-gold-500" aria-label={t('mgmt.testAccounts.password')}>
                <span className="text-xs font-semibold text-slate-500">{t('mgmt.testAccounts.password')}</span><bdi dir="ltr" className="font-mono text-sm font-extrabold text-navy-900">{d.password}</bdi>
                {copied === d.password ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4 text-slate-400" />}
              </button>
            </div>
          </Card>

          {/* The programs, numbered */}
          <section>
            <h2 className="mb-3 text-lg font-bold text-navy-900">{t('mgmt.testAccounts.programs')}</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {d.programs.map((p) => (
                <div key={p.id} className="flex gap-3 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
                  <span className={clsx('grid size-14 shrink-0 place-items-center rounded-2xl text-3xl font-black', PROGRAM_TONE[(p.n - 1) % 4])}>{p.n}</span>
                  <div className="min-w-0"><div className="truncate font-bold text-navy-900">{p.title}</div>
                    <div className="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><Presentation className="size-3.5" />{p.trainers.join('، ') || '—'}</div>
                    <div className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500"><GraduationCap className="size-3.5" />{t('mgmt.testAccounts.traineesCount', { count: p.trainees })}</div></div>
                </div>
              ))}
            </div>
          </section>

          {/* Online courses to try the learning experience */}
          {(d.courses?.length ?? 0) > 0 && (
            <section>
              <h2 className="flex items-center gap-2 text-lg font-bold text-navy-900"><BookOpenCheck className="size-5 text-gold-600" />{t('mgmt.testAccounts.courses')}</h2>
              <p className="mb-3 mt-0.5 text-sm text-slate-500">{t('mgmt.testAccounts.coursesHint')}</p>
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {d.courses!.map((c, i) => (
                  <Link key={c.id} to={`/admin/programs/${c.id}`} className="flex gap-3 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm transition hover:border-gold-400">
                    <span className={clsx('grid size-14 shrink-0 place-items-center rounded-2xl text-3xl font-black', PROGRAM_TONE[i % 4])}>{i + 1}</span>
                    <div className="min-w-0"><div className="font-bold leading-snug text-navy-900">{c.title}</div>
                      <div className="mt-1 text-xs text-slate-500">{t('mgmt.testAccounts.lessonsCount', { count: c.lessons })} · {t('mgmt.testAccounts.traineesCount', { count: c.trainees })}</div></div>
                  </Link>
                ))}
              </div>
            </section>
          )}

          {/* Who is assigned to what, in plain words */}
          {(['trainee', 'trainer'] as const).map((kind) => (
            <section key={kind}>
              <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-navy-900">{kind === 'trainee' ? <UserRound className="size-5 text-gold-600" /> : <Presentation className="size-5 text-gold-600" />}{t(`mgmt.testAccounts.${kind}s`)}</h2>
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                {d.accounts.filter((a) => a.kind === kind).map((a) => (
                  <div key={a.email} className="rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
                    <div className="flex items-center gap-3">
                      <span className="grid size-11 place-items-center rounded-full bg-navy-900 text-lg font-extrabold text-gold-300">{a.n}</span>
                      <div className="min-w-0 flex-1"><div className="truncate font-bold text-navy-900">{a.name}</div><Badge color={kind === 'trainee' ? 'navy' : 'gold'}>{t(`mgmt.testAccounts.${kind}`)} {a.n}</Badge></div>
                    </div>
                    <button type="button" onClick={() => copy(a.email)} className="mt-3 flex w-full items-center justify-between gap-2 rounded-lg bg-ivory px-3 py-2 text-start font-mono text-xs text-navy-800 hover:bg-gold-100/60" dir="ltr">
                      <span className="truncate">{a.email}</span>{copied === a.email ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5 text-slate-400" />}
                    </button>
                    <div className="mt-3 flex flex-wrap items-center gap-1.5">{a.programs.map((n) => <span key={n} className={clsx('grid size-8 place-items-center rounded-lg text-sm font-black', PROGRAM_TONE[(n - 1) % 4])}>{n}</span>)}</div>
                    <p className="mt-2 text-sm font-semibold leading-relaxed text-navy-900">{t(`mgmt.testAccounts.assigned.${kind}`, { n: a.n, programs: list(a.programs), count: a.programs.length })}</p>
                  </div>
                ))}
              </div>
            </section>
          ))}

          {/* Matrix */}
          <Card padded={false}>
            <div className="p-5 pb-3 font-bold text-navy-900">{t('mgmt.testAccounts.matrix')}</div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-y border-navy-100 bg-ivory/60 text-xs text-slate-500"><th className="px-5 py-2.5 text-start font-semibold">{t('mgmt.live.cols.name')}</th>{d.programs.map((p) => <th key={p.n} className="px-3 py-2.5 text-center font-semibold">{t('mgmt.testAccounts.programN', { n: p.n })}</th>)}</tr></thead>
                <tbody className="divide-y divide-navy-100">
                  {d.accounts.map((a) => (
                    <tr key={a.email} className="hover:bg-ivory/50">
                      <td className="px-5 py-2.5"><span className="font-semibold text-navy-900">{a.name}</span> <span className="text-xs text-slate-400" dir="ltr">{a.email}</span></td>
                      {d.programs.map((p) => <td key={p.n} className="px-3 py-2.5 text-center">{a.programs.includes(p.n) ? <span className={clsx('inline-grid size-7 place-items-center rounded-full', PROGRAM_TONE[(p.n - 1) % 4])}><Check className="size-4" /></span> : <span className="text-slate-200">—</span>}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
