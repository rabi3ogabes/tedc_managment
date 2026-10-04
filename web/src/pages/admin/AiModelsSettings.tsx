import clsx from 'clsx'
import { AudioLines, Check, FileText, Film, Image as ImageIcon, KeyRound, Plug, Plus, Search, Sparkles, Trash2, TriangleAlert, Zap } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { Switch } from './notifications/shared'

type Task = 'text' | 'image' | 'audio' | 'video'
const TASKS: Task[] = ['text', 'image', 'audio', 'video']
const ICON: Record<Task, ReactNode> = { text: <FileText className="size-5" />, image: <ImageIcon className="size-5" />, audio: <AudioLines className="size-5" />, video: <Film className="size-5" /> }
type Driver = 'openrouter' | 'openai' | 'anthropic' | 'custom'
type Connection = { id: string; name: string; driver: Driver; base_url: string | null; enabled: boolean; has_key: boolean; key_hint: string | null }
type Test = { ok: boolean; ms: number | null; at: string; error: string | null }
type Model = { id: string; connection_id: string; model: string; label: string; tasks: Task[]; enabled: boolean; last_test: Test | null }
type State = { connections: Connection[]; models: Model[]; assignments: Record<Task, string | null> }
type CatalogRow = { id: string; name: string; tasks: Task[]; context: number | null; free: boolean; vision: boolean }

/** Settings → AI models: connect a provider such as OpenRouter with its key, add models, and choose which one does each kind of work. */
export default function AiModelsSettings() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: State }>('/admin/settings/ai-models')
  const [state, setState] = useState<State | null>(null)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ name: 'OpenRouter', driver: 'openrouter' as Driver, base_url: '', api_key: '' })
  const [picker, setPicker] = useState<string | null>(null)   // connection id whose models are being added
  const [catalog, setCatalog] = useState<CatalogRow[] | null>(null)
  const [query, setQuery] = useState('')
  const [only, setOnly] = useState<Task | 'all'>('all')
  const [manual, setManual] = useState({ model: '', tasks: ['text'] as Task[] })
  const [tests, setTests] = useState<Record<string, { ok: boolean; text: string }>>({})

  useEffect(() => { if (data && !state) setState(data.data) }, [data, state])
  const shown = useMemo(() => (catalog ?? []).filter((m) => (only === 'all' || m.tasks.includes(only)) && (`${m.id} ${m.name}`.toLowerCase().includes(query.toLowerCase()))).slice(0, 60), [catalog, query, only])
  if (isLoading || !state) return <Spinner />

  const run = async (key: string, job: () => Promise<unknown>, ok?: string) => {
    setBusy(key); setNote(null)
    try { await job(); ok && setNote({ ok: true, text: ok }) } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setBusy(null) }
  }
  const take = (r: { data: { data: State } }) => setState(r.data.data)
  const modelsOf = (task: Task) => state.models.filter((m) => m.tasks.includes(task) && m.enabled)

  const addConnection = () => run('conn', async () => {
    take(await api.post('/admin/settings/ai-models/connections', { name: form.name, driver: form.driver, base_url: form.driver === 'custom' ? form.base_url : null, api_key: form.api_key }))
    setAdding(false); setForm({ name: 'OpenRouter', driver: 'openrouter', base_url: '', api_key: '' })
  }, t('aimodels.connected'))
  const patchConnection = (c: Connection, extra: Record<string, unknown>) => run(`c${c.id}`, async () => take(await api.put(`/admin/settings/ai-models/connections/${c.id}`, { name: c.name, driver: c.driver, base_url: c.base_url, enabled: c.enabled, ...extra })))
  const addModel = (connection_id: string, model: string, label: string, tasks: Task[]) => run(`m${model}`, async () => {
    take(await api.post('/admin/settings/ai-models/models', { connection_id, model, label, tasks: tasks.length ? tasks : ['text'] }))
  }, t('aimodels.modelAdded'))
  const assign = (task: Task, id: string) => run(`a${task}`, async () => take(await api.put('/admin/settings/ai-models/assignments', { [task]: id || null })), t('aimodels.assigned'))
  const test = async (m: Model, task?: Task) => {
    setBusy(`t${m.id}`)
    try {
      const { data: r } = await api.post(`/admin/settings/ai-models/models/${m.id}/test`, task ? { task } : {})
      setState((s) => s && { ...s, models: r.data.models })
      setTests((x) => ({ ...x, [m.id]: r.data.ok ? { ok: true, text: `${r.data.ms} ms · ${r.data.sample ?? ''}` } : { ok: false, text: r.data.error === 'no_key' ? t('aimodels.noKey') : String(r.data.error) } }))
    } catch (e) { setTests((x) => ({ ...x, [m.id]: { ok: false, text: errorMessage(e) } })) } finally { setBusy(null) }
  }

  const openPicker = (id: string) => {
    setPicker(id); setCatalog(null); setQuery(''); setOnly('all')
    void run('catalog', async () => setCatalog((await api.get(`/admin/settings/ai-models/connections/${id}/catalog`)).data.data))
  }
  const have = (c: string, id: string) => state.models.some((m) => m.connection_id === c && m.model === id)

  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('aimodels.title')} subtitle={t('aimodels.subtitle')} actions={<Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('aimodels.addConnection')}</Button>} />
      {note && <div role="status" className={clsx('flex items-center gap-2 rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.ok ? <Check className="size-4" /> : <TriangleAlert className="size-4" />}{note.text}</div>}

      {/* Which model does which work */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {TASKS.map((task) => {
          const current = state.assignments[task]
          const options = modelsOf(task)
          return (
            <Card key={task} className="space-y-3">
              <div className="flex items-center gap-3">
                <span className="grid size-11 place-items-center rounded-xl bg-navy-900 text-gold-300">{ICON[task]}</span>
                <div className="min-w-0"><h3 className="font-bold text-navy-900">{t(`aimodels.tasks.${task}`)}</h3><p className="text-xs text-slate-500">{t(`aimodels.taskHint.${task}`)}</p></div>
              </div>
              <select className="input" value={current ?? ''} disabled={busy === `a${task}`} onChange={(e) => void assign(task, e.target.value)} aria-label={t(`aimodels.tasks.${task}`)}>
                <option value="">{t('aimodels.builtin')}</option>
                {options.map((m) => <option key={m.id} value={m.id}>{m.label || m.model}</option>)}
              </select>
              {task === 'video' && <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">{t('aimodels.videoNote')}</p>}
              <span className={clsx('inline-block rounded-full px-2.5 py-0.5 text-xs font-bold', current ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{current ? t('aimodels.active') : t('aimodels.fallback')}</span>
            </Card>
          )
        })}
      </div>

      {state.connections.length === 0 && !adding && (
        <Card className="grid place-items-center gap-3 py-12 text-center">
          <span className="grid size-16 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Sparkles className="size-8" /></span>
          <h2 className="text-lg font-bold text-navy-900">{t('aimodels.emptyTitle')}</h2>
          <p className="max-w-md text-sm text-slate-500">{t('aimodels.emptyHint')}</p>
          <Button variant="gold" icon={<Plug className="size-4" />} onClick={() => setAdding(true)}>{t('aimodels.connectOpenRouter')}</Button>
        </Card>
      )}

      {adding && (
        <Card className="space-y-4 border-2 border-gold-300">
          <h2 className="font-bold text-navy-900">{t('aimodels.addConnection')}</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('aimodels.provider')}>
              <select className="input" value={form.driver} onChange={(e) => { const driver = e.target.value as Driver; setForm((f) => ({ ...f, driver, name: t(`aimodels.drivers.${driver}`) })) }}>
                {(['openrouter', 'openai', 'anthropic', 'custom'] as Driver[]).map((d) => <option key={d} value={d}>{t(`aimodels.drivers.${d}`)}</option>)}
              </select>
            </Field>
            <Field label={t('aimodels.name')}><input className="input" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            {form.driver === 'custom' && <Field label={t('aimodels.baseUrl')} hint={t('aimodels.baseUrlHint')} className="sm:col-span-2"><input className="input" dir="ltr" placeholder="https://…/v1" value={form.base_url} onChange={(e) => setForm((f) => ({ ...f, base_url: e.target.value }))} /></Field>}
            <Field label={t('aimodels.apiKey')} hint={t('aimodels.keyHint')} className="sm:col-span-2"><input className="input" dir="ltr" type="password" autoComplete="off" placeholder={form.driver === 'openrouter' ? 'sk-or-…' : ''} value={form.api_key} onChange={(e) => setForm((f) => ({ ...f, api_key: e.target.value }))} /></Field>
          </div>
          <div className="flex gap-2"><Button variant="gold" loading={busy === 'conn'} disabled={!form.api_key || !form.name} icon={<KeyRound className="size-4" />} onClick={() => void addConnection()}>{t('aimodels.saveConnection')}</Button><Button variant="outline" onClick={() => setAdding(false)}>{t('aimodels.cancel')}</Button></div>
        </Card>
      )}

      {state.connections.map((c) => {
        const mine = state.models.filter((m) => m.connection_id === c.id)
        return (
          <Card key={c.id} className="space-y-5">
            <div className="flex flex-wrap items-center gap-3">
              <span className="grid size-11 place-items-center rounded-xl bg-navy-900 text-gold-300"><Plug className="size-5" /></span>
              <div className="min-w-0 flex-1">
                <h2 className="text-lg font-bold text-navy-900">{c.name}</h2>
                <p className="text-xs text-slate-500"><span dir="ltr">{t(`aimodels.drivers.${c.driver}`)}</span> · {c.has_key ? <span dir="ltr">{c.key_hint}</span> : <span className="font-bold text-danger">{t('aimodels.noKey')}</span>}</p>
              </div>
              <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">{t('aimodels.enabled')}<Switch checked={c.enabled} label={t('aimodels.enabled')} onChange={(v) => void patchConnection({ ...c, enabled: v }, {})} /></label>
              <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => openPicker(c.id)}>{t('aimodels.addModel')}</Button>
              <button type="button" aria-label={t('aimodels.delete')} className="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                onClick={() => { if (window.confirm(t('aimodels.confirmDelete'))) void run(`d${c.id}`, async () => take(await api.delete(`/admin/settings/ai-models/connections/${c.id}`))) }}><Trash2 className="size-4" /></button>
            </div>

            <ReplaceKey t={t} onSave={(key) => patchConnection(c, { api_key: key })} />

            {picker === c.id && (
              <div className="space-y-3 rounded-2xl bg-ivory p-4">
                <div className="flex flex-wrap items-center gap-2">
                  <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('aimodels.search')} value={query} onChange={(e) => setQuery(e.target.value)} dir="ltr" /></div>
                  {(['all', ...TASKS] as const).map((x) => <button key={x} type="button" onClick={() => setOnly(x)} className={clsx('rounded-full px-3 py-1.5 text-xs font-bold', only === x ? 'bg-navy-900 text-white' : 'bg-white text-slate-600')}>{x === 'all' ? t('aimodels.all') : t(`aimodels.tasks.${x}`)}</button>)}
                  <button type="button" className="text-xs font-bold text-slate-400" onClick={() => setPicker(null)}>{t('aimodels.close')}</button>
                </div>
                {catalog === null ? (busy === 'catalog' ? <Spinner /> : <p className="text-sm text-slate-500">{t('aimodels.catalogFailed')}</p>) : (
                  <ul className="grid max-h-80 gap-2 overflow-auto sm:grid-cols-2">
                    {shown.map((m) => (
                      <li key={m.id} className="flex items-center gap-3 rounded-xl bg-white p-3">
                        <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-navy-900" dir="ltr">{m.name}</div><div className="truncate text-xs text-slate-400" dir="ltr">{m.id}</div>
                          <div className="mt-1 flex flex-wrap gap-1">{m.tasks.map((x) => <span key={x} className="rounded-full bg-navy-50 px-2 py-0.5 text-[10px] font-bold text-navy-700">{t(`aimodels.tasks.${x}`)}</span>)}{m.free && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">{t('aimodels.free')}</span>}</div></div>
                        {have(c.id, m.id) ? <Check className="size-5 text-emerald-600" /> : <Button size="sm" variant="outline" loading={busy === `m${m.id}`} onClick={() => void addModel(c.id, m.id, m.name, m.tasks)}>{t('aimodels.add')}</Button>}
                      </li>
                    ))}
                    {shown.length === 0 && <li className="text-sm text-slate-500">{t('aimodels.noResults')}</li>}
                  </ul>
                )}
                <div className="flex flex-wrap items-end gap-2 border-t border-navy-100 pt-3">
                  <Field label={t('aimodels.manualId')} className="min-w-56 flex-1"><input className="input" dir="ltr" placeholder="vendor/model-name" value={manual.model} onChange={(e) => setManual((m) => ({ ...m, model: e.target.value }))} /></Field>
                  <div className="flex gap-1 pb-1">{TASKS.map((x) => <button key={x} type="button" aria-pressed={manual.tasks.includes(x)} onClick={() => setManual((m) => ({ ...m, tasks: m.tasks.includes(x) ? m.tasks.filter((y) => y !== x) : [...m.tasks, x] }))} className={clsx('rounded-full px-3 py-1.5 text-xs font-bold', manual.tasks.includes(x) ? 'bg-gold-300 text-navy-900' : 'bg-white text-slate-500')}>{t(`aimodels.tasks.${x}`)}</button>)}</div>
                  <Button variant="gold" disabled={!manual.model.trim()} onClick={() => void addModel(c.id, manual.model.trim(), manual.model.trim(), manual.tasks).then(() => setManual((m) => ({ ...m, model: '' })))}>{t('aimodels.add')}</Button>
                </div>
              </div>
            )}

            {mine.length === 0 ? <p className="text-sm text-slate-500">{t('aimodels.noModels')}</p> : (
              <ul className="divide-y divide-navy-50">
                {mine.map((m) => {
                  const r = tests[m.id]
                  const last = m.last_test
                  return (
                    <li key={m.id} className="flex flex-wrap items-center gap-3 py-3">
                      <div className="min-w-0 flex-1">
                        <div className="truncate font-bold text-navy-900" dir="auto">{m.label || m.model}</div>
                        <div className="truncate text-xs text-slate-400" dir="ltr">{m.model}</div>
                        <div className="mt-1 flex flex-wrap gap-1">
                          {TASKS.map((x) => (
                            <button key={x} type="button" aria-pressed={m.tasks.includes(x)} title={t(`aimodels.toggleTask`)}
                              onClick={() => { const tasks = m.tasks.includes(x) ? m.tasks.filter((y) => y !== x) : [...m.tasks, x]; if (tasks.length) void run(`m${m.id}`, async () => take(await api.put(`/admin/settings/ai-models/models/${m.id}`, { connection_id: m.connection_id, model: m.model, label: m.label, tasks, enabled: m.enabled }))) }}
                              className={clsx('rounded-full px-2.5 py-0.5 text-[11px] font-bold', m.tasks.includes(x) ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-400')}>{t(`aimodels.tasks.${x}`)}</button>
                          ))}
                        </div>
                      </div>
                      {(r || last) && <span className={clsx('max-w-72 truncate rounded-full px-3 py-1 text-xs font-bold', (r ? r.ok : last!.ok) ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')} title={r?.text ?? last?.error ?? ''} dir="auto">{r ? r.text : last!.ok ? `${last!.ms} ms` : last!.error}</span>}
                      <Button size="sm" variant="outline" icon={<Zap className="size-4" />} loading={busy === `t${m.id}`} disabled={!c.has_key} onClick={() => void test(m)}>{t('aimodels.test')}</Button>
                      <Switch small checked={m.enabled} label={t('aimodels.enabled')} onChange={(v) => void run(`m${m.id}`, async () => take(await api.put(`/admin/settings/ai-models/models/${m.id}`, { connection_id: m.connection_id, model: m.model, label: m.label, tasks: m.tasks, enabled: v })))} />
                      <button type="button" aria-label={t('aimodels.delete')} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" onClick={() => void run(`m${m.id}`, async () => take(await api.delete(`/admin/settings/ai-models/models/${m.id}`)))}><Trash2 className="size-4" /></button>
                    </li>
                  )
                })}
              </ul>
            )}
          </Card>
        )
      })}
      {state.connections.length > 0 && <p className="text-xs text-slate-400">{t('aimodels.secure')}</p>}
    </div>
  )
}

/** Changing the key: the old one is never shown, only replaced. */
function ReplaceKey({ t, onSave }: { t: (k: string) => string; onSave: (key: string) => Promise<unknown> }) {
  const [open, setOpen] = useState(false)
  const [key, setKey] = useState('')
  if (!open) return <button type="button" className="text-xs font-bold text-navy-700 underline" onClick={() => setOpen(true)}>{t('aimodels.replaceKey')}</button>
  return (
    <div className="flex flex-wrap items-end gap-2">
      <Field label={t('aimodels.newKey')} className="min-w-64 flex-1"><input className="input" dir="ltr" type="password" autoComplete="off" value={key} onChange={(e) => setKey(e.target.value)} /></Field>
      <Button variant="gold" disabled={!key} onClick={() => void onSave(key).then(() => { setKey(''); setOpen(false) })}>{t('aimodels.saveKey')}</Button>
      <Button variant="outline" onClick={() => setOpen(false)}>{t('aimodels.cancel')}</Button>
    </div>
  )
}
