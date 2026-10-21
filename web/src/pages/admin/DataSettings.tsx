import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, CircleDashed, Clock3, Database, FileSpreadsheet, Loader2, RotateCw, ShieldAlert, Trash2, UploadCloud, Users, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { putFile, type SignedUpload } from '@/lib/upload'

type Step = { step: 'setup' | 'people' | 'courses'; part: number; parts: number; done: boolean; seconds: number | null }
type Run = {
  id: string; status: 'uploading' | 'ready' | 'running' | 'done' | 'failed'; files: { name: string; size: number }[]; steps: Step[]; done_steps: number; total_steps: number
  stats: Record<string, number>; issues: { code: string; empno?: string | null }[]; report: Record<string, number | boolean> | null; error: string | null; by: string | null; created_at: string | null; finished_at: string | null
}
type Preview = { purged: { at: string; admin: string } | null; imported_people: number; total: number; table_count: number; tables: { table: string; rows: number }[]; kept: { people: number; courses: number; schools: number; setup_tables: number }; confirmation: string }

/** Settings → Data: the Ministry's trainee extract imported from the browser, step by step, and the demo data removed once. */
export default function DataSettings() {
  const { t } = useTranslation()
  return (
    <div className="space-y-6">
      <div className="flex items-start gap-3 rounded-2xl border border-gold-300/60 bg-gold-50/60 p-4 text-sm leading-7 text-navy-900">
        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-gold-700" />
        <p>{t('dataset.intro')}</p>
      </div>
      <ImportCard />
      <PurgeCard />
    </div>
  )
}

function stepLabel(t: (k: string, o?: Record<string, unknown>) => string, s: Step, files: { name: string }[]) {
  if (s.step === 'setup') return t('dataset.steps.setup')
  if (s.step === 'people') return t('dataset.steps.people', { k: s.part + 1, n: s.parts })
  return t('dataset.steps.courses', { k: s.part + 1, name: files[s.part]?.name ?? '' })
}

function ImportCard() {
  const { t } = useTranslation()
  const history = useGet<{ data: Run[] }>('/admin/imports')
  const [picked, setPicked] = useState<File[]>([])
  const [run, setRun] = useState<Run | null>(null)
  const [uploads, setUploads] = useState<Record<number, number>>({})
  const [busy, setBusy] = useState<'upload' | 'steps' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [drag, setDrag] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  const stop = useRef(false)

  // A run left unfinished (closed tab, network) is offered again.
  const open = history.data?.data.find((r) => r.status !== 'done' && r.status !== 'uploading')
  const current = run ?? open ?? null
  const last = history.data?.data.find((r) => r.status === 'done')

  useEffect(() => {
    if (!busy) return
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [busy])

  const add = (list: FileList | null) => {
    const files = Array.from(list ?? []).filter((f) => /\.xlsx$/i.test(f.name))
    setPicked((cur) => [...cur, ...files.filter((f) => !cur.some((c) => c.name === f.name && c.size === f.size))].slice(0, 10))
  }

  const advance = async (id: string) => {
    setBusy('steps'); setError(null); stop.current = false
    try {
      for (let i = 0; i < 60 && !stop.current; i++) {
        const { data } = await api.post(`/admin/imports/${id}/step`, {}, { timeout: 330000 })
        setRun(data.data as Run)
        if ((data.data as Run).status === 'done') break
      }
    } catch (e) {
      setError(errorMessage(e))
      try { const { data } = await api.get(`/admin/imports/${id}`); setRun(data.data as Run) } catch { /* keep what we have */ }
    } finally {
      setBusy(null)
      void history.refetch()
    }
  }

  const start = async () => {
    if (picked.length === 0) return
    setBusy('upload'); setError(null); setUploads({})
    try {
      const { data } = await api.post('/admin/imports/trainee-history', { files: picked.map((f) => ({ name: f.name, size: f.size })) })
      const created = data.data as Run & { uploads: SignedUpload[] }
      setRun(created)
      for (let i = 0; i < picked.length; i++) {
        await putFile(created.uploads[i], picked[i], (p) => setUploads((u) => ({ ...u, [i]: p })))
      }
      const ready = await api.post(`/admin/imports/${created.id}/uploaded`)
      setRun(ready.data.data as Run)
      setPicked([])
      await advance(created.id)
    } catch (e) {
      setError(errorMessage(e))
      setBusy(null)
    }
  }

  const st = current?.stats ?? {}
  const report = current?.status === 'done' ? current.report : null
  const percent = current?.total_steps ? Math.round((current.done_steps / current.total_steps) * 100) : 0

  return (
    <Card>
      <CardTitle subtitle={t('dataset.import.hint')}><span className="inline-flex items-center gap-2"><FileSpreadsheet className="size-5 text-gold-600" />{t('dataset.import.title')}</span></CardTitle>

      {(!current || current.status === 'done') && busy !== 'upload' && (
        <>
          <div onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={() => setDrag(false)} onDrop={(e) => { e.preventDefault(); setDrag(false); add(e.dataTransfer.files) }}
            className={clsx('flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-6 py-8 text-center transition', drag ? 'border-gold-500 bg-gold-50' : 'border-navy-100 bg-ivory/60')}>
            <UploadCloud className="size-9 text-gold-600" />
            <p className="font-bold text-navy-900">{t('dataset.import.drop')}</p>
            <p className="text-xs text-slate-500">{t('dataset.import.dropHint')}</p>
            <Button variant="outline" size="sm" onClick={() => input.current?.click()}>{t('dataset.import.choose')}</Button>
            <input ref={input} type="file" accept=".xlsx" multiple hidden onChange={(e) => { add(e.target.files); e.target.value = '' }} />
          </div>
          {picked.length > 0 && (
            <ul className="mt-4 divide-y divide-navy-100 rounded-2xl border border-navy-100">
              {picked.map((f, i) => (
                <li key={f.name + f.size} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                  <FileSpreadsheet className="size-4 shrink-0 text-emerald-600" /><span className="min-w-0 flex-1 truncate font-semibold text-navy-900" dir="auto">{f.name}</span>
                  <span className="text-xs text-slate-500" dir="ltr">{fmt.size(f.size)}</span>
                  <button type="button" onClick={() => setPicked((p) => p.filter((_, j) => j !== i))} aria-label={t('common.remove', { defaultValue: 'Remove' }) as string} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-danger"><X className="size-4" /></button>
                </li>
              ))}
            </ul>
          )}
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Button variant="gold" icon={<UploadCloud className="size-4" />} disabled={picked.length === 0} onClick={start}>{picked.length ? t('dataset.import.start', { n: picked.length }) : t('dataset.import.startEmpty')}</Button>
            <span className="text-xs text-slate-500">{t('dataset.import.safe')}</span>
          </div>
        </>
      )}

      {busy === 'upload' && (
        <div className="space-y-3">
          <p className="flex items-center gap-2 text-sm font-bold text-navy-900"><Loader2 className="size-4 animate-spin" />{t('dataset.import.uploading')}</p>
          {picked.map((f, i) => (
            <div key={f.name} className="text-xs">
              <div className="mb-1 flex justify-between"><span dir="auto">{f.name}</span><span dir="ltr">{uploads[i] ?? 0}%</span></div>
              <div className="h-2 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full bg-gold-500 transition-[width]" style={{ width: `${uploads[i] ?? 0}%` }} /></div>
            </div>
          ))}
        </div>
      )}

      {current && current.status !== 'done' && busy !== 'upload' && (
        <div className="space-y-4">
          <div>
            <div className="mb-1.5 flex items-center justify-between text-sm"><span className="font-bold text-navy-900">{t('dataset.import.progress', { done: current.done_steps, total: current.total_steps })}</span><span className="font-mono text-xs text-slate-500" dir="ltr">{percent}%</span></div>
            <div className="h-3 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full bg-gradient-to-l from-gold-500 to-gold-600 transition-[width] duration-500" style={{ width: `${percent}%` }} /></div>
          </div>
          <ol className="grid gap-1.5 sm:grid-cols-2">
            {current.steps.map((s, i) => {
              const next = !s.done && current.steps.findIndex((x) => !x.done) === i
              return (
                <li key={i} className={clsx('flex items-center gap-2 rounded-xl px-3 py-2 text-xs', s.done ? 'bg-emerald-50 text-emerald-800' : next ? 'bg-gold-50 font-bold text-navy-900' : 'bg-ivory text-slate-500')}>
                  {s.done ? <CheckCircle2 className="size-4 shrink-0" /> : next && busy ? <Loader2 className="size-4 shrink-0 animate-spin" /> : <CircleDashed className="size-4 shrink-0" />}
                  <span className="min-w-0 flex-1 truncate" dir="auto">{stepLabel(t, s, current.files)}</span>
                  {s.seconds !== null && <span className="font-mono text-[10px]" dir="ltr">{s.seconds}s</span>}
                </li>
              )
            })}
          </ol>
          <Counters st={st} />
          {(error || current.error) && <div role="alert" className="flex items-start gap-2 rounded-2xl bg-red-50 p-3 text-sm text-danger"><AlertTriangle className="mt-0.5 size-4 shrink-0" /><span>{error ?? current.error}</span></div>}
          {busy === 'steps'
            ? <p className="flex items-center gap-2 text-xs text-slate-500"><Clock3 className="size-3.5" />{t('dataset.import.keepOpen')}</p>
            : <Button variant="gold" icon={<RotateCw className="size-4" />} onClick={() => advance(current.id)}>{t('dataset.import.resume')}</Button>}
        </div>
      )}

      {report && <Report report={report} issues={current?.issues ?? []} onNew={() => setRun(null)} />}
      {!current && last?.report && (
        <p className="mt-4 rounded-2xl bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
          {t('dataset.import.lastRun', { date: fmt.dateTime(last.finished_at), people: fmt.number(Number(last.report.people ?? 0)), courses: fmt.number(Number(last.report.activities_imported ?? 0) + Number(last.report.activities_existing ?? 0)) })}
        </p>
      )}
    </Card>
  )
}

function Counters({ st }: { st: Record<string, number> }) {
  const { t } = useTranslation()
  const items: [string, number | undefined][] = [['rows', st.source_rows], ['people', st.people], ['peopleNew', st.people_created], ['courses', st.activities_imported], ['schoolsNew', st.schools_created]]
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
      {items.map(([k, v]) => (
        <div key={k} className="rounded-xl border border-navy-100 bg-white px-3 py-2"><div className="font-display text-lg font-bold text-navy-900">{v === undefined ? '—' : fmt.number(v)}</div><div className="text-[11px] text-slate-500">{t(`dataset.n.${k}`)}</div></div>
      ))}
    </div>
  )
}

function Report({ report: r, issues, onNew }: { report: Record<string, number | boolean>; issues: { code: string }[]; onNew: () => void }) {
  const { t } = useTranslation()
  const n = (k: string) => fmt.number(Number(r[k] ?? 0))
  const byCode = issues.reduce<Record<string, number>>((a, i) => ({ ...a, [i.code]: (a[i.code] ?? 0) + 1 }), {})
  const ok = r.reconciled === true
  return (
    <div className="mt-2 space-y-4">
      <div className={clsx('flex items-center gap-3 rounded-2xl p-4', ok ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-danger')}>
        {ok ? <CheckCircle2 className="size-6 shrink-0" /> : <AlertTriangle className="size-6 shrink-0" />}
        <div><div className="font-bold">{ok ? t('dataset.report.ok') : t('dataset.report.notOk')}</div><div className="text-xs opacity-80">{t('dataset.report.time', { s: n('seconds') })}</div></div>
      </div>
      <dl className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
        {([
          ['rows', n('source_rows'), { a: n('rejected_rows') }], ['people', n('people'), { a: n('people_created'), b: n('people_updated') }], ['numbers', n('employee_numbers'), null],
          ['schools', fmt.number(Number(r.schools_matched ?? 0) + Number(r.schools_created ?? 0)), { a: n('schools_matched'), b: n('schools_created') }],
          ['courses', fmt.number(Number(r.activities_imported ?? 0) + Number(r.activities_existing ?? 0)), { a: n('activities_imported'), b: n('activities_existing'), c: n('activities_rejected') }],
          ['hours', n('source_hours'), { a: n('imported_hours') }],
        ] as [string, string, Record<string, string> | null][]).map(([k, main, sub]) => (
          <div key={k} className="rounded-2xl border border-navy-100 bg-white p-3">
            <dt className="text-xs font-semibold text-slate-500">{t(`dataset.report.${k}`)}</dt>
            <dd className="mt-1 font-display text-xl font-bold text-navy-900">{main}</dd>
            {sub && <dd className="text-[11px] text-slate-500">{t(`dataset.report.${k}Sub`, sub)}</dd>}
          </div>
        ))}
      </dl>
      {Object.keys(byCode).length > 0 && (
        <div className="rounded-2xl border border-gold-300/60 bg-gold-50/50 p-3 text-xs">
          <div className="mb-1 font-bold text-navy-900">{t('dataset.report.issues')}</div>
          <ul className="flex flex-wrap gap-2">{Object.entries(byCode).map(([c, k]) => <li key={c}><Badge color="gold">{t(`dataset.issue.${c}`, { defaultValue: c })} · {k}</Badge></li>)}</ul>
        </div>
      )}
      <Button variant="outline" icon={<UploadCloud className="size-4" />} onClick={onNew}>{t('dataset.import.another')}</Button>
    </div>
  )
}

function PurgeCard() {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const preview = useGet<{ data: Preview }>('/admin/system/demo-data', undefined, { retry: false })
  const [form, setForm] = useState({ admin_name: '', admin_email: '', password: '', password_confirmation: '', confirmation: '' })
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [done, setDone] = useState<{ removed_rows: number; tables: number; backup: { path: string; rows: number }; admin: { email: string }; signed_out: boolean } | null>(null)
  const p = preview.data?.data

  const submit = async () => {
    setSaving(true); setErrors({}); setMessage(null)
    try {
      const { data } = await api.post('/admin/system/demo-data/purge', form, { timeout: 330000 })
      setDone(data.data)
    } catch (e) {
      const res = (e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data
      if (res?.errors) setErrors(Object.fromEntries(Object.entries(res.errors).map(([k, v]) => [k, v[0]])))
      setMessage(errorMessage(e))
    } finally { setSaving(false) }
  }

  if (preview.isError) {
    return (
      <Card>
        <CardTitle><span className="inline-flex items-center gap-2"><Trash2 className="size-5 text-danger" />{t('dataset.purge.title')}</span></CardTitle>
        <div role="alert" className="flex flex-wrap items-center gap-3 rounded-2xl bg-red-50 p-4 text-sm text-danger">
          <AlertTriangle className="size-5 shrink-0" /><span className="flex-1">{t('dataset.purge.previewFailed')} {errorMessage(preview.error)}</span>
          <Button variant="outline" size="sm" icon={<RotateCw className="size-4" />} onClick={() => void preview.refetch()}>{t('dataset.purge.retry')}</Button>
        </div>
      </Card>
    )
  }
  if (preview.isLoading || !p) return <Card><div className="flex items-center justify-center gap-3 py-8 text-sm text-slate-500"><Spinner />{t('dataset.purge.computing')}</div></Card>
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }))

  return (
    <Card className={clsx(!p.purged && !done && 'border-red-200')}>
      <CardTitle subtitle={t('dataset.purge.hint')}><span className="inline-flex items-center gap-2"><Trash2 className="size-5 text-danger" />{t('dataset.purge.title')}</span></CardTitle>

      {done ? (
        <div className="space-y-3">
          <div className="flex items-start gap-3 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900"><CheckCircle2 className="mt-0.5 size-5 shrink-0" />
            <div><div className="font-bold">{t('dataset.purge.done', { rows: fmt.number(done.removed_rows), tables: done.tables })}</div>
              <div className="mt-1 text-xs">{t('dataset.purge.backup', { rows: fmt.number(done.backup.rows) })} <span className="font-mono" dir="ltr">{done.backup.path}</span></div></div></div>
          {done.signed_out && (
            <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-navy-100 p-4 text-sm"><span className="flex-1">{t('dataset.purge.signIn', { email: done.admin.email })}</span>
              <Button variant="gold" onClick={() => { logout(); window.location.assign('/login') }}>{t('dataset.purge.goSignIn')}</Button></div>
          )}
        </div>
      ) : p.purged ? (
        <p className="flex items-center gap-2 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><CheckCircle2 className="size-4" />{t('dataset.purge.already', { date: fmt.dateTime(p.purged.at), admin: p.purged.admin })}</p>
      ) : p.imported_people === 0 ? (
        <p className="flex items-center gap-2 rounded-2xl bg-ivory px-4 py-3 text-sm text-slate-600"><Database className="size-4" />{t('dataset.purge.importFirst')}</p>
      ) : (
        <div className="space-y-5">
          <div className="grid gap-3 lg:grid-cols-2">
            <div className="rounded-2xl border border-red-200 bg-red-50/60 p-4">
              <div className="text-xs font-bold text-danger">{t('dataset.purge.goes')}</div>
              <div className="mt-1 font-display text-2xl font-bold text-navy-900">{fmt.number(p.total)}</div>
              <div className="text-xs text-slate-600">{t('dataset.purge.goesSub', { tables: p.table_count })}</div>
              <details className="mt-2 text-xs"><summary className="cursor-pointer font-semibold text-navy-800">{t('dataset.purge.details')}</summary>
                <ul className="mt-2 grid max-h-48 grid-cols-2 gap-x-4 gap-y-0.5 overflow-y-auto font-mono text-[11px]" dir="ltr">{p.tables.map((x) => <li key={x.table} className="flex justify-between"><span className="truncate">{x.table}</span><span>{fmt.number(x.rows)}</span></li>)}</ul></details>
            </div>
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
              <div className="text-xs font-bold text-emerald-800">{t('dataset.purge.stays')}</div>
              <ul className="mt-2 space-y-1 text-sm text-navy-900">
                <li className="flex items-center gap-2"><Users className="size-4 text-emerald-700" />{t('dataset.purge.staysPeople', { n: fmt.number(p.kept.people), c: fmt.number(p.kept.courses) })}</li>
                <li>{t('dataset.purge.staysSchools', { n: fmt.number(p.kept.schools) })}</li>
                <li>{t('dataset.purge.staysSetup')}</li>
              </ul>
            </div>
          </div>
          <div>
            <h4 className="mb-2 text-sm font-bold text-navy-900">{t('dataset.purge.adminTitle')}</h4>
            <p className="mb-3 text-xs leading-6 text-slate-500">{t('dataset.purge.adminHint')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('dataset.purge.name')}><input className="input" value={form.admin_name} onChange={set('admin_name')} autoComplete="name" />{errors.admin_name && <p className="mt-1 text-xs text-danger">{errors.admin_name}</p>}</Field>
              <Field label={t('dataset.purge.email')}><input className="input" type="email" dir="ltr" value={form.admin_email} onChange={set('admin_email')} autoComplete="email" />{errors.admin_email && <p className="mt-1 text-xs text-danger">{errors.admin_email}</p>}</Field>
              <Field label={t('dataset.purge.password')}><input className="input" type="password" dir="ltr" value={form.password} onChange={set('password')} autoComplete="new-password" />{errors.password && <p className="mt-1 text-xs text-danger">{errors.password}</p>}</Field>
              <Field label={t('dataset.purge.password2')}><input className="input" type="password" dir="ltr" value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" /></Field>
            </div>
          </div>
          <Field label={t('dataset.purge.confirm', { phrase: p.confirmation })}>
            <input className="input" value={form.confirmation} onChange={set('confirmation')} placeholder={p.confirmation} />{errors.confirmation && <p className="mt-1 text-xs text-danger">{errors.confirmation}</p>}
          </Field>
          {message && !Object.keys(errors).length && <div role="alert" className="rounded-2xl bg-red-50 p-3 text-sm text-danger">{message}</div>}
          <Button variant="danger" icon={<Trash2 className="size-4" />} loading={saving}
            disabled={form.confirmation.trim() !== p.confirmation || !form.admin_email || !form.admin_name || form.password.length < 8 || form.password !== form.password_confirmation} onClick={submit}>
            {t('dataset.purge.button')}
          </Button>
        </div>
      )}
    </Card>
  )
}
