import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, CircleSlash, FlaskConical, PlugZap, RefreshCw, SlidersHorizontal, Stethoscope } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button } from '@/components/ui'
import { intlLocale } from '@/lib/format'
import { relativeTime } from '@/lib/relativeTime'
import type { ConnectionState, Provider } from './types'

const badgeTone: Record<ConnectionState, 'green' | 'blue' | 'amber' | 'red' | 'gray'> = { connected: 'green', training: 'blue', attention: 'amber', down: 'red', not_set_up: 'amber', off: 'gray' }
const ring: Record<ConnectionState, string> = {
  connected: 'ring-emerald-500/55', training: 'ring-sky-400/60', attention: 'ring-amber-500/60', down: 'ring-red-500/60', not_set_up: 'ring-amber-400/50', off: 'ring-slate-300',
}
const stateIcon: Record<ConnectionState, typeof CheckCircle2> = { connected: CheckCircle2, training: FlaskConical, attention: AlertTriangle, down: AlertTriangle, not_set_up: PlugZap, off: CircleSlash }

/** A provider's initial inside a ring that takes the colour of its connection: the status is readable before a word is. */
function Mark({ name, state }: { name: string; state: ConnectionState }) {
  return (
    <div className={clsx('grid size-12 shrink-0 place-items-center rounded-2xl bg-navy-900 font-display text-lg font-bold text-gold-300 ring-2 ring-offset-2 ring-offset-white transition-colors', ring[state])} aria-hidden="true">
      {name.trim().charAt(0).toUpperCase()}
    </div>
  )
}

function Switch({ checked, onChange, label, disabled }: { checked: boolean; onChange: (on: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <button
      type="button" role="switch" aria-checked={checked} aria-label={label} title={label} disabled={disabled} onClick={() => onChange(!checked)}
      className={clsx('relative h-6 w-11 shrink-0 rounded-full transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500 disabled:opacity-50', checked ? 'bg-emerald-500' : 'bg-slate-300')}
    >
      <span className={clsx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all duration-200 motion-reduce:transition-none', checked ? 'start-[1.375rem]' : 'start-0.5')} />
    </button>
  )
}

function Stat({ value, label, tone }: { value: number; label: string; tone?: 'warn' }) {
  return (
    <div className={clsx('rounded-xl px-3 py-2.5', tone === 'warn' && value > 0 ? 'bg-amber-50' : 'bg-navy-50/70')}>
      <div className={clsx('font-display text-xl font-bold tabular-nums', tone === 'warn' && value > 0 ? 'text-amber-700' : 'text-navy-900')}>{value}</div>
      <div className="mt-0.5 text-xs text-slate-500">{label}</div>
    </div>
  )
}

export default function ProviderCard({ p, busy, onToggle, onConnect, onCheck, onSync }: {
  p: Provider
  busy: string | null
  onToggle: (on: boolean) => void
  onConnect: () => void
  onCheck: () => void
  onSync: (full: boolean) => void
}) {
  const { t } = useTranslation()
  const L = useLang()
  const Icon = stateIcon[p.connection]
  const reading = busy === `${p.key}:sync` || busy === `${p.key}:full`
  const run = p.last_run
  const lastRead = run ? `${relativeTime(run.finished_at ?? run.started_at, new Date(), intlLocale())}` : ''
  const canRead = p.enabled && p.connection !== 'not_set_up'

  return (
    <article className="card flex flex-col gap-4 p-5" aria-label={L(p.name)}>
      <header className="flex items-start gap-3">
        <Mark name={L(p.name)} state={p.connection} />
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-base font-bold text-navy-900">{L(p.name)}</h3>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <Badge color={badgeTone[p.connection]}><Icon className="size-3.5" aria-hidden="true" />{t(`min.providers.status.${p.connection}`)}</Badge>
            {p.enabled && p.driver === 'http' && p.health !== 'unknown' && p.connection !== 'not_set_up' && <span className="text-xs text-slate-400">{t(`min.providers.health.${p.health}`)}{p.latency_ms ? ` · ${p.latency_ms} ms` : ''}</span>}
          </div>
        </div>
        <Switch checked={p.enabled} onChange={onToggle} label={t(p.enabled ? 'min.providers.switchOff' : 'min.providers.switchOn')} disabled={busy === `${p.key}:toggle`} />
      </header>

      <div className="grid grid-cols-3 gap-2.5">
        <Stat value={p.catalogue.total} label={t('min.providers.courses')} />
        <Stat value={p.catalogue.imported} label={t('min.providers.programmes')} />
        <Stat value={p.open_issues} label={t('min.providers.problems')} tone="warn" />
      </div>

      <div className="min-h-10 space-y-2 text-xs">
        {p.connection === 'not_set_up' && <p className="rounded-xl bg-amber-50 px-3 py-2 text-amber-800">{t('min.providers.needs', { fields: p.missing.map((k) => t(`min.providers.f.${k}`, { defaultValue: k })).join('، ') })}</p>}
        {p.connection === 'training' && <p className="text-slate-500">{t('min.providers.trainingNote')}</p>}
        {p.connection === 'off' && <p className="text-slate-500">{t('min.providers.offNote')}</p>}
        {run && p.enabled && (
          <p className="text-slate-500">
            {t('min.providers.lastRead')}: <span className="font-semibold text-navy-800">{lastRead}</span> · {t(`min.providers.run.${run.status}`)}
            {run.status !== 'failed' && ` · ${t('min.providers.runs.summary', { courses: run.courses_seen, done: run.completions_applied, issues: run.issues_found })}`}
          </p>
        )}
        {!run && p.enabled && p.connection !== 'not_set_up' && <p className="text-slate-500">{t('min.providers.lastRead')}: {t('min.providers.never')}</p>}
        {(run?.error || (p.last_error && p.connection === 'down')) && <p className="rounded-xl bg-red-50 px-3 py-2 text-red-700" dir="auto">{run?.error ?? p.last_error}</p>}
      </div>

      <footer className="mt-auto flex flex-wrap items-center gap-2">
        <Button size="sm" variant="outline" icon={<SlidersHorizontal className="size-4" aria-hidden="true" />} onClick={onConnect}>{t('min.providers.connect')}</Button>
        <Button size="sm" variant="ghost" icon={<Stethoscope className="size-4" aria-hidden="true" />} loading={busy === `${p.key}:check`} disabled={!canRead || p.driver === 'fake'} onClick={onCheck}>{t('min.providers.check')}</Button>
        <Button size="sm" variant="gold" className="ms-auto" icon={<RefreshCw className="size-4" aria-hidden="true" />} loading={reading} disabled={!canRead} onClick={() => onSync(false)}>{reading ? t('min.providers.reading') : t('min.providers.readNow')}</Button>
      </footer>
      {canRead && p.driver === 'http' && (
        <button type="button" className="-mt-2 self-start text-xs font-semibold text-slate-400 underline-offset-2 hover:text-navy-800 hover:underline disabled:opacity-50" disabled={reading} onClick={() => onSync(true)}>{t('min.providers.readFull')}</button>
      )}
    </article>
  )
}
