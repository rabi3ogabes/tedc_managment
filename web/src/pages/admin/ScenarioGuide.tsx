import clsx from 'clsx'
import { Check, Copy, Play, RefreshCcw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'

type Item = { id: string; code: string; mode: 'in_person' | 'online' | 'hybrid'; status: string; title: string }

const CAST: [string, string][] = [
  ['admin', 'admin@tedc.qa'], ['center', 'center@tedc.qa'], ['coordinator', 'coordinator@tedc.qa'], ['executive', 'executive@tedc.qa'], ['kits', 'kits@tedc.qa'], ['qa', 'qa@tedc.qa'],
  ['trainer', 'trainer@tedc.qa'], ['school', 'school@tedc.qa'], ['teacher', 'teacher@tedc.qa'], ['trainee1', 'trainee1@tedc.qa'], ['trainee2', 'trainee2@tedc.qa'], ['trainee3', 'trainee3@tedc.qa'], ['trainee4', 'trainee4@tedc.qa'],
]
const PHASES = ['accounts', 'sc1', 'sc2', 'sc3', 'sc4', 'sc5'] as const
const TONE = { in_person: 'bg-navy-900 text-gold-300', online: 'bg-sky-600 text-white', hybrid: 'bg-emerald-600 text-white' }

/** Settings → Test accounts: the presentation scenario — build it, see its programs, who plays whom, and the running order. */
export default function ScenarioGuide({ programs, onBuilt }: { programs: Item[]; onBuilt: () => void }) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)
  const [step, setStep] = useState(0)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const [copied, setCopied] = useState<string | null>(null)
  const ready = programs.length > 0
  const steps = t('scenario.steps', { returnObjects: true }) as unknown as string[][]

  const build = async () => {
    setBusy(true); setNote(null); setStep(0)
    try {
      // One phase per request keeps every call short, even on a slow connection to the database.
      for (let i = 0; i < PHASES.length; i++) { setStep(i + 1); await api.post('/admin/test-accounts/scenario', { phase: PHASES[i] }, { timeout: 120_000 }) }
      setNote({ ok: true, text: t('scenario.built') }); onBuilt()
    } catch (e) { setNote({ ok: false, text: `${errorMessage(e)} — ${t('scenario.retry')}` }) } finally { setBusy(false); setStep(0) }
  }
  const copy = (v: string) => { void navigator.clipboard?.writeText(v); setCopied(v); setTimeout(() => setCopied(null), 1500) }

  return (
    <section className="space-y-5">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass">
        <div className="pointer-events-none absolute -end-20 -top-20 size-64 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative flex flex-wrap items-center gap-4">
          <span className="grid size-12 place-items-center rounded-2xl bg-gold-500 text-navy-950"><Play className="size-6" /></span>
          <div className="min-w-0 flex-1 basis-72"><h2 className="text-xl font-extrabold">{t('scenario.title')}</h2><p className="mt-1 text-sm text-white/70">{t('scenario.subtitle')}</p></div>
          <Button variant="gold" icon={<RefreshCcw className="size-4" />} loading={busy} onClick={build}>{busy && step > 0 ? t('scenario.progress', { n: step, total: PHASES.length }) : ready ? t('scenario.rebuild') : t('scenario.build')}</Button>
        </div>
      </div>
      {note && <div role="status" className={clsx('rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.text}</div>}

      {!ready ? <Card><p className="py-8 text-center text-sm text-slate-500">{t('scenario.empty')}</p></Card> : (
        <>
          <div>
            <h3 className="mb-3 font-bold text-navy-900">{t('scenario.programs')}</h3>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              {programs.map((p) => (
                <Link key={p.id} to={`/admin/programs/${p.id}`} className="rounded-2xl border border-navy-100 bg-white p-4 shadow-sm transition hover:border-gold-400">
                  <div className="flex items-center justify-between"><span className={clsx('rounded-lg px-2 py-1 text-xs font-black', TONE[p.mode])}>{p.code}</span><Badge color="gold">{t(`scenario.modes.${p.mode}`)}</Badge></div>
                  <div className="mt-2 font-bold leading-snug text-navy-900">{p.title}</div>
                  <div className="mt-1 text-xs text-slate-500">{t(`scenario.status.${p.status}`, { defaultValue: p.status })} · 08:00 – 13:00</div>
                </Link>
              ))}
            </div>
          </div>

          <div>
            <h3 className="mb-3 font-bold text-navy-900">{t('scenario.script')}</h3>
            <ol className="grid gap-3 lg:grid-cols-3">
              {steps.map((s, i) => (
                <li key={i} className="flex gap-3 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
                  <span className="grid size-9 shrink-0 place-items-center rounded-full bg-navy-900 text-sm font-black text-gold-300">{i + 1}</span>
                  <div className="min-w-0"><div className="font-extrabold text-navy-900">{s[0]} <span className="text-xs font-semibold text-gold-700">· {s[1]}</span></div><p className="mt-1 text-sm text-slate-600">{s[2]}</p></div>
                </li>
              ))}
            </ol>
          </div>

          <Card padded={false}>
            <div className="p-5 pb-3 font-bold text-navy-900">{t('scenario.cast')}</div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-y border-navy-100 bg-ivory/60 text-xs text-slate-500"><th className="px-5 py-2.5 text-start font-semibold">{t('scenario.cols.who')}</th><th className="px-3 py-2.5 text-start font-semibold">{t('scenario.cols.account')}</th><th className="px-3 py-2.5 text-start font-semibold">{t('scenario.cols.does')}</th></tr></thead>
                <tbody className="divide-y divide-navy-100">
                  {CAST.map(([key, email]) => {
                    const [who, does] = t(`scenario.roles.${key}`, { returnObjects: true }) as unknown as string[]
                    return (
                      <tr key={key} className="hover:bg-ivory/50">
                        <td className="whitespace-nowrap px-5 py-2.5 font-bold text-navy-900">{who}</td>
                        <td className="px-3 py-2.5"><button type="button" onClick={() => copy(email)} dir="ltr" className="inline-flex items-center gap-2 rounded-lg bg-ivory px-2.5 py-1 font-mono text-xs text-navy-800 hover:bg-gold-100/60">{email}{copied === email ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5 text-slate-400" />}</button></td>
                        <td className="min-w-72 px-3 py-2.5 text-slate-600">{does}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <p className="border-t border-navy-100 p-4 text-xs text-slate-500">{t('scenario.note')}</p>
          </Card>
        </>
      )}
    </section>
  )
}
