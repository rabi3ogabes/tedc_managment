import clsx from 'clsx'
import { Copy, Download, ExternalLink, FileText, Link2, Pencil, QrCode } from 'lucide-react'
import { QRCodeCanvas } from 'qrcode.react'
import { useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal, StatusBadge, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Details, Program } from './types'

const asText = (v: unknown): string => (Array.isArray(v) ? v.map(asText).filter(Boolean).join('، ') : v && typeof v === 'object' ? Object.values(v as Record<string, unknown>).map(asText).filter(Boolean).join('، ') : v == null ? '' : String(v))

/** «تفاصيل البرنامج» (A14): overview, groups and every day with its room and trainer. */
export function DetailsModal({ programId, onClose }: { programId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'overview' | 'groups' | 'days'>('overview')
  const { data } = useGet<{ data: Details }>(`/admin/saber/programs/${programId}/details`, undefined, { staleTime: 0 })
  const p = data?.data

  const attachment = async (type: 'program' | 'group', id: string) => {
    try { window.open((await api.get(`/admin/saber/cancellations/${type}/${id}/attachment`)).data.data.url, '_blank', 'noopener') } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <Modal open onClose={onClose} title={p ? <span className="flex items-center gap-2">{p.title}<span className="font-mono text-xs font-normal text-slate-400" dir="ltr">{p.code}</span></span> : t('saber.details.title')} wide>
      {!p ? <div className="h-48 animate-pulse rounded-xl bg-ivory" /> : (
        <>
          {p.cancel && (
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-rose-100 bg-rose-50/60 px-3 py-2 text-sm text-rose-800">
              <span className="flex-1">{t('saber.details.cancelled', { date: fmt.date(p.cancel.at), reason: p.cancel.reason ?? '—' })}</span>
              {p.cancel.has_attachment && <Button size="sm" variant="ghost" icon={<FileText className="size-4" />} onClick={() => attachment('program', p.id)}>{t('saber.cancel.file')}</Button>}
            </div>
          )}
          <Tabs tabs={(['overview', 'groups', 'days'] as const).map((id) => ({ id, label: t(`saber.details.tabs.${id}`) }))} value={tab} onChange={setTab} />
          <div className="mt-4 max-h-[60vh] overflow-y-auto">
            {tab === 'overview' && (
              <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <Item label={t('saber.details.status')}><StatusBadge status={p.status} /></Item>
                <Item label={t('saber.details.hours')}>{fmt.number(p.hours, 1)} · {t('saber.program.days', { n: p.days })}</Item>
                <Item label={t('saber.details.method')}>{p.method ? t(`modes.${p.method}`, { defaultValue: p.method }) : '—'}</Item>
                <Item label={t('saber.details.capacity')}>{fmt.number(p.capacity)}</Item>
                <Item label={t('saber.details.category')}>{p.category ?? '—'}</Item>
                <Item label={t('saber.program.supervisor')}>{p.supervisor?.name ?? t('saber.program.noSupervisor')}</Item>
                <Item label={t('saber.details.kits')}>
                  {p.kits.length === 0 ? '—' : <span className="flex flex-wrap gap-1">{p.kits.map((k) => <Badge key={k.id} color={k.status === 'published' || k.status === 'approved' ? 'green' : 'gray'}>{k.title}</Badge>)}</span>}
                </Item>
                <Item label={t('saber.survey.title')}>{p.survey_link ? <a href={p.survey_link} target="_blank" rel="noopener noreferrer" className="font-semibold text-navy-700 underline decoration-gold-400 underline-offset-2">{t('saber.survey.open')}</a> : t('saber.survey.none')}</Item>
                {p.summary && <Item label={t('saber.details.summary')} wide>{p.summary}</Item>}
                {asText(p.objectives) && <Item label={t('saber.details.objectives')} wide>{asText(p.objectives)}</Item>}
                {asText(p.audience) && <Item label={t('saber.details.audience')} wide>{asText(p.audience)}</Item>}
              </dl>
            )}
            {tab === 'groups' && (
              <ul className="space-y-2">
                {p.groups.map((g) => (
                  <li key={g.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 px-3 py-2.5" style={{ borderInlineStartWidth: 4, borderInlineStartColor: g.color ?? undefined }}>
                    <span className="font-bold text-navy-900">{g.title || t('saber.group.n', { n: g.number })}</span>
                    <StatusBadge status={g.status} label={t(`groups.status.${g.status}`, { defaultValue: g.status })} />
                    {g.validated_at && <Badge color="green">{t('saber.group.validated')}</Badge>}
                    <span className="text-xs text-slate-500">{t('saber.details.registrations')}: {fmt.number(g.registrations)}{g.capacity ? ` / ${fmt.number(g.capacity)}` : ''}</span>
                    <span className="text-xs text-slate-500">{g.term_effective ? t(`saber.group.terms.${g.term_effective}`) : ''}</span>
                    {g.status_reason && <span className="w-full text-xs text-rose-700">{g.status_reason}</span>}
                    {g.cancel?.has_attachment && <Button size="sm" variant="ghost" icon={<FileText className="size-4" />} onClick={() => attachment('group', g.id)}>{t('saber.cancel.file')}</Button>}
                  </li>
                ))}
              </ul>
            )}
            {tab === 'days' && (
              <div className="space-y-4">
                {p.groups.map((g) => (
                  <section key={g.id}>
                    <h4 className="mb-1.5 text-xs font-bold text-slate-500">{g.title || t('saber.group.n', { n: g.number })}</h4>
                    {g.days.every((d) => !d.date) ? <p className="text-sm text-slate-400">{t('saber.details.noDays')}</p> : (
                      <table className="w-full text-sm">
                        <tbody className="divide-y divide-navy-50">
                          {g.days.filter((d) => d.date).map((d) => (
                            <tr key={d.idx} className={clsx(d.status === 'cancelled' && 'text-slate-400 line-through')}>
                              <td className="py-1.5 pe-3 font-semibold text-navy-900">{t('saber.day.n', { n: d.idx })}</td>
                              <td className="py-1.5 pe-3">{fmt.date(d.date, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}</td>
                              <td className="py-1.5 pe-3 tabular-nums" dir="ltr">{d.start_time}–{d.end_time}</td>
                              <td className="py-1.5 pe-3">{d.room ?? '—'}</td>
                              <td className="py-1.5">{d.trainer ?? '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </section>
                ))}
              </div>
            )}
          </div>
        </>
      )}
    </Modal>
  )
}

function Item({ label, children, wide }: { label: string; children: ReactNode; wide?: boolean }) {
  return <div className={wide ? 'sm:col-span-2' : undefined}><dt className="text-[11px] font-bold text-slate-400">{label}</dt><dd className="mt-0.5 text-sm text-navy-900">{children}</dd></div>
}

/** The programme's survey link (A15): open, copy, a QR code to show or download, and the links edited by whoever manages them. */
export function SurveyLinks({ program, canEdit, onSaved }: { program: Program; canEdit: boolean; onSaved: (p: Program) => void }) {
  const { t } = useTranslation()
  const [qr, setQr] = useState(false)
  const [edit, setEdit] = useState(false)
  const [links, setLinks] = useState({ survey_link: program.survey_link ?? '', survey_edit_link: program.survey_edit_link ?? '' })
  const [saving, setSaving] = useState(false)
  const canvas = useRef<HTMLDivElement>(null)
  const link = program.survey_link

  const copy = async () => { try { await navigator.clipboard.writeText(link ?? ''); toast(t('saber.survey.copied')) } catch { toast(link ?? '', 'info') } }
  const download = () => {
    const c = canvas.current?.querySelector('canvas')
    if (!c) return
    const a = document.createElement('a')
    a.href = c.toDataURL('image/png'); a.download = `survey-${program.code}.png`; a.click()
  }
  const save = async () => {
    setSaving(true)
    try {
      const { data } = await api.patch(`/admin/saber/programs/${program.id}`, { survey_link: links.survey_link.trim() || null, survey_edit_link: links.survey_edit_link.trim() || null })
      onSaved(data.data); toast(t('saber.survey.saved')); setEdit(false)
    } catch (e) { toast(errorMessage(e), 'error') } finally { setSaving(false) }
  }
  const icon = 'grid size-8 place-items-center rounded-lg text-slate-500 transition hover:bg-white hover:text-navy-900 disabled:opacity-30'

  return (
    <div className="flex items-center gap-1 rounded-xl border border-navy-100 bg-white/70 py-0.5 pe-0.5 ps-2.5">
      <Link2 className="size-4 text-gold-600" />
      <span className={clsx('me-1 text-xs font-semibold', link ? 'text-navy-900' : 'text-slate-400')}>{link ? t('saber.survey.title') : t('saber.survey.none')}</span>
      <a href={link ?? undefined} target="_blank" rel="noopener noreferrer" aria-label={t('saber.survey.open')} title={t('saber.survey.open')} className={clsx(icon, !link && 'pointer-events-none opacity-30')}><ExternalLink className="size-4" /></a>
      <button type="button" disabled={!link} onClick={copy} aria-label={t('saber.survey.copy')} title={t('saber.survey.copy')} className={icon}><Copy className="size-4" /></button>
      <button type="button" disabled={!link} onClick={() => setQr(true)} aria-label={t('saber.survey.qr')} title={t('saber.survey.qr')} className={icon}><QrCode className="size-4" /></button>
      {canEdit && <button type="button" onClick={() => { setLinks({ survey_link: program.survey_link ?? '', survey_edit_link: program.survey_edit_link ?? '' }); setEdit(true) }} aria-label={t('saber.survey.edit')} title={t('saber.survey.edit')} className={icon}><Pencil className="size-4" /></button>}

      {qr && link && (
        <Modal open onClose={() => setQr(false)} title={t('saber.survey.qr')}>
          <div className="flex flex-col items-center gap-4">
            <div ref={canvas} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100"><QRCodeCanvas value={link} size={240} marginSize={2} /></div>
            <div className="max-w-full truncate text-xs text-slate-500" dir="ltr">{link}</div>
            <div className="text-center text-sm font-bold text-navy-900">{program.title}</div>
            <Button icon={<Download className="size-4" />} onClick={download}>{t('saber.survey.download')}</Button>
          </div>
        </Modal>
      )}
      {edit && (
        <Modal open onClose={() => setEdit(false)} title={t('saber.survey.edit')}>
          <div className="space-y-3">
            <Field label={t('saber.survey.link')}><input dir="ltr" value={links.survey_link} onChange={(e) => setLinks((l) => ({ ...l, survey_link: e.target.value }))} placeholder="https://" className="h-10 w-full rounded-xl border border-navy-100 px-3 text-sm" /></Field>
            <Field label={t('saber.survey.editLink')}><input dir="ltr" value={links.survey_edit_link} onChange={(e) => setLinks((l) => ({ ...l, survey_edit_link: e.target.value }))} placeholder="https://" className="h-10 w-full rounded-xl border border-navy-100 px-3 text-sm" /></Field>
            {program.survey_edit_link && <a href={program.survey_edit_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-bold text-navy-700 hover:text-gold-700"><ExternalLink className="size-3.5" />{t('saber.survey.editLink')}</a>}
          </div>
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setEdit(false)}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button>
            <Button loading={saving} onClick={save}>{t('common.save', { defaultValue: 'حفظ' })}</Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
