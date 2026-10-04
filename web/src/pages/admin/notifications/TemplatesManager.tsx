import clsx from 'clsx'
import { BellOff, BellRing, Check, FilePlus2, Mail, MessageSquareText, Pencil, RotateCcw, Search, Smartphone, Trash2 } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useCenterName } from '@/lib/ThemeProvider'
import { PhoneNotification, Switch, usePreview, VariableChips, type Template, type TemplateData } from './shared'

const GROUP_ORDER = ['registration', 'program', 'survey', 'session', 'task', 'certificate', 'custom']

/** Settings → Notification templates: one switch per automatic notification, editable wording, and custom templates. */
export default function TemplatesManager() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const { data, isLoading, refetch } = useGet<{ data: TemplateData }>('/admin/notifications/templates')
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<Template | 'new' | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const templates = data?.data.templates ?? []
  const groups = useMemo(() => {
    const filtered = templates.filter((x) => !q || `${x.name_ar} ${x.name_en} ${x.title_ar} ${x.title_en} ${x.event}`.toLowerCase().includes(q.toLowerCase()))
    return GROUP_ORDER.map((g) => ({ id: g, items: filtered.filter((x) => x.group === g) })).filter((g) => g.items.length)
  }, [templates, q])

  if (isLoading || !data) return <Spinner />
  const stats = { on: templates.filter((x) => x.enabled).length, off: templates.filter((x) => !x.enabled).length, custom: templates.filter((x) => x.customised || !x.is_system).length }

  const toggle = async (tpl: Template, patch: Partial<Pick<Template, 'enabled' | 'push' | 'email' | 'sms'>>) => {
    setBusy(tpl.id)
    try { await api.put(`/admin/notifications/templates/${tpl.id}`, patch); await refetch() } catch (e) { setNotice(errorMessage(e)) } finally { setBusy(null) }
  }

  return (
    <div className="pb-6">
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><BellRing className="size-5" /></span>{t('mgmt.settings.sections.templates.title')}</span>}
        subtitle={t('mgmt.notif.templates.subtitle')}
        actions={<Button variant="gold" icon={<FilePlus2 className="size-4" />} onClick={() => setEditing('new')}>{t('mgmt.notif.templates.new')}</Button>}
      />

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        {[
          { label: t('mgmt.notif.templates.stats.on'), value: stats.on, tone: 'text-emerald-700 bg-emerald-50 border-emerald-200' },
          { label: t('mgmt.notif.templates.stats.off'), value: stats.off, tone: 'text-slate-600 bg-slate-50 border-slate-200' },
          { label: t('mgmt.notif.templates.stats.custom'), value: stats.custom, tone: 'text-gold-700 bg-gold-100/50 border-gold-300' },
        ].map((s) => <div key={s.label} className={clsx('rounded-2xl border p-4', s.tone)}><div className="text-xs font-semibold opacity-80">{s.label}</div><div className="mt-1 text-3xl font-extrabold">{s.value}</div></div>)}
      </div>

      {notice && <div className="mb-4 rounded-2xl bg-red-50 p-3 text-sm font-semibold text-danger">{notice}</div>}
      <div className="relative mb-5 max-w-md"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mgmt.notif.templates.search')} aria-label={t('mgmt.notif.templates.search')} /></div>

      <div className="space-y-8">
        {groups.map((g) => (
          <section key={g.id}>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-500">{data.data.groups[g.id]?.[lang] ?? g.id}<span className="rounded-full bg-navy-100/70 px-2 py-0.5 text-xs">{g.items.length}</span></h2>
            <ul className="space-y-3">
              {g.items.map((tpl) => (
                <li key={tpl.id} className={clsx('rounded-2xl border bg-white p-4 shadow-sm transition', tpl.enabled ? 'border-navy-100' : 'border-slate-200 bg-slate-50/70')}>
                  <div className="flex flex-wrap items-start gap-4">
                    <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl', tpl.enabled ? 'bg-navy-900 text-gold-300' : 'bg-slate-200 text-slate-400')}>{tpl.enabled ? <BellRing className="size-5" /> : <BellOff className="size-5" />}</span>
                    <div className="min-w-0 flex-1 basis-64">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className={clsx('font-bold', tpl.enabled ? 'text-navy-900' : 'text-slate-500')}>{lang === 'ar' ? tpl.name_ar : tpl.name_en}</span>
                        {tpl.customised && <Badge color="gold">{t('mgmt.notif.templates.customised')}</Badge>}
                        {!tpl.is_system && <Badge color="navy">{t('mgmt.notif.templates.custom')}</Badge>}
                      </div>
                      <div className="mt-1 line-clamp-2 text-sm text-slate-600" dir={lang === 'ar' ? 'rtl' : 'ltr'}><b>{lang === 'ar' ? tpl.title_ar : tpl.title_en}</b>{(lang === 'ar' ? tpl.body_ar : tpl.body_en) && <> — {lang === 'ar' ? tpl.body_ar : tpl.body_en}</>}</div>
                      <code className="mt-1 inline-block font-mono text-[10px] text-slate-400" dir="ltr">{tpl.event}</code>
                    </div>
                    <div className="flex items-center gap-5">
                      <label className="flex flex-col items-center gap-1 text-[11px] font-semibold text-slate-500">{t('mgmt.notif.templates.enabled')}<Switch checked={tpl.enabled} label={t('mgmt.notif.templates.enabled')} onChange={(v) => void toggle(tpl, { enabled: v })} /></label>
                      <label className={clsx('flex flex-col items-center gap-1 text-[11px] font-semibold text-slate-500', !tpl.enabled && 'opacity-40')}><span className="flex items-center gap-1"><Smartphone className="size-3" />{t('mgmt.notif.templates.push')}</span><Switch small checked={tpl.push} label={t('mgmt.notif.templates.push')} onChange={(v) => void toggle(tpl, { push: v })} /></label>
                      <label className={clsx('flex flex-col items-center gap-1 text-[11px] font-semibold text-slate-500', !tpl.enabled && 'opacity-40')}><span className="flex items-center gap-1"><Mail className="size-3" />{t('channels.email')}</span><Switch small checked={tpl.email} label={t('channels.email')} onChange={(v) => void toggle(tpl, { email: v })} /></label>
                      <label className={clsx('flex flex-col items-center gap-1 text-[11px] font-semibold text-slate-500', !tpl.enabled && 'opacity-40')}><span className="flex items-center gap-1"><MessageSquareText className="size-3" />{t('channels.sms')}</span><Switch small checked={tpl.sms} label={t('channels.sms')} onChange={(v) => void toggle(tpl, { sms: v })} /></label>
                      <Button size="sm" variant="outline" icon={<Pencil className="size-4" />} loading={busy === tpl.id} onClick={() => setEditing(tpl)}>{t('common.edit')}</Button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      {editing && <TemplateEditor template={editing === 'new' ? null : editing} variables={data.data.variables} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); void refetch() }} />}
    </div>
  )
}

function TemplateEditor({ template, variables, onClose, onSaved }: { template: Template | null; variables: string[]; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const center = useCenterName()
  const [form, setForm] = useState({
    name_ar: template?.name_ar ?? '', name_en: template?.name_en ?? '', title_ar: template?.title_ar ?? '', title_en: template?.title_en ?? '',
    body_ar: template?.body_ar ?? '', body_en: template?.body_en ?? '',
  })
  const [lang, setLang] = useState<'ar' | 'en'>('ar')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const focused = useRef<keyof typeof form>('title_ar')
  const refs = useRef<Partial<Record<keyof typeof form, HTMLInputElement | HTMLTextAreaElement | null>>>({})
  const preview = usePreview(form)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const insert = (token: string) => {
    const key = focused.current.startsWith('name') ? 'title_ar' : focused.current
    const el = refs.current[key]
    const start = el?.selectionStart ?? form[key].length
    const end = el?.selectionEnd ?? start
    const next = form[key].slice(0, start) + token + form[key].slice(end)
    set(key, next)
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length) })
  }

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      if (template) await api.put(`/admin/notifications/templates/${template.id}`, form)
      else await api.post('/admin/notifications/templates', form)
      onSaved()
    } catch (e) { setError(errorMessage(e)); setSaving(false) }
  }
  const reset = async () => { if (template) { await api.post(`/admin/notifications/templates/${template.id}/reset`); onSaved() } }
  const remove = async () => { if (template && window.confirm(t('mgmt.notif.templates.confirmDelete'))) { await api.delete(`/admin/notifications/templates/${template.id}`); onSaved() } }

  const field = (k: keyof typeof form, label: string, multiline = false, dir: 'rtl' | 'ltr' = 'rtl') => {
    const common = { value: form[k], dir, onFocus: () => { focused.current = k }, onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => set(k, e.target.value) }
    return (
      <Field label={label}>
        {multiline
          ? <textarea ref={(el) => { refs.current[k] = el }} className="input min-h-24" maxLength={1000} {...common} />
          : <input ref={(el) => { refs.current[k] = el }} className="input" maxLength={200} {...common} />}
      </Field>
    )
  }

  return (
    <Modal open onClose={onClose} wide title={template ? t('mgmt.notif.templates.edit') : t('mgmt.notif.templates.new')}>
      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-4">
          {(!template || !template.is_system) && <div className="grid gap-3 sm:grid-cols-2">{field('name_ar', t('mgmt.notif.templates.nameAr'))}{field('name_en', t('mgmt.notif.templates.nameEn'), false, 'ltr')}</div>}
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-3">{field('title_ar', t('mgmt.notif.templates.titleAr'))}{field('body_ar', t('mgmt.notif.templates.bodyAr'), true)}</div>
            <div className="space-y-3">{field('title_en', t('mgmt.notif.templates.titleEn'), false, 'ltr')}{field('body_en', t('mgmt.notif.templates.bodyEn'), true, 'ltr')}</div>
          </div>
          <div>
            <div className="mb-1.5 text-xs font-semibold text-slate-500">{t('mgmt.notif.templates.variables')}</div>
            <VariableChips variables={variables} onInsert={insert} />
            <p className="mt-2 text-xs text-slate-400">{t('mgmt.notif.templates.variablesHint')}</p>
          </div>
        </div>
        <div>
          <div role="tablist" className="mb-3 inline-flex rounded-xl border border-navy-100 p-1 text-xs font-bold">
            {(['ar', 'en'] as const).map((l) => <button key={l} type="button" role="tab" aria-selected={lang === l} onClick={() => setLang(l)} className={clsx('rounded-lg px-4 py-1.5', lang === l ? 'bg-navy-900 text-white' : 'text-slate-500')}>{l === 'ar' ? 'العربية' : 'English'}</button>)}
          </div>
          <div className="rounded-3xl bg-gradient-to-br from-navy-950 to-navy-800 p-4">
            <div className="mb-3 text-center text-[11px] font-semibold text-white/60">{t('mgmt.notif.templates.preview')}</div>
            <PhoneNotification lang={lang} appName={center} title={preview ? preview[`title_${lang}`] : ''} body={preview ? preview[`body_${lang}`] : ''} />
          </div>
        </div>
      </div>
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      <div className="mt-6 flex flex-wrap items-center gap-2">
        {template?.is_system && template.customised && <Button variant="ghost" icon={<RotateCcw className="size-4" />} onClick={reset}>{t('mgmt.notif.templates.reset')}</Button>}
        {template && !template.is_system && <Button variant="ghost" icon={<Trash2 className="size-4" />} onClick={remove}>{t('common.delete')}</Button>}
        <span className="ms-auto flex gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={saving} disabled={!form.title_ar.trim() || !form.title_en.trim() || (!template && (!form.name_ar.trim() || !form.name_en.trim()))} onClick={save}>{t('common.save')}</Button></span>
      </div>
    </Modal>
  )
}
