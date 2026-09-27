import { File, Link2, Trash2, Upload, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardTitle, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

type Material = { id: string; title_ar: string; title_en: string; type: string; url?: string; mime?: string; size?: number; visibility: string; created_at: string }

export default function MaterialsTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Material[] }>(`/admin/programs/${program.id}/materials`)
  const [form, setForm] = useState({ title_ar: '', title_en: '', type: 'file', url: '', visibility: 'participants' })
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const upload = async () => {
    setLoading(true)
    setError(null)
    const body = new FormData()
    Object.entries(form).forEach(([k, v]) => v && body.append(k, v))
    if (file) body.append('file', file)
    try {
      await api.post(`/admin/programs/${program.id}/materials`, body)
      setForm({ ...form, title_ar: '', title_en: '', url: '' })
      setFile(null)
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  const icon = (type: string) => (type === 'video' ? <Video className="size-5" /> : type === 'link' ? <Link2 className="size-5" /> : <File className="size-5" />)

  return (
    <div className="grid gap-6 xl:grid-cols-3">
      <Card className="xl:col-span-2" padded={false}>
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <ul className="divide-y divide-navy-100">
            {data.data.map((m) => (
              <li key={m.id} className="flex items-center gap-4 p-4">
                <div className="grid size-11 place-items-center rounded-xl bg-navy-900 text-gold-300">{icon(m.type)}</div>
                <div className="min-w-0 flex-1">
                  <div className="font-semibold text-navy-900">{i18n.language === 'ar' ? m.title_ar : m.title_en}</div>
                  <div className="text-xs text-slate-400">{m.type} · {m.visibility} {m.size ? `· ${fmt.number(m.size / 1024 / 1024, 1)} MB` : ''}</div>
                </div>
                {m.url && <a href={m.url} target="_blank" rel="noreferrer" className="text-sm font-semibold text-link">{t('common.view')}</a>}
                {can('materials.manage') && <button className="text-slate-400 hover:text-danger" onClick={async () => { await api.delete(`/admin/materials/${m.id}`); refetch() }}><Trash2 className="size-4" /></button>}
              </li>
            ))}
          </ul>
        )}
      </Card>
      {can('materials.manage') && (
        <Card>
          <CardTitle>{t('admin.programs.addMaterial')}</CardTitle>
          <div className="space-y-3">
            <Field label={t('admin.programs.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
            <Field label={t('admin.programs.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-2">
              <select className="input" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{['file', 'document', 'presentation', 'video', 'link'].map((x) => <option key={x}>{x}</option>)}</select>
              <select className="input" value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value })}>{['participants', 'trainers', 'public'].map((x) => <option key={x}>{x}</option>)}</select>
            </div>
            {['link', 'video'].includes(form.type)
              ? <input className="input" dir="ltr" placeholder="https://" value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} />
              : <input type="file" className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />}
            {error && <p className="text-sm text-danger">{error}</p>}
            <Button variant="gold" className="w-full" icon={<Upload className="size-4" />} loading={loading} onClick={upload}>{t('common.upload')}</Button>
          </div>
        </Card>
      )}
    </div>
  )
}
