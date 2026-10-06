/* eslint-disable @typescript-eslint/no-explicit-any */
import { Lock, MessagesSquare, Plus, Search, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router-dom'
import { Badge, Button, Card, Empty, ErrorState, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type View = 'mine' | 'discover'
const TYPES = ['', 'community', 'program_forum', 'group_forum', 'trainers_channel', 'lesson_thread'] as const

/** The hub: the spaces a person belongs to, and the open communities they can join. */
export default function Communities() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const base = useLocation().pathname.startsWith('/admin') ? '/admin' : '/portal'
  const [view, setView] = useState<View>('mine')
  const [type, setType] = useState<string>('')
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const res = useGet<{ data: any[]; can_create: boolean }>('/social/spaces', { type: type || undefined, q: q || undefined }, { staleTime: 0, retry: false })

  const rows = (res.data?.data ?? []).filter((s) => (view === 'mine' ? s.my_role || s.my_status === 'pending' : !s.my_role))
  const act = async (s: any, e: React.MouseEvent) => {
    e.preventDefault()
    try {
      const { data } = await api.post(`/social/spaces/${s.id}/join`)
      toast(String(t(data.status === 'pending' ? 'soc.hub.requested' : 'soc.hub.joined')))
      res.refetch()
    } catch (err) { toast(errorMessage(err), 'error') }
  }

  return (
    <>
      <PageHeader title={t('soc.hub.title')} subtitle={t('soc.hub.subtitle')} actions={res.data?.can_create && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('soc.hub.new')}</Button>} />
      <Tabs<View> value={view} onChange={setView} tabs={[{ id: 'mine', label: t('soc.hub.mine') }, { id: 'discover', label: t('soc.hub.discover') }]} />
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <div className="relative min-w-52 flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('soc.hub.search'))} />
        </div>
        <div className="flex flex-wrap gap-1.5">
          {TYPES.map((x) => (
            <button key={x || 'all'} onClick={() => setType(x)} className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${type === x ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400'}`}>
              {x ? t(`soc.hub.types.${x}`) : t('soc.hub.all')}
            </button>
          ))}
        </div>
      </div>

      {res.isLoading ? <Spinner /> : res.isError ? <ErrorState message={errorMessage(res.error)} onRetry={() => res.refetch()} /> : rows.length === 0 ? (
        <Empty text={String(t(view === 'mine' ? 'soc.hub.emptyMine' : 'soc.hub.empty'))} icon={<MessagesSquare className="size-8" />} />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {rows.map((s) => {
            const title = en ? s.title_en : s.title_ar
            const desc = en ? s.description_en : s.description_ar
            return (
              <Link key={s.id} to={`${base}/communities/${s.id}`} className="group">
                <Card className="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-lg" >
                  <div className="mb-2 flex items-center justify-between gap-2">
                    <Badge color={s.type === 'community' ? 'gold' : 'navy'}>{t(`soc.hub.types.${s.type}`)}</Badge>
                    {s.visibility === 'private' && <Lock className="size-4 text-slate-400" aria-hidden />}
                  </div>
                  <h3 className="font-display text-lg font-bold text-navy-900">{title}</h3>
                  <p className="mt-1 line-clamp-2 min-h-10 text-sm text-slate-500">{desc}</p>
                  <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span className="inline-flex items-center gap-1"><Users className="size-3.5" />{t('soc.hub.members', { n: s.members_count ?? 0 })}</span>
                    <span>{t('soc.hub.posts', { n: s.posts_count })}</span>
                  </div>
                  {view === 'discover' && s.type === 'community' && (
                    s.my_status === 'pending' ? <p className="mt-3 text-xs font-semibold text-amber-700">{t('soc.hub.pending')}</p>
                      : s.join_policy === 'invite' ? <p className="mt-3 text-xs text-slate-400">{t('soc.hub.invite')}</p>
                        : <Button size="sm" variant="outline" className="mt-3 w-full" onClick={(e) => act(s, e)}>{t(s.join_policy === 'request' ? 'soc.hub.request' : 'soc.hub.join')}</Button>
                  )}
                </Card>
              </Link>
            )
          })}
        </div>
      )}
      <CreateCommunity open={open} onClose={() => setOpen(false)} onDone={() => { setOpen(false); res.refetch() }} />
    </>
  )
}

function CreateCommunity({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState<any>({ title_ar: '', title_en: '', description_ar: '', description_en: '', visibility: 'members', join_policy: 'open', settings: { allow_polls: true, allow_files: true, moderation: false, anonymous_qa: false } })
  const [busy, setBusy] = useState(false)
  const set = (k: string, v: any) => setF((x: any) => ({ ...x, [k]: v }))
  const setting = (k: string, v: boolean) => setF((x: any) => ({ ...x, settings: { ...x.settings, [k]: v } }))
  const submit = async () => {
    setBusy(true)
    try { await api.post('/social/spaces', f); toast(String(t('soc.form.created'))); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('soc.form.title')} wide>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('soc.form.titleAr')}><input className="input" dir="rtl" value={f.title_ar} onChange={(e) => set('title_ar', e.target.value)} maxLength={200} /></Field>
        <Field label={t('soc.form.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => set('title_en', e.target.value)} maxLength={200} /></Field>
        <Field label={t('soc.form.descAr')}><textarea className="input min-h-20" dir="rtl" value={f.description_ar} onChange={(e) => set('description_ar', e.target.value)} /></Field>
        <Field label={t('soc.form.descEn')}><textarea className="input min-h-20" dir="ltr" value={f.description_en} onChange={(e) => set('description_en', e.target.value)} /></Field>
        <Field label={t('soc.form.visibility')}>
          <select className="input" value={f.visibility} onChange={(e) => set('visibility', e.target.value)}>{['public_in_scope', 'members', 'private'].map((v) => <option key={v} value={v}>{t(`soc.form.vis.${v}`)}</option>)}</select>
        </Field>
        <Field label={t('soc.form.join')}>
          <select className="input" value={f.join_policy} onChange={(e) => set('join_policy', e.target.value)}>{['open', 'request', 'invite'].map((v) => <option key={v} value={v}>{t(`soc.form.joins.${v}`)}</option>)}</select>
        </Field>
        <div className="grid gap-2 sm:col-span-2 sm:grid-cols-2">
          {(['allow_polls', 'allow_files', 'moderation', 'anonymous_qa'] as const).map((k) => (
            <label key={k} className="flex items-center gap-2 text-sm text-navy-900"><input type="checkbox" className="accent-gold-600" checked={f.settings[k]} onChange={(e) => setting(k, e.target.checked)} />{t(`soc.form.${{ allow_polls: 'polls', allow_files: 'files', moderation: 'moderation', anonymous_qa: 'anonymous' }[k]}`)}</label>
          ))}
        </div>
      </div>
      <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('soc.common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!f.title_ar.trim() || !f.title_en.trim()} onClick={submit}>{t('soc.form.create')}</Button></div>
    </Modal>
  )
}
