import clsx from 'clsx'
import { BellRing, BookOpenCheck, Check, ClipboardCheck, FileCheck2, GraduationCap, Library, Search, UserRoundCheck } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardTitle, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { Switch } from '../notifications/shared'
import type { Kit, KitsState } from './kitsState'

/**
 * The training kits chosen for a programme and the evaluation instruments generated from them: the trainee survey, the
 * trainer's self-reflection and — when a chosen kit carries assessment tools — the pre-test and the post-test. The
 * card also says who is told, and when, so nothing about the programme's announcements is a surprise.
 */
export default function ProgramKits({ deliveryMode, state, onChange, open }: { deliveryMode: string; state: KitsState; onChange: (s: KitsState) => void; open: boolean }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Kit[] }>('/admin/programs/kit-options', { delivery_mode: deliveryMode }, { staleTime: 60_000 })
  const [q, setQ] = useState('')
  const kits = useMemo(() => res.data?.data ?? [], [res.data])
  const chosen = kits.filter((k) => state.kitIds.includes(k.id))
  const shown = useMemo(() => kits.filter((k) => !q.trim() || `${k.title_ar} ${k.title_en} ${k.code}`.toLowerCase().includes(q.trim().toLowerCase())), [kits, q])
  const nm = (k: Kit) => (en && k.title_en ? k.title_en : k.title_ar)
  const toggle = (id: string) => onChange({ ...state, kitIds: state.kitIds.includes(id) ? state.kitIds.filter((x) => x !== id) : [...state.kitIds, id] })
  const setInstr = (p: Partial<KitsState['instruments']>) => onChange({ ...state, instruments: { ...state.instruments, ...p } })
  const preKit = chosen.find((k) => k.has_pre)
  const postKit = chosen.find((k) => k.has_post)

  const rows: { key: 'trainee_survey' | 'trainer_reflection' | 'pre_test' | 'post_test'; icon: React.ReactNode; available: boolean; note: React.ReactNode }[] = [
    { key: 'trainee_survey', icon: <ClipboardCheck className="size-5" />, available: true, note: (
      <span className="flex flex-wrap items-center gap-2">{t('admin.programs.kits.traineeNote')}
        <label className="inline-flex items-center gap-1 text-xs text-slate-500">{t('admin.programs.kits.afterHours')}<input type="number" min={0} max={720} className="input w-20 py-1" value={state.instruments.survey_auto_hours} disabled={!state.instruments.trainee_survey} onChange={(e) => setInstr({ survey_auto_hours: Math.max(0, Math.min(720, Number(e.target.value) || 0)) })} />{t('admin.programs.kits.hours')}</label></span>) },
    { key: 'trainer_reflection', icon: <UserRoundCheck className="size-5" />, available: true, note: t('admin.programs.kits.trainerNote') },
    { key: 'pre_test', icon: <BookOpenCheck className="size-5" />, available: !!preKit, note: preKit ? t('admin.programs.kits.fromKit', { kit: nm(preKit), files: preKit.assessment_files.slice(0, 2).join('، ') }) : t('admin.programs.kits.noTool') },
    { key: 'post_test', icon: <FileCheck2 className="size-5" />, available: !!postKit, note: postKit ? t('admin.programs.kits.fromKit', { kit: nm(postKit), files: postKit.assessment_files.slice(0, 2).join('، ') }) : t('admin.programs.kits.noTool') },
  ]

  return (
    <>
      <Card>
        <CardTitle subtitle={t('admin.programs.kits.subtitle')} action={state.kitIds.length > 0 && <Badge color="gold">{t('admin.programs.kits.selected', { n: state.kitIds.length })}</Badge>}>
          <span className="inline-flex items-center gap-2"><Library className="size-5 text-gold-600" />{t('admin.programs.kits.title')}</span>
        </CardTitle>
        {res.isLoading ? <Spinner /> : !kits.length ? <Empty text={String(t('admin.programs.kits.none'))} icon={<Library className="size-6" />} /> : (
          <div className="space-y-3">
            <div className="relative"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('admin.programs.kits.search'))} /></div>
            <div className="grid max-h-[26rem] gap-2.5 overflow-y-auto pe-1 sm:grid-cols-2">
              {shown.map((k) => {
                const on = state.kitIds.includes(k.id)
                return (
                  <button key={k.id} type="button" onClick={() => toggle(k.id)} aria-pressed={on} className={clsx('rounded-2xl border p-3.5 text-start transition', on ? 'border-gold-400 bg-gold-50/70 shadow-sm' : 'border-navy-100 bg-white hover:border-gold-300')}>
                    <div className="flex items-start gap-3">
                      <span className={clsx('mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border', on ? 'border-gold-500 bg-gold-500 text-navy-950' : 'border-slate-300 bg-white')}>{on && <Check className="size-3.5" />}</span>
                      <div className="min-w-0 flex-1">
                        <div className="truncate font-bold text-navy-900">{nm(k)}</div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-slate-500"><span className="font-mono">{k.code}</span><span>{k.duration_hours} {t('admin.programs.kits.hours')}</span><span>{t('admin.programs.kits.filesN', { n: k.files })}</span></div>
                        <div className="mt-2 flex flex-wrap gap-1">
                          <Badge color={k.has_pre ? 'green' : 'gray'} className="font-normal">{t('admin.programs.kits.pre')}{k.has_pre ? ' ✓' : ''}</Badge>
                          <Badge color={k.has_post ? 'green' : 'gray'} className="font-normal">{t('admin.programs.kits.post')}{k.has_post ? ' ✓' : ''}</Badge>
                          {!k.matches && <Badge color="amber" className="font-normal">{t(`modes.${k.delivery}`)}</Badge>}
                        </div>
                      </div>
                    </div>
                  </button>
                )
              })}
            </div>
          </div>
        )}
      </Card>

      <Card>
        <CardTitle subtitle={t('admin.programs.kits.instrumentsHint')}><span className="inline-flex items-center gap-2"><GraduationCap className="size-5 text-gold-600" />{t('admin.programs.kits.instruments')}</span></CardTitle>
        <ul className="divide-y divide-slate-100">
          {rows.map((r) => {
            const on = r.available && state.instruments[r.key]
            return (
              <li key={r.key} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', on ? 'bg-navy-900 text-gold-300' : 'bg-slate-100 text-slate-400')}>{r.icon}</span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2"><span className="font-bold text-navy-900">{t(`admin.programs.kits.instr.${r.key}`)}</span>{!r.available && <Badge color="amber" className="font-normal">{t('admin.programs.kits.unavailable')}</Badge>}</div>
                  <div className="mt-0.5 text-xs text-slate-500">{r.note}</div>
                </div>
                <Switch checked={on} small disabled={!r.available} label={t(`admin.programs.kits.instr.${r.key}`)} onChange={(v) => setInstr({ [r.key]: v } as Partial<KitsState['instruments']>)} />
              </li>
            )
          })}
        </ul>
        <div className="mt-4 flex items-start gap-3 rounded-2xl border border-gold-200 bg-gold-50/60 p-3.5 text-xs text-slate-700">
          <BellRing className="mt-0.5 size-4 shrink-0 text-gold-700" />
          <div className="space-y-1">
            <div className="font-semibold text-navy-900">{t('admin.programs.kits.notifyTitle')}</div>
            <p>{open ? t('admin.programs.kits.notifyOpenNow') : t('admin.programs.kits.notifyOpen')}</p>
            <p>{t('admin.programs.kits.notifyAssign')}</p>
            <p className="text-slate-500">{t('admin.programs.kits.notifyChannels')}</p>
          </div>
        </div>
      </Card>
    </>
  )
}
