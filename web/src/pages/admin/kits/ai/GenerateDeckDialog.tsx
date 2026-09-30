import clsx from 'clsx'
import { Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import type { Kit, KitFile } from '../types'

type Result = { file: KitFile; provider: string; ai_available: boolean; slides: number }

/** Describe the session and the system builds a complete, editable deck. */
export default function GenerateDeckDialog({ kit, seed, onClose }: { kit: Kit; seed?: Record<string, unknown>; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const status = useGet<{ data: { ai: boolean } }>('/admin/kits/ai/status')
  const [form, setForm] = useState(() => ({
    topic: String(seed?.topic ?? (i18n.language === 'en' ? kit.title_en : kit.title_ar)), audience: String(seed?.audience ?? kit.audience ?? ''),
    objectives: ((seed?.objectives as string[] | undefined) ?? kit.objectives).join('\n'), slide_count: Number(seed?.slide_count ?? 12), language: (i18n.language === 'en' ? 'en' : 'ar') as 'ar' | 'en',
    tone: 'professional', duration_hours: Number(seed?.duration_hours ?? kit.duration_hours ?? 0) || 0, activities: true, quiz: true, instructions: String(seed?.instructions ?? ''), name: '',
  }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }))

  const run = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Result }>(`/admin/kits/${kit.id}/ai/deck`, {
        ...form, objectives: form.objectives.split('\n').map((s) => s.trim()).filter(Boolean), duration_hours: form.duration_hours || null, audience: form.audience || null, instructions: form.instructions || null, name: form.name || null,
      })
      navigate(`/admin/kits/${kit.id}/files/${res.data.data.file.id}`)
    } catch (e) {
      setError(errorMessage(e))
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={busy ? () => undefined : onClose} wide title={t('kits.gen.deckTitle')}>
      <div className="space-y-4">
        {status.data && (
          <p className={clsx('rounded-xl px-3 py-2 text-xs leading-relaxed', status.data.data.ai ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800')}>{status.data.data.ai ? t('kits.gen.aiOn') : t('kits.gen.aiOff')}</p>
        )}
        <Field label={t('kits.gen.topic')}><input className="input" dir="auto" value={form.topic} onChange={(e) => set('topic', e.target.value)} /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('kits.gen.audience')}><input className="input" dir="auto" value={form.audience} onChange={(e) => set('audience', e.target.value)} /></Field>
          <Field label={t('kits.gen.name')} hint={t('kits.gen.nameHint')}><input className="input" dir="auto" value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
        </div>
        <Field label={t('kits.gen.objectives')} hint={t('kits.gen.objectivesHint')}><textarea rows={3} className="input" dir="auto" value={form.objectives} onChange={(e) => set('objectives', e.target.value)} /></Field>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('kits.gen.slideCount', { count: form.slide_count })}><input type="range" min={6} max={30} value={form.slide_count} onChange={(e) => set('slide_count', Number(e.target.value))} className="w-full accent-gold-600" /></Field>
          <Field label={t('kits.gen.duration')}><input type="number" min={0} max={100} step={0.5} className="input" value={form.duration_hours} onChange={(e) => set('duration_hours', Number(e.target.value))} /></Field>
          <Field label={t('kits.gen.language')}>
            <div className="flex gap-1" role="radiogroup">{(['ar', 'en'] as const).map((l) => <button key={l} type="button" role="radio" aria-checked={form.language === l} onClick={() => set('language', l)} className={clsx('flex-1 rounded-xl border px-3 py-2 text-sm font-semibold transition', form.language === l ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600')}>{l === 'ar' ? 'العربية' : 'English'}</button>)}</div>
          </Field>
        </div>
        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-navy-800">
          <label className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-gold-600" checked={form.activities} onChange={(e) => set('activities', e.target.checked)} />{t('kits.gen.activities')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-gold-600" checked={form.quiz} onChange={(e) => set('quiz', e.target.checked)} />{t('kits.gen.quiz')}</label>
        </div>
        <Field label={t('kits.gen.instructions')} hint={t('kits.gen.instructionsHint')}><textarea rows={2} className="input" dir="auto" value={form.instructions} onChange={(e) => set('instructions', e.target.value)} /></Field>
        {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <div className="flex items-center justify-between gap-3">
          <p className="text-xs text-slate-400">{busy ? t('kits.gen.working') : t('kits.gen.editable')}</p>
          <div className="flex gap-2"><Button variant="ghost" onClick={onClose} disabled={busy}>{t('kits.common.cancel')}</Button><Button variant="gold" loading={busy} disabled={form.topic.trim().length < 3} icon={<Sparkles className="size-4" />} onClick={run}>{t('kits.gen.create')}</Button></div>
        </div>
      </div>
    </Modal>
  )
}
