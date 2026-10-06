/* eslint-disable @typescript-eslint/no-explicit-any */
import { Route, SkipForward, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Card } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'

/** The learner's adaptive path: modules skipped because they are mastered, and remedial lessons recommended — each with the reason. */
export default function AdaptivePathCard({ registrationId }: { registrationId: string }) {
  const { t, i18n } = useTranslation()
  const on = useFeature('ai')
  const res = useGet<{ data: any }>(on ? `/me/courses/${registrationId}/path` : null, undefined, { staleTime: 0, retry: false })
  const d = res.data?.data
  const en = i18n.language === 'en'
  if (!on || !d || (!d.skipped.length && !d.remedial.length)) return null
  const titles: Record<string, string> = {}
  for (const m of d.modules) for (const l of m.lessons) titles[l.id] = en ? l.title_en || l.title_ar : l.title_ar
  const modTitle = (id: string) => { const m = d.modules.find((x: any) => x.module_id === id); return m ? (en ? m.title_en || m.title_ar : m.title_ar) : '' }
  return (
    <Card className="space-y-3 border-gold-200 bg-gold-50/30">
      <h3 className="flex items-center gap-2 font-bold text-navy-900"><Route className="size-5 text-gold-600" />{t('aix.path.title')}</h3>
      <p className="text-xs text-slate-500">{t('aix.path.hint')}</p>
      <ul className="space-y-2 text-sm">
        {d.skipped.map((s: any) => <li key={s.module_id} className="flex items-start gap-2"><SkipForward className="mt-0.5 size-4 shrink-0 text-emerald-600" /><span><b>{modTitle(s.module_id)}</b> — {t('aix.path.mastered', { skill: en ? s.skill.en || s.skill.ar : s.skill.ar, m: Math.round(s.mastery * 100) })}</span></li>)}
        {d.remedial.map((r: any) => <li key={r.lesson_id} className="flex items-start gap-2"><Sparkles className="mt-0.5 size-4 shrink-0 text-gold-600" /><span><b>{titles[r.lesson_id]}</b> <Badge color="gold">{t('aix.path.remedial')}</Badge> — {t('aix.path.weak', { m: Math.round(r.mastery * 100) })}</span></li>)}
      </ul>
    </Card>
  )
}
