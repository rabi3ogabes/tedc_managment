/* eslint-disable @typescript-eslint/no-explicit-any */
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** Common Cartridge import: upload, see the organisation tree with what each part becomes, tick the parts, import. */
export default function CartridgeImport({ programId, onClose }: { programId: string; onClose: (changed?: boolean) => void }) {
  const { t } = useTranslation()
  const file = useRef<HTMLInputElement>(null)
  const [pkg, setPkg] = useState<any | null>(null)
  const [tree, setTree] = useState<any[]>([])
  const [picked, setPicked] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const upload = async (f: File) => {
    const fd = new FormData(); fd.append('file', f); setBusy(true)
    try {
      const { data } = await api.post('/admin/packages', fd)
      if (data.data.standard !== 'cc') { toast(t('content.cc.becomes.unsupported'), 'error'); return }
      const prev = await api.get(`/admin/packages/${data.data.id}/cc/preview`)
      setPkg(data.data); setTree(prev.data.data.tree); setPicked(prev.data.data.tree.filter((n: any) => n.becomes !== 'folder' && n.becomes !== 'unsupported' && n.becomes !== 'discussion').map((n: any) => n.id))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const go = async () => {
    setBusy(true)
    try { const { data } = await api.post(`/admin/packages/${pkg.id}/cc/import`, { program_id: programId, item_ids: picked }); toast(`${t('content.cc.done', data.data)}${data.data.skipped.length ? ` · ${t('content.cc.skipped', { n: data.data.skipped.length })}` : ''}`); onClose(true) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const depth = (n: any): number => { let d = 0; let p = n.parent; while (p) { d++; p = tree.find((x) => x.id === p)?.parent } return d }
  return (
    <Modal wide open onClose={() => onClose()} title={t('content.cc.title')}>
      {!pkg ? <div><Button loading={busy} onClick={() => file.current?.click()}>{t('content.cc.pick')}</Button><input ref={file} type="file" hidden accept=".zip,.imscc" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} /></div> : (
        <div className="space-y-3"><div className="text-sm font-bold text-navy-900">{pkg.title} — {t('content.cc.tree')}</div>
          <div className="max-h-[50vh] space-y-1 overflow-y-auto rounded-xl border border-navy-100 p-2">{tree.map((n) => (
            <label key={n.id} className="flex items-center gap-2 text-sm" style={{ paddingInlineStart: depth(n) * 18 }}>
              <input type="checkbox" disabled={n.becomes === 'unsupported' || n.becomes === 'discussion'} checked={picked.includes(n.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, n.id] : picked.filter((x) => x !== n.id))} />
              <span className="flex-1">{n.title}</span><Badge color={n.becomes === 'unsupported' || n.becomes === 'discussion' ? 'gray' : 'gold'}>{t(`content.cc.becomes.${n.becomes}`)}</Badge></label>))}</div>
          <Button variant="gold" loading={busy} disabled={!picked.length} onClick={() => void go()}>{t('content.cc.import')}</Button></div>)}
    </Modal>
  )
}
