import { useTranslation } from 'react-i18next'
import { Button, Card } from '@/components/ui'
import type { LessonDetail } from './types'

/** Plain text with light structure: blank line = new paragraph, "# " = heading, "- " = bullet. */
export default function Article({ lesson, onDone }: { lesson: LessonDetail; onDone: () => void }) {
  const { t } = useTranslation()
  const blocks = (lesson.body ?? '').split(/\n{2,}/).map((b) => b.trim()).filter(Boolean)
  return (
    <Card className="space-y-4 p-6 sm:p-8">
      <article className="prose-lesson space-y-4 text-[1.05rem] leading-8 text-slate-800">
        {blocks.map((b, i) => {
          if (b.startsWith('# ')) return <h3 key={i} className="pt-2 text-xl font-bold text-navy-900">{b.slice(2)}</h3>
          if (b.split('\n').every((l) => l.trim().startsWith('- '))) return <ul key={i} className="list-disc space-y-1 ps-6">{b.split('\n').map((l, k) => <li key={k}>{l.replace(/^\s*-\s/, '')}</li>)}</ul>
          return <p key={i} className="whitespace-pre-line">{b}</p>
        })}
      </article>
      {lesson.progress.status !== 'completed' && <div className="border-t border-navy-100 pt-4"><Button variant="gold" onClick={onDone}>{t('learn.markDone')}</Button></div>}
    </Card>
  )
}
