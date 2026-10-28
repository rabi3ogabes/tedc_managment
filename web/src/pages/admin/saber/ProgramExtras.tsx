import clsx from 'clsx'
import { FileText } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Modal, StatusBadge, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { FormsSummary } from './FormLinks'
import type { Details } from './types'

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
                {p.forms && <Item label={t('saber.forms.title')} wide><FormsSummary forms={p.forms} /></Item>}
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
