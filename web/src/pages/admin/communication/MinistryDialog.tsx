/* eslint-disable @typescript-eslint/no-explicit-any */
import { Copy } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { input } from './shared'

/** Settings for the Ministry website: endpoint and key, automatic export, feed addresses, the export log and manual files. */
export default function MinistryDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/ministry-site', undefined, { staleTime: 0 })
  const [s, setS] = useState<any>(null)
  const [key, setKey] = useState('')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => { if (res.data) setS(res.data.data) }, [res.data])

  const save = async () => {
    setError(null)
    try {
      const { data } = await api.put('/admin/settings/ministry-site', { enabled: s.enabled, auto_export: s.auto_export, endpoint: s.endpoint, auth_header: s.auth_header, site_name: s.site_name, ...(key ? { api_key: key } : {}) })
      setS(data.data); setKey(''); toast(String(t('comm.ministry.saved')))
    } catch (e) { setError(errorMessage(e)) }
  }
  const run = async () => { try { const { data } = await api.post('/admin/settings/ministry-site/run'); toast(`${t('comm.ministry.sent')}: ${data.data.sent} · ${t('comm.ministry.failed')}: ${data.data.failed}`); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }

  return (
    <Modal open onClose={onClose} title={t('comm.ministry.title')} wide>
      {!s ? <Spinner /> : (
        <div className="space-y-4">
          <p className="text-sm text-slate-500">{t('comm.ministry.hint')}</p>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={s.enabled} onChange={(e) => setS({ ...s, enabled: e.target.checked })} />{t('comm.ministry.enabled')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={s.auto_export} onChange={(e) => setS({ ...s, auto_export: e.target.checked })} />{t('comm.ministry.auto')}</label>
            <Field label={t('comm.ministry.endpoint')} className="md:col-span-2"><input className={input} dir="ltr" placeholder="https://" value={s.endpoint} onChange={(e) => setS({ ...s, endpoint: e.target.value })} /></Field>
            <Field label={t('comm.ministry.header')}><input className={input} dir="ltr" value={s.auth_header} onChange={(e) => setS({ ...s, auth_header: e.target.value })} /></Field>
            <Field label={t('comm.ministry.key')} hint={s.api_key_set ? t('comm.ministry.keySet') : undefined}><input type="password" className={input} dir="ltr" autoComplete="off" value={key} onChange={(e) => setKey(e.target.value)} /></Field>
            <Field label={t('comm.ministry.site')}><input className={input} value={s.site_name} onChange={(e) => setS({ ...s, site_name: e.target.value })} /></Field>
          </div>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex flex-wrap justify-between gap-2">
            <div className="flex gap-2"><Button variant="outline" onClick={run}>{t('comm.ministry.run')}</Button>
              {(['news', 'events'] as const).flatMap((k) => (['json', 'csv', 'rss'] as const).map((f) => <Button key={k + f} size="sm" variant="outline" onClick={() => downloadFile(`/admin/settings/ministry-site/file?kind=${k}&format=${f}`, `${k}.${f === 'rss' ? 'xml' : f}`)}>{k} · {f.toUpperCase()}</Button>))}</div>
            <Button variant="gold" onClick={save}>{t('common.save')}</Button>
          </div>
          <div>
            <h4 className="mb-2 text-sm font-bold text-navy-900">{t('comm.ministry.feeds')}</h4>
            <ul className="space-y-1 text-xs">{Object.entries(s.feeds as Record<string, string>).map(([k, u]) => <li key={k} className="flex items-center gap-2 rounded-lg bg-ivory px-3 py-1.5"><span className="w-24 shrink-0 text-slate-400">{k}</span><code className="min-w-0 flex-1 truncate" dir="ltr">{u}</code><button onClick={() => { void navigator.clipboard?.writeText(u); toast('✓') }} aria-label="copy"><Copy className="size-3.5" /></button></li>)}</ul>
          </div>
          <div>
            <h4 className="mb-2 flex items-center gap-2 text-sm font-bold text-navy-900">{t('comm.ministry.log')}<Badge color="gold">{t('comm.ministry.queued')}: {s.counts.queued}</Badge><Badge color="red">{t('comm.ministry.failed')}: {s.counts.failed}</Badge><Badge color="green">{t('comm.ministry.sent')}: {s.counts.sent}</Badge></h4>
            <ul className="max-h-40 space-y-1 overflow-y-auto text-xs">{s.log.map((l: any) => <li key={l.id} className="flex justify-between gap-2 rounded-lg bg-ivory px-3 py-1.5"><span className="truncate">{l.announcement_row?.title_en ?? l.announcement_id}</span><span className="text-slate-500">{l.status}{l.last_error ? ` — ${l.last_error}` : ''}</span></li>)}</ul>
          </div>
        </div>
      )}
    </Modal>
  )
}
