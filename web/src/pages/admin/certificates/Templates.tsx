import clsx from 'clsx'
import { Copy, Pencil, Plus, Star, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Badge, Button, Card, Empty, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import PageCanvas, { useTemplateFile } from './PageCanvas'
import type { CertTemplate, TemplateMeta } from './types'

function Thumb({ tpl, sample }: { tpl: CertTemplate; sample: Record<string, string> }) {
  const background = useTemplateFile(tpl.has_background ? tpl.id : null, 'background', tpl.updated_at)
  return <PageCanvas widthMm={tpl.width_mm} heightMm={tpl.height_mm} elements={tpl.elements} background={background} values={sample} className="pointer-events-none" />
}

export default function Templates() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [kind, setKind] = useState<'' | 'trainee' | 'trainer'>('')
  const { data, isLoading, error, refetch } = useGet<{ data: CertTemplate[]; meta: TemplateMeta }>('/admin/certificate-templates', kind ? { kind } : undefined)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ name_ar: '', name_en: '', kind: 'trainee' as 'trainee' | 'trainer', duplicate_of: '' })
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const manage = can('certificates.issue')

  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState onRetry={refetch} />
  const rows = data.data

  const create = async () => {
    setBusy('create')
    setMessage(null)
    try {
      const res = await api.post<{ data: CertTemplate }>('/admin/certificate-templates', { ...form, duplicate_of: form.duplicate_of || undefined })
      navigate(`/admin/certificate-templates/${res.data.data.id}`)
    } catch (e) {
      setMessage(errorMessage(e))
      setBusy(null)
    }
  }
  const act = async (id: string, fn: () => Promise<unknown>) => {
    setBusy(id)
    setMessage(null)
    try { await fn(); await refetch() } catch (e) { setMessage(errorMessage(e)) } finally { setBusy(null) }
  }
  const copy = (c: CertTemplate) => act(c.id, async () => {
    const res = await api.post<{ data: CertTemplate }>('/admin/certificate-templates', { name_ar: `${c.name_ar} (نسخة)`, name_en: `${c.name_en} (copy)`, kind: c.kind, duplicate_of: c.id })
    navigate(`/admin/certificate-templates/${res.data.data.id}`)
  })

  return (
    <>
      <PageHeader title={t('studio.list.title')} subtitle={t('studio.list.subtitle')} actions={manage && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => { setForm({ name_ar: '', name_en: '', kind: kind || 'trainee', duplicate_of: '' }); setCreating(true) }}>{t('studio.list.new')}</Button>} />

      <div className="mb-5 flex gap-1 rounded-2xl border border-navy-100 bg-white p-1 sm:w-fit">
        {([['', 'all'], ['trainee', 'trainee'], ['trainer', 'trainer']] as const).map(([k, label]) => (
          <button key={k} type="button" onClick={() => setKind(k)} className={clsx('rounded-xl px-4 py-2 text-sm font-semibold transition', kind === k ? 'bg-navy-900 text-white shadow' : 'text-slate-600 hover:bg-ivory')}>{t(`studio.kind.${label}`)}</button>
        ))}
      </div>
      {message && <div className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{message}</div>}

      {rows.length === 0 ? <Card><Empty text={t('studio.list.empty')} /></Card> : (
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {rows.map((c) => (
            <Card key={c.id} padded={false} className="group overflow-hidden">
              <button type="button" disabled={!manage} onClick={() => navigate(`/admin/certificate-templates/${c.id}`)} className="block w-full border-b border-navy-100 bg-slate-100 p-4 transition group-hover:bg-ivory">
                <div className="mx-auto max-w-[22rem] shadow-md"><Thumb tpl={c} sample={data.meta.sample} /></div>
              </button>
              <div className="space-y-3 p-4">
                <div>
                  <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold text-navy-900">{c.name}</h3>{c.is_default && <Badge color="green">{t('studio.list.default')}</Badge>}</div>
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500"><Badge color={c.kind === 'trainer' ? 'gold' : 'navy'}>{t(`studio.kind.${c.kind}`)}</Badge><span dir="ltr">{c.width_mm}×{c.height_mm} mm</span><span>{t('studio.list.programs', { count: c.programs_count ?? 0 })}</span>{c.has_background && <Badge color="blue">{t('studio.list.hasBackground')}</Badge>}</div>
                </div>
                {manage && (
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="primary" icon={<Pencil className="size-4" />} onClick={() => navigate(`/admin/certificate-templates/${c.id}`)}>{t('studio.list.edit')}</Button>
                    <Button size="sm" variant="outline" loading={busy === c.id} icon={<Copy className="size-4" />} onClick={() => copy(c)}>{t('studio.list.duplicate')}</Button>
                    {!c.is_default && <Button size="sm" variant="outline" icon={<Star className="size-4" />} onClick={() => act(c.id, () => api.post(`/admin/certificate-templates/${c.id}/default`))}>{t('studio.list.makeDefault')}</Button>}
                    {!c.is_default && <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4 text-danger" />} onClick={() => window.confirm(t('studio.list.confirmDelete', { name: c.name })) && act(c.id, () => api.delete(`/admin/certificate-templates/${c.id}`))} />}
                  </div>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('studio.list.new')}>
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('studio.designer.nameAr')}><input dir="rtl" className="input" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} /></Field>
            <Field label={t('studio.designer.nameEn')}><input dir="ltr" className="input" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} /></Field>
          </div>
          <div>
            <div className="label">{t('studio.list.forWhom')}</div>
            <div className="grid grid-cols-2 gap-2">
              {(['trainee', 'trainer'] as const).map((k) => <button key={k} type="button" aria-pressed={form.kind === k} onClick={() => setForm({ ...form, kind: k })} className={clsx('rounded-xl border p-3 text-start text-sm transition', form.kind === k ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 hover:border-gold-400')}><span className="block font-bold text-navy-900">{t(`studio.kind.${k}`)}</span><span className="text-xs text-slate-500">{t(`studio.kind.${k}Hint`)}</span></button>)}
            </div>
          </div>
          <Field label={t('studio.list.startFrom')} hint={t('studio.list.startFromHint')}>
            <select className="input" value={form.duplicate_of} onChange={(e) => setForm({ ...form, duplicate_of: e.target.value })}>
              <option value="">{t('studio.list.stock')}</option>
              {rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </Field>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setCreating(false)}>{t('common.cancel')}</Button>
            <Button variant="gold" loading={busy === 'create'} disabled={!form.name_ar.trim() || !form.name_en.trim()} onClick={create}>{t('studio.list.createAndDesign')}</Button>
          </div>
        </div>
      </Modal>
    </>
  )
}
