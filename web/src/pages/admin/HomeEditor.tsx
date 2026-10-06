/* eslint-disable @typescript-eslint/no-explicit-any */
import { ArrowDown, ArrowUp, Eye, EyeOff, Globe, History, Monitor, Plus, Save, Smartphone, Trash2, Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import BlockForm from './cms/BlockForm'
import BlockPreview from './cms/BlockPreview'
import StatsManager from './cms/StatsManager'
import { fromLocalInput, toLocalInput } from './communication/shared'

const TYPES = ['hero_slider', 'stats', 'featured_programs', 'news', 'events', 'rich_text', 'cta', 'logos', 'faq', 'video', 'custom_html_safe']
const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
let seq = 0
const withKey = (b: any) => ({ ...b, _k: b.id ?? `n${++seq}` })

/** The homepage (and About) as blocks: edit a working copy, check it live on desktop and phone in both languages, publish a version, restore an old one. */
export default function HomeEditor() {
  const { t } = useTranslation()
  const [page, setPage] = useState<'home' | 'about'>('home')
  const [tab, setTab] = useState<'blocks' | 'stats'>('blocks')
  const res = useGet<{ data: any[]; meta: { published_version: number | null } }>(`/admin/pages/${page}/blocks`, undefined, { staleTime: 0 })
  const preview = useGet<{ data: { blocks: any[] } }>(`/admin/pages/${page}/preview`, undefined, { staleTime: 0 })
  const [blocks, setBlocks] = useState<any[] | null>(null)
  const [sel, setSel] = useState<string | null>(null)
  const [dirty, setDirty] = useState(false)
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  const [lang, setLang] = useState<'ar' | 'en'>('ar')
  const [busy, setBusy] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [note, setNote] = useState('')
  const [versions, setVersions] = useState<any[] | null>(null)
  const loaded = useRef('')

  useEffect(() => {
    if (res.data && loaded.current !== `${page}:${res.dataUpdatedAt}`) {
      loaded.current = `${page}:${res.dataUpdatedAt}`
      setBlocks(res.data.data.map(withKey)); setDirty(false); setSel(null)
    }
  }, [res.data, res.dataUpdatedAt, page])
  useEffect(() => { setBlocks(null); loaded.current = '' }, [page])

  const live = res.data?.meta.published_version
  const dataFor = (type: string) => preview.data?.data.blocks.find((b: any) => b.type === type)?.data
  const edit = (patch: (b: any[]) => any[]) => { setBlocks((b) => patch(b ?? [])); setDirty(true) }
  const move = (i: number, d: number) => edit((b) => { const n = [...b]; const j = i + d; if (j < 0 || j >= n.length) return n; [n[i], n[j]] = [n[j], n[i]]; return n })
  const upd = (k: string, patch: any) => edit((b) => b.map((x) => (x._k === k ? { ...x, ...patch } : x)))
  const add = (type: string) => { const nb = withKey({ type, config: {}, is_visible: true, audience: 'public', starts_at: null, ends_at: null }); edit((b) => [...b, nb]); setSel(nb._k) }
  const strip = (b: any) => { const { _k, sort_order: _s, ...rest } = b; return rest }

  const save = async () => {
    setBusy(true)
    try { const { data } = await api.put(`/admin/pages/${page}/blocks`, { blocks: (blocks ?? []).map(strip) }); setBlocks(data.data.map(withKey)); setDirty(false); toast(String(t('comm.home.saved'))); preview.refetch(); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const publish = async () => {
    setBusy(true)
    try {
      if (dirty) await api.put(`/admin/pages/${page}/blocks`, { blocks: (blocks ?? []).map(strip) })
      const { data } = await api.post(`/admin/pages/${page}/publish`, { note: note || undefined })
      toast(String(t('comm.home.published', { v: data.data.version }))); setPublishing(false); setNote(''); setDirty(false); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const openVersions = async () => { const { data } = await api.get(`/admin/pages/${page}/versions`); setVersions(data.data) }
  const restore = async (v: number) => { await api.post(`/admin/pages/${page}/rollback/${v}`); toast(String(t('comm.home.restored'))); setVersions(null); res.refetch() }

  const cur = blocks?.find((b) => b._k === sel)

  return (
    <>
      <PageHeader title={t('comm.home.title')} actions={tab === 'blocks' && (
        <div className="flex flex-wrap items-center gap-2">
          {dirty && <Badge color="gold">{t('comm.home.unsaved')}</Badge>}
          <Button variant="outline" icon={<History className="size-4" />} onClick={openVersions}>{t('comm.home.versions')}</Button>
          <Button variant="outline" icon={<Save className="size-4" />} loading={busy} disabled={!dirty} onClick={save}>{t('comm.home.save')}</Button>
          <Button variant="gold" icon={<Upload className="size-4" />} onClick={() => setPublishing(true)}>{t('comm.home.publish')}</Button>
        </div>
      )} />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Tabs<'blocks' | 'stats'> value={tab} onChange={setTab} tabs={[{ id: 'blocks', label: t('comm.home.blocks') }, { id: 'stats', label: t('comm.home.stats') }]} />
        {tab === 'blocks' && <select className={`${cls} w-auto`} value={page} onChange={(e) => setPage(e.target.value as 'home' | 'about')} aria-label={String(t('comm.home.page'))}>{(['home', 'about'] as const).map((p) => <option key={p} value={p}>{t(`comm.home.pages.${p}`)}</option>)}</select>}
        {tab === 'blocks' && !live && <span className="text-xs text-amber-700">{t('comm.home.neverPublished')}</span>}
        {tab === 'blocks' && !!live && <Badge color="green"><Globe className="me-1 inline size-3" />{t('comm.home.current')}: v{live}</Badge>}
      </div>

      {tab === 'stats' ? <StatsManager /> : !blocks ? <Spinner /> : (
        <div className="grid gap-4 xl:grid-cols-[22rem_1fr]">
          <div className="space-y-3">
            <Card padded={false}>
              <ul className="divide-y divide-navy-50">
                {blocks.map((b, i) => (
                  <li key={b._k} className={`flex items-center gap-1 p-2 ${sel === b._k ? 'bg-gold-50' : ''}`}>
                    <button className="min-w-0 flex-1 truncate p-1 text-start text-sm font-semibold text-navy-900" onClick={() => setSel(sel === b._k ? null : b._k)}>{t(`comm.home.types.${b.type}`)}{!b.is_visible && <span className="ms-2 text-xs font-normal text-slate-400">({t('comm.home.hidden')})</span>}{b.audience === 'signed_in' && <span className="ms-2 text-xs font-normal text-sky-600">🔒</span>}</button>
                    <button className="p-1 text-slate-400 hover:text-navy-900" onClick={() => move(i, -1)} aria-label={String(t('comm.home.moveUp'))}><ArrowUp className="size-4" /></button>
                    <button className="p-1 text-slate-400 hover:text-navy-900" onClick={() => move(i, 1)} aria-label={String(t('comm.home.moveDown'))}><ArrowDown className="size-4" /></button>
                    <button className="p-1 text-slate-400 hover:text-navy-900" onClick={() => upd(b._k, { is_visible: !b.is_visible })} aria-label="visibility">{b.is_visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}</button>
                  </li>
                ))}
                {!blocks.length && <li className="p-4"><Empty /></li>}
              </ul>
              <div className="flex items-center gap-2 border-t border-navy-50 p-2">
                <select id="newblock" className={cls} defaultValue="">{[<option key="" value="" disabled>{String(t('comm.home.add'))}</option>, ...TYPES.map((x) => <option key={x} value={x}>{t(`comm.home.types.${x}`)}</option>)]}</select>
                <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => { const el = document.getElementById('newblock') as HTMLSelectElement; if (el.value) { add(el.value); el.value = '' } }}>{t('common.add')}</Button>
              </div>
            </Card>

            {cur && (
              <Card>
                <div className="mb-3 flex items-center justify-between"><h4 className="font-bold text-navy-900">{t(`comm.home.types.${cur.type}`)}</h4><button className="text-slate-400 hover:text-danger" onClick={() => { edit((b) => b.filter((x) => x._k !== cur._k)); setSel(null) }} aria-label={String(t('comm.home.remove'))}><Trash2 className="size-4" /></button></div>
                <BlockForm type={cur.type} config={cur.config} onChange={(config) => upd(cur._k, { config })} />
                <div className="mt-4 grid gap-3 border-t border-navy-50 pt-3 sm:grid-cols-2">
                  <Field label={t('comm.home.starts')}><input type="datetime-local" className={cls} value={toLocalInput(cur.starts_at)} onChange={(e) => upd(cur._k, { starts_at: fromLocalInput(e.target.value) })} /></Field>
                  <Field label={t('comm.home.ends')}><input type="datetime-local" className={cls} value={toLocalInput(cur.ends_at)} onChange={(e) => upd(cur._k, { ends_at: fromLocalInput(e.target.value) })} /></Field>
                  <Field label={t('admin.communication.audience')}><select className={cls} value={cur.audience} onChange={(e) => upd(cur._k, { audience: e.target.value })}><option value="public">{t('comm.home.audiencePublic')}</option><option value="signed_in">{t('comm.home.audienceSigned')}</option></select></Field>
                </div>
              </Card>
            )}
          </div>

          <div>
            <div className="mb-2 flex items-center gap-2">
              <span className="text-sm font-bold text-navy-900">{t('comm.home.preview')}</span>
              <div className="ms-auto flex gap-1">
                <Button size="sm" variant={device === 'desktop' ? 'primary' : 'outline'} icon={<Monitor className="size-4" />} onClick={() => setDevice('desktop')}>{t('comm.home.desktop')}</Button>
                <Button size="sm" variant={device === 'mobile' ? 'primary' : 'outline'} icon={<Smartphone className="size-4" />} onClick={() => setDevice('mobile')}>{t('comm.home.mobile')}</Button>
                <Button size="sm" variant={lang === 'ar' ? 'primary' : 'outline'} onClick={() => setLang('ar')}>AR</Button>
                <Button size="sm" variant={lang === 'en' ? 'primary' : 'outline'} onClick={() => setLang('en')}>EN</Button>
              </div>
            </div>
            <div className="overflow-hidden rounded-2xl border border-navy-100 bg-slate-100 p-3">
              <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className={`mx-auto overflow-hidden rounded-xl bg-white shadow-sm transition-all ${device === 'mobile' ? 'max-w-[375px]' : 'max-w-full'}`}>
                {blocks.map((b) => <div key={b._k} onClick={() => setSel(b._k)} className={`cursor-pointer ring-inset transition ${sel === b._k ? 'ring-2 ring-gold-500' : 'hover:ring-1 hover:ring-gold-300'}`}><BlockPreview block={b} lang={lang} data={dataFor(b.type)} /></div>)}
                {!blocks.length && <div className="p-10"><Empty /></div>}
              </div>
            </div>
          </div>
        </div>
      )}

      <Modal open={publishing} onClose={() => setPublishing(false)} title={t('comm.home.publish')}>
        <Field label={t('comm.home.note')}><input className={cls} value={note} maxLength={200} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setPublishing(false)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} onClick={publish}>{t('comm.home.publish')}</Button></div>
      </Modal>
      <Modal open={!!versions} onClose={() => setVersions(null)} title={t('comm.home.versions')}>
        <ul className="max-h-96 divide-y divide-navy-50 overflow-y-auto text-sm">
          {versions?.map((v) => <li key={v.id} className="flex items-center justify-between gap-3 py-2"><div><b>v{v.version}</b>{v.version === live && <Badge color="green" className="ms-2">{t('comm.home.current')}</Badge>}<div className="text-xs text-slate-500">{v.note} · {fmt.date(v.created_at, { dateStyle: 'medium', timeStyle: 'short' } as any)}{v.publisher ? ` · ${v.publisher.name_ar || v.publisher.name}` : ''}</div></div><Button size="sm" variant="outline" onClick={() => restore(v.version)}>{t('comm.home.restore')}</Button></li>)}
          {!versions?.length && <li className="py-4"><Empty /></li>}
        </ul>
      </Modal>
    </>
  )
}
