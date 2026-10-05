import clsx from 'clsx'
import { FileUp, Layers, Pencil, Plus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import SchoolPicker, { type SchoolRow } from '@/components/admin/SchoolPicker'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Group = { id: string; code: string; name_ar: string; name_en: string; type: string; description: string | null; schools_count: number; schools?: SchoolRow[] }
type Report = { groups_created: number; groups_updated: number; linked: number; unknown_schools: { line: number; school_code: string }[]; invalid_rows: { line: number; reason: string }[] }
const TYPES = ['directorate', 'cluster', 'stage', 'custom']
const blank = { code: '', name_ar: '', name_en: '', type: 'custom', description: '' }

/** Settings → School groups: directorates, clusters and stages. A role granted over a group sees only those schools. */
export default function SchoolGroups() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { data, isLoading, refetch } = useGet<{ data: Group[] }>('/admin/school-groups', { per_page: 100 }, { staleTime: 0 })
  const [editing, setEditing] = useState<Group | 'new' | null>(null)
  const [form, setForm] = useState(blank)
  const [schools, setSchools] = useState<string[]>([])
  const [rows, setRows] = useState<SchoolRow[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [report, setReport] = useState<Report | null>(null)
  const file = useRef<HTMLInputElement>(null)

  const open = async (g: Group | 'new') => {
    setError(null); setEditing(g)
    if (g === 'new') { setForm(blank); setSchools([]); setRows([]); return }
    setForm({ code: g.code, name_ar: g.name_ar, name_en: g.name_en, type: g.type, description: g.description ?? '' })
    const { data: full } = await api.get(`/admin/school-groups/${g.id}`)
    const list = (full.data.schools ?? []) as SchoolRow[]
    setSchools(list.map((s) => s.id)); setRows(list)
  }
  const save = async () => {
    setBusy(true); setError(null)
    try {
      const saved = editing === 'new' ? (await api.post('/admin/school-groups', form)).data.data : (await api.put(`/admin/school-groups/${(editing as Group).id}`, form)).data.data
      await api.put(`/admin/school-groups/${saved.id}/schools`, { school_ids: schools })
      toast(t('schoolGroups.saved')); setEditing(null); await refetch()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const remove = async (g: Group) => {
    if (!window.confirm(t('schoolGroups.confirmDelete', { name: ar ? g.name_ar : g.name_en }))) return
    try { await api.delete(`/admin/school-groups/${g.id}`); toast(t('schoolGroups.deleted')); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const upload = async (f: File) => {
    const body = new FormData(); body.append('file', f)
    try { const { data: r } = await api.post('/admin/school-groups/import', body); setReport(r.data); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  if (isLoading || !data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('schoolGroups.title')} subtitle={t('schoolGroups.subtitle')} actions={
        <div className="flex gap-2">
          <input ref={file} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} />
          <Button variant="outline" icon={<FileUp className="size-4" />} onClick={() => file.current?.click()}>{t('schoolGroups.import')}</Button>
          <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => void open('new')}>{t('schoolGroups.new')}</Button>
        </div>} />

      {report && (
        <Card className="space-y-2">
          <div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('schoolGroups.report')}</h3><button type="button" className="text-xs font-bold text-slate-400" onClick={() => setReport(null)}>{t('common.close', { defaultValue: 'Close' })}</button></div>
          <p className="text-sm text-slate-600">{t('schoolGroups.reportSummary', { created: report.groups_created, updated: report.groups_updated, linked: report.linked })}</p>
          {report.unknown_schools.length > 0 && <div className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900"><b>{t('schoolGroups.unknown', { n: report.unknown_schools.length })}</b><div className="mt-1 flex flex-wrap gap-1.5" dir="ltr">{report.unknown_schools.slice(0, 30).map((u) => <span key={u.line} className="rounded bg-white px-1.5 py-0.5 font-mono">{u.school_code || '∅'} <span className="text-slate-400">#{u.line}</span></span>)}</div></div>}
        </Card>
      )}

      {data.data.length === 0 ? <Card><Empty text={t('schoolGroups.empty')} /></Card> : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {data.data.map((g) => (
            <Card key={g.id} className="space-y-3">
              <div className="flex items-start gap-3">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><Layers className="size-5" /></span>
                <div className="min-w-0 flex-1"><h3 className="truncate font-bold text-navy-900">{ar ? g.name_ar : g.name_en}</h3><p className="truncate font-mono text-xs text-slate-400" dir="ltr">{g.code}</p></div>
                <Badge color="navy">{t(`schoolGroups.types.${g.type}`)}</Badge>
              </div>
              <div className="text-3xl font-extrabold tabular-nums text-navy-900">{fmt.number(g.schools_count)} <span className="text-sm font-semibold text-slate-400">{t('schoolGroups.schools')}</span></div>
              <div className="flex gap-2"><Button size="sm" variant="outline" icon={<Pencil className="size-4" />} onClick={() => void open(g)}>{t('schoolGroups.edit')}</Button><button type="button" aria-label={t('schoolGroups.delete')} onClick={() => void remove(g)} className="grid size-9 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button></div>
            </Card>
          ))}
        </div>
      )}

      <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? t('schoolGroups.new') : t('schoolGroups.edit')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('schoolGroups.nameAr')}><input className="input" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} /></Field>
            <Field label={t('schoolGroups.nameEn')}><input className="input" dir="ltr" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} /></Field>
            <Field label={t('schoolGroups.code')}><input className="input" dir="ltr" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></Field>
            <Field label={t('schoolGroups.type')}><select className="input" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{TYPES.map((x) => <option key={x} value={x}>{t(`schoolGroups.types.${x}`)}</option>)}</select></Field>
          </div>
          <Field label={`${t('schoolGroups.schoolsOf')} (${schools.length})`}><SchoolPicker multiple value={schools} selectedRows={rows} onChange={(ids, r) => { setSchools(ids); setRows(r) }} /></Field>
          {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
          <div className="flex gap-2"><Button variant="gold" loading={busy} disabled={!form.code.trim() || !form.name_ar.trim() || !form.name_en.trim()} onClick={() => void save()}>{t('schoolGroups.save')}</Button><Button variant="outline" className={clsx()} onClick={() => setEditing(null)}>{t('schoolGroups.cancel')}</Button></div>
        </div>
      </Modal>
    </div>
  )
}
