/* eslint-disable @typescript-eslint/no-explicit-any */
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import type { Lesson } from './api'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** Attaches a content package (upload or pick) and the unit to play; the lesson can require a pass. */
export function PackageCard({ lesson, onSaved }: { lesson: Lesson; onSaved: () => void }) {
  const { t } = useTranslation()
  const list = useGet<{ data: any[] }>('/admin/packages', undefined, { staleTime: 0 })
  const file = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [pkgId, setPkgId] = useState<string>(lesson.package_id ?? '')
  const [unit, setUnit] = useState<string>(lesson.package_item_id ?? '')
  const detail = useGet<{ data: any }>(pkgId ? `/admin/packages/${pkgId}` : null, undefined, { staleTime: 0 })
  const upload = async (f: File) => {
    const fd = new FormData(); fd.append('file', f)
    setBusy(true)
    try { const { data } = await api.post('/admin/packages', fd); toast(data.data.title); setPkgId(data.data.id); setUnit(''); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const save = async () => { try { await api.put(`/admin/course/lessons/${lesson.id}/package`, { package_id: pkgId, item_id: unit || null }); toast(t('common.saved')); onSaved() } catch (e) { toast(errorMessage(e), 'error') } }
  const units: any[] = (detail.data?.data.entry_points ?? []).filter((e: any) => e.href)
  return (
    <Card className="space-y-3">
      <h3 className="font-bold text-navy-900">{t('content.lesson.package')}</h3>
      <div className="flex flex-wrap items-center gap-2"><Button size="sm" variant="outline" loading={busy} onClick={() => file.current?.click()}>{busy ? t('content.lesson.uploading') : t('content.lesson.upload')}</Button><input ref={file} type="file" hidden accept=".zip,.h5p,.imscc" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} /></div>
      <Field label={t('content.lesson.pickPackage')}>{list.isLoading ? <Spinner /> : <select className={input} value={pkgId} onChange={(e) => { setPkgId(e.target.value); setUnit('') }}><option value="">—</option>{(list.data?.data ?? []).filter((p) => p.standard !== 'cc').map((p) => <option key={p.id} value={p.id}>{p.title} · {t(`content.lesson.standards.${p.standard}`)}</option>)}</select>}</Field>
      {units.length > 1 && <Field label={t('content.lesson.unit')}><select className={input} value={unit} onChange={(e) => setUnit(e.target.value)}><option value="">1</option>{units.map((u) => <option key={u.id} value={u.id}>{u.title}</option>)}</select></Field>}
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!lesson.settings.require_pass} onChange={async (e) => { try { await api.put(`/admin/course/lessons/${lesson.id}`, { settings: { ...lesson.settings, require_pass: e.target.checked } }); onSaved() } catch (x) { toast(errorMessage(x), 'error') } }} /> {t('content.lesson.requirePass')}</label>
      <div className="flex gap-2"><Button variant="gold" disabled={!pkgId} onClick={() => void save()}>{t('common.save')}</Button>{detail.data && <Button variant="outline" onClick={() => window.open(detail.data!.data.preview_url, '_blank', 'noopener')}>{t('content.lesson.preview')}</Button>}{detail.data && <Badge>{t(`content.lesson.standards.${detail.data.data.standard}`)}</Badge>}</div>
    </Card>
  )
}

/** Chooses the LTI tool of a lesson, or fetches content from it (Deep Linking). */
export function LtiCard({ lesson, onSaved }: { lesson: Lesson; onSaved: () => void }) {
  const { t } = useTranslation()
  const tools = useGet<{ data: any[] }>('/admin/lti-tools')
  const [tool, setTool] = useState(lesson.lti_tool_id ?? '')
  const save = async () => { try { await api.put(`/admin/course/lessons/${lesson.id}`, { lti_tool_id: tool || null }); toast(t('common.saved')); onSaved() } catch (e) { toast(errorMessage(e), 'error') } }
  const deepLink = async () => {
    try {
      const { data } = await api.post(`/admin/lti-tools/${tool}/deep-link`, { module_id: (lesson as any).module_id })
      const w = window.open('', 'lti-deeplink', 'width=900,height=700')
      if (!w) return
      const f = w.document.createElement('form'); f.method = 'POST'; f.action = data.data.action
      Object.entries<string>(data.data.fields).forEach(([k, v]) => { const i = w.document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i) })
      w.document.body.appendChild(f); f.submit()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('content.lesson.lti')}</h3>
      <Field label={t('content.lesson.tool')}><select className={input} value={tool} onChange={(e) => setTool(e.target.value)}><option value="">—</option>{(tools.data?.data ?? []).filter((x) => x.is_active || x.id === tool).map((x) => <option key={x.id} value={x.id}>{x.name} · {x.version}</option>)}</select></Field>
      <div className="flex gap-2"><Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button><Button variant="outline" disabled={!tool} onClick={() => void deepLink()}>{t('content.lesson.selectFromTool')}</Button></div>
    </Card>
  )
}

/** Version history: publish a new version (what happens to learners in progress), compare, restore, archive. */
export function VersionsCard({ lesson, onSaved }: { lesson: Lesson; onSaved: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: { current: number; versions: any[] } }>(`/admin/course/lessons/${lesson.id}/versions`, undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ note: '', learners: 'keep' })
  const [diff, setDiff] = useState<any | null>(null)
  const publish = async () => { try { await api.post(`/admin/course/lessons/${lesson.id}/versions`, f); toast(t('content.versions.published')); setOpen(false); await res.refetch(); onSaved() } catch (e) { toast(errorMessage(e), 'error') } }
  const act = async (v: number, what: 'restore' | 'archive') => { try { what === 'restore' ? await api.post(`/admin/course/lessons/${lesson.id}/versions/${v}/restore`) : await api.put(`/admin/course/lessons/${lesson.id}/versions/${v}/archive`); await res.refetch(); onSaved() } catch (e) { toast(errorMessage(e), 'error') } }
  const compare = async (v: number) => { try { const { data } = await api.get(`/admin/course/lessons/${lesson.id}/versions/diff`, { params: { from: v, to: res.data!.data.current } }); setDiff({ ...data.data, v }) } catch (e) { toast(errorMessage(e), 'error') } }
  const d = res.data?.data
  return (
    <Card className="space-y-3"><div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('content.versions.title')}</h3><Button size="sm" variant="outline" onClick={() => setOpen(true)}>{t('content.versions.publish')}</Button></div>
      {!d ? <Spinner /> : d.versions.map((v) => (
        <div key={v.id} className="flex flex-wrap items-center gap-2 text-sm"><b>v{v.version}</b>{v.version === d.current && <Badge color="green">{t('content.versions.current')}</Badge>}{v.is_archived && <Badge color="gray">{t('content.versions.archived')}</Badge>}<span className="flex-1 text-xs text-slate-500">{v.note}</span>
          {v.version !== d.current && <><Button size="sm" variant="ghost" onClick={() => void compare(v.version)}>{t('content.versions.diff')}</Button><Button size="sm" variant="ghost" onClick={() => void act(v.version, 'restore')}>{t('content.versions.restore')}</Button>{!v.is_archived && <Button size="sm" variant="ghost" onClick={() => void act(v.version, 'archive')}>{t('content.versions.archive')}</Button>}</>}</div>))}
      <Modal open={open} onClose={() => setOpen(false)} title={t('content.versions.publish')}>
        <div className="space-y-3"><Field label={t('content.versions.note')}><input className={input} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></Field>
          <Field label={t('content.versions.learners')}><select className={input} value={f.learners} onChange={(e) => setF({ ...f, learners: e.target.value })}><option value="keep">{t('content.versions.keep')}</option><option value="move">{t('content.versions.move')}</option></select></Field><Button variant="gold" onClick={() => void publish()}>{t('content.versions.publish')}</Button></div>
      </Modal>
      <Modal open={!!diff} onClose={() => setDiff(null)} title={t('content.versions.diff')}>
        {diff && (diff.changed.length ? <ul className="space-y-1 text-sm">{diff.changed.map((c: any) => <li key={c.field}><b>{c.field}</b>: <span className="text-danger line-through">{String(c.from ?? '—').slice(0, 80)}</span> → <span className="text-emerald-700">{String(c.to ?? '—').slice(0, 80)}</span></li>)}</ul> : <p className="text-sm">{t('content.versions.noChanges')}</p>)}
      </Modal>
    </Card>
  )
}
