import { ClipboardList, DatabaseZap, Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Badge, Button, Empty, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import MigrationPanel from './MigrationPanel'

type Row = { id: string; kind: string; title_ar: string; title_en?: string | null; status: string; privacy_mode: string; owner?: string | null; participants: number; submissions: number; is_open: boolean; closes_at?: string | null; updated_at?: string; legacy_type?: 'needs_survey' | 'evaluation_form' | null }
type Catalog = { kinds: string[]; privacy_modes: string[] }
const TONE: Record<string, 'gray' | 'amber' | 'green' | 'navy' | 'red'> = { draft: 'gray', in_review: 'amber', approved: 'green', published: 'navy', closed: 'gray', archived: 'gray' }

/** The surveys of the new engine, and the door to the studio. */
export default function SurveysList() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { can } = useAuth()
  const en = i18n.language === 'en'
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [mine, setMine] = useState(false)
  const [open, setOpen] = useState(false)
  const [migrate, setMigrate] = useState(false)
  const res = useGet<{ data: Row[]; meta: { total: number } }>('/admin/surveys', { q: q || undefined, status: status || undefined, mine: mine ? 1 : undefined, per_page: 60 }, { staleTime: 0 })
  const catalog = useGet<{ data: Catalog }>('/admin/surveys/catalog')
  return (
    <>
      <PageHeader title={t('ss.list.title')} subtitle={t('ss.list.subtitle')} actions={can('surveys.manage') && <div className="flex gap-2"><Button variant="outline" icon={<DatabaseZap className="size-4" />} onClick={() => setMigrate(true)}>{t('ss.migration.title')}</Button><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('ss.list.create')}</Button></div>} />
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('ss.list.search'))} /></div>
        <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('ss.list.all')}</option>{['draft', 'in_review', 'approved', 'published', 'closed', 'archived'].map((s) => <option key={s} value={s}>{t(`ss.state.${s}`)}</option>)}</select>
        <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" className="accent-gold-600" checked={mine} onChange={(e) => setMine(e.target.checked)} />{t('ss.list.mine')}</label>
      </div>
      {res.isLoading ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={() => res.refetch()} /> : !res.data?.data.length ? <Empty text={String(t('ss.list.empty'))} icon={<ClipboardList className="size-6" />} /> : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {res.data.data.map((s) => (
            <Link key={s.id} to={`/admin/surveys/${s.id}`} className="group rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-navy-300 hover:shadow-sm">
              <div className="mb-2 flex items-start justify-between gap-2"><Badge color={TONE[s.status] ?? 'gray'}>{t(`ss.state.${s.status}`)}</Badge><Badge color="gray">{t(`ss.privacy.${s.privacy_mode}`)}</Badge></div>
              <div className="line-clamp-2 font-display text-base font-bold text-navy-900 group-hover:text-navy-700">{en && s.title_en ? s.title_en : s.title_ar}</div>
              <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">{t(`ss.kind.${s.kind}`)}{s.owner ? ` · ${s.owner}` : ''}{s.legacy_type && <Badge color="amber">{t(`ss.legacy.${s.legacy_type}`)}</Badge>}</div>
              <div className="mt-3 flex gap-4 text-xs text-slate-600"><span><b className="tabular-nums">{s.participants}</b> {t('ss.list.participants')}</span><span><b className="tabular-nums">{s.submissions}</b> {t('ss.list.submissions')}</span></div>
            </Link>
          ))}
        </div>
      )}
      <MigrationPanel open={migrate} onClose={() => { setMigrate(false); void res.refetch() }} />
      <CreateDialog open={open} onClose={() => setOpen(false)} catalog={catalog.data?.data} onCreated={(id) => navigate(`/admin/surveys/${id}`)} />
    </>
  )
}

function CreateDialog({ open, onClose, catalog, onCreated }: { open: boolean; onClose: () => void; catalog?: Catalog; onCreated: (id: string) => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ title_ar: '', title_en: '', kind: 'general', privacy_mode: 'identified' })
  const create = useSend<Record<string, unknown>, { data: { id: string } }>('post', '/admin/surveys', ['/admin/surveys'])
  const submit = async () => {
    try {
      const { data } = await create.mutateAsync({ ...f, title_en: f.title_en || null, questions: [{ id: 'q1', type: 'single', title: t('ss.studio.question'), required: true, options: [{ id: 'o1', label: `${t('ss.defaults.option')} 1` }, { id: 'o2', label: `${t('ss.defaults.option')} 2` }] }] })
      toast(String(t('ss.list.created'))); onCreated(data.id)
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('ss.list.newTitle')}>
      <div className="grid gap-4">
        <Field label={t('ss.list.titleAr')}><input className="input" dir="rtl" value={f.title_ar} onChange={(e) => setF({ ...f, title_ar: e.target.value })} maxLength={200} /></Field>
        <Field label={t('ss.list.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => setF({ ...f, title_en: e.target.value })} maxLength={200} /></Field>
        <Field label={t('ss.list.kind')}><select className="input" value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}>{(catalog?.kinds ?? ['general']).map((k) => <option key={k} value={k}>{t(`ss.kind.${k}`)}</option>)}</select></Field>
        <Field label={t('ss.list.privacy')} hint={t(`ss.privacy.${f.privacy_mode}Hint`)}>
          <div className="grid gap-2 sm:grid-cols-3">{(catalog?.privacy_modes ?? ['identified', 'confidential', 'anonymous']).map((m) => <button key={m} type="button" onClick={() => setF({ ...f, privacy_mode: m })} className={`rounded-xl border px-3 py-2 text-sm ${f.privacy_mode === m ? 'border-navy-900 bg-navy-50 font-semibold text-navy-900' : 'border-slate-200 hover:border-navy-300'}`}>{t(`ss.privacy.${m}`)}</button>)}</div>
        </Field>
      </div>
      <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button variant="gold" disabled={!f.title_ar.trim()} loading={create.isPending} onClick={submit}>{t('ss.list.create')}</Button></div>
    </Modal>
  )
}
