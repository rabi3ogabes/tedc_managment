import clsx from 'clsx'
import { CheckCircle2, Send, Users } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useCenterName } from '@/lib/ThemeProvider'
import type { Paginated, Program } from '@/lib/types'
import ChannelPicker, { type Channel } from './ChannelPicker'
import { PhoneNotification, usePreview, VariableChips, type TemplateData } from './shared'

type Audience = 'trainees' | 'pending_survey'
export type Sent = { id: string; recipients: number; title: string }

/** Write (or pick a template for) a notification and send it to the trainees of a program. */
export default function SendNotificationDialog({ programId, defaultEvent, defaultAudience = 'trainees', onClose, onSent }: {
  programId?: string; defaultEvent?: string; defaultAudience?: Audience; onClose: () => void; onSent?: (c: Sent) => void
}) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const center = useCenterName()
  const programs = useGet<Paginated<Program>>(programId ? null : '/admin/programs', { per_page: 100 })
  const templates = useGet<{ data: TemplateData }>('/admin/notifications/templates')
  const [program, setProgram] = useState(programId ?? '')
  const [audience, setAudience] = useState<Audience>(defaultAudience)
  const [templateId, setTemplateId] = useState('')
  const [form, setForm] = useState({ title_ar: '', title_en: '', body_ar: '', body_en: '' })
  const [previewLang, setPreviewLang] = useState<'ar' | 'en'>(lang)
  const [channels, setChannels] = useState<Channel[] | null>(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<Sent | null>(null)
  const focused = useRef<keyof typeof form>('title_ar')
  const refs = useRef<Partial<Record<keyof typeof form, HTMLInputElement | HTMLTextAreaElement | null>>>({})
  const count = useGet<{ data: { count: number } }>(program ? '/admin/notifications/audience' : null, { program_id: program, audience })
  const preview = usePreview(form, program || null)
  const list = templates.data?.data.templates ?? []

  const apply = (id: string) => {
    setTemplateId(id)
    const tpl = list.find((x) => x.id === id)
    if (tpl) setForm({ title_ar: tpl.title_ar, title_en: tpl.title_en, body_ar: tpl.body_ar ?? '', body_en: tpl.body_en ?? '' })
  }
  // Pre-select the requested template once the list is loaded.
  const applied = useRef(false)
  useEffect(() => {
    if (!applied.current && defaultEvent && list.length) { applied.current = true; apply(list.find((x) => x.event === defaultEvent)?.id ?? '') }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list.length])

  const insert = (token: string) => {
    const key = focused.current
    const el = refs.current[key]
    const start = el?.selectionStart ?? form[key].length
    const end = el?.selectionEnd ?? start
    setForm((f) => ({ ...f, [key]: f[key].slice(0, start) + token + f[key].slice(end) }))
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length) })
  }

  const send = async () => {
    setSending(true)
    setError(null)
    try {
      const { data } = await api.post('/admin/notifications/send', { program_id: program, audience, template_id: templateId || undefined, ...form, notify_channels: channels ?? undefined })
      setDone({ id: data.data.id, recipients: data.data.recipients, title: data.data.title })
      onSent?.(data.data)
    } catch (e) { setError(errorMessage(e)) } finally { setSending(false) }
  }

  const n = count.data?.data.count ?? 0
  const ready = !!program && n > 0 && !!form.title_ar.trim() && !!form.title_en.trim()
  const input = (k: keyof typeof form, label: string, multiline = false, dir: 'rtl' | 'ltr' = 'rtl') => {
    const props = { value: form[k], dir, onFocus: () => { focused.current = k }, onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm((f) => ({ ...f, [k]: e.target.value })) }
    return <Field label={label}>{multiline ? <textarea ref={(el) => { refs.current[k] = el }} className="input min-h-20" maxLength={1000} {...props} /> : <input ref={(el) => { refs.current[k] = el }} className="input" maxLength={200} {...props} />}</Field>
  }

  if (done) {
    return (
      <Modal open onClose={onClose} title={t('mgmt.notif.send.title')}>
        <div className="py-6 text-center">
          <span className="mx-auto grid size-16 place-items-center rounded-full bg-emerald-50 text-emerald-600"><CheckCircle2 className="size-9" /></span>
          <h3 className="mt-4 text-xl font-bold text-navy-900">{t('mgmt.notif.send.sent', { count: done.recipients })}</h3>
          <p className="mt-1 text-sm text-slate-500">{t('mgmt.notif.send.trackHint')}</p>
          <Button className="mt-6" variant="gold" onClick={onClose}>{t('common.close')}</Button>
        </div>
      </Modal>
    )
  }

  return (
    <Modal open onClose={onClose} wide title={t('mgmt.notif.send.title')}>
      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-4">
          {!programId && (
            <Field label={t('mgmt.notif.send.program')}>
              <select className="input" value={program} onChange={(e) => setProgram(e.target.value)}>
                <option value="">{t('mgmt.notif.send.pickProgram')}</option>
                {programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
              </select>
            </Field>
          )}
          <div>
            <div className="mb-1.5 text-xs font-semibold text-slate-500">{t('mgmt.notif.send.audience')}</div>
            <div role="radiogroup" className="grid grid-cols-2 gap-2">
              {(['trainees', 'pending_survey'] as const).map((a) => (
                <button key={a} type="button" role="radio" aria-checked={audience === a} onClick={() => setAudience(a)} className={clsx('rounded-xl border p-3 text-start transition', audience === a ? 'border-gold-400 bg-gold-100/40 shadow-sm' : 'border-navy-100 hover:border-gold-300')}>
                  <div className="text-sm font-bold text-navy-900">{t(`mgmt.notif.send.audiences.${a}`)}</div>
                  <div className="text-[11px] text-slate-500">{t(`mgmt.notif.send.audiences.${a}Hint`)}</div>
                </button>
              ))}
            </div>
          </div>
          <Field label={t('mgmt.notif.send.template')}>
            <select className="input" value={templateId} onChange={(e) => apply(e.target.value)}>
              <option value="">{t('mgmt.notif.send.custom')}</option>
              {list.map((x) => <option key={x.id} value={x.id}>{lang === 'ar' ? x.name_ar : x.name_en}</option>)}
            </select>
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-3">{input('title_ar', t('mgmt.notif.templates.titleAr'))}{input('body_ar', t('mgmt.notif.templates.bodyAr'), true)}</div>
            <div className="space-y-3">{input('title_en', t('mgmt.notif.templates.titleEn'), false, 'ltr')}{input('body_en', t('mgmt.notif.templates.bodyEn'), true, 'ltr')}</div>
          </div>
          <VariableChips variables={(templates.data?.data.variables ?? []).filter((v) => v !== 'name')} onInsert={insert} />
          <ChannelPicker value={channels} onChange={setChannels} />
        </div>
        <div>
          <div role="tablist" className="mb-3 inline-flex rounded-xl border border-navy-100 p-1 text-xs font-bold">
            {(['ar', 'en'] as const).map((l) => <button key={l} type="button" role="tab" aria-selected={previewLang === l} onClick={() => setPreviewLang(l)} className={clsx('rounded-lg px-4 py-1.5', previewLang === l ? 'bg-navy-900 text-white' : 'text-slate-500')}>{l === 'ar' ? 'العربية' : 'English'}</button>)}
          </div>
          <div className="rounded-3xl bg-gradient-to-br from-navy-950 to-navy-800 p-4">
            <div className="mb-3 text-center text-[11px] font-semibold text-white/60">{t('mgmt.notif.templates.preview')}</div>
            <PhoneNotification lang={previewLang} appName={center} title={preview ? preview[`title_${previewLang}`] : ''} body={preview ? preview[`body_${previewLang}`] : ''} />
          </div>
          <div className={clsx('mt-4 flex items-center gap-3 rounded-2xl border p-3', program && n === 0 ? 'border-amber-200 bg-amber-50' : 'border-navy-100 bg-ivory')}>
            <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Users className="size-5" /></span>
            <div><div className="text-2xl font-extrabold text-navy-900">{program ? n : '—'}</div><div className="text-xs text-slate-500">{t('mgmt.notif.send.willReach')}</div></div>
          </div>
        </div>
      </div>
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="gold" icon={<Send className="size-4" />} loading={sending} disabled={!ready} onClick={send}>{t('mgmt.notif.send.send', { count: n })}</Button>
      </div>
    </Modal>
  )
}
