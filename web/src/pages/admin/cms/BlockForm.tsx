/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Field } from '@/components/ui'

type F = { k: string; kind: 'bi' | 'area' | 'text' | 'num' | 'url' | 'list'; fields?: F[] }
const bi = (k: string): F => ({ k, kind: 'bi' })
const area = (k: string): F => ({ k, kind: 'area' })
const url = (k: string): F => ({ k, kind: 'url' })

export const SCHEMA: Record<string, F[]> = {
  hero_slider: [{ k: 'slides', kind: 'list', fields: [bi('title'), bi('subtitle'), url('image'), bi('label'), url('url')] }],
  stats: [bi('title')],
  featured_programs: [bi('title'), { k: 'limit', kind: 'num' }],
  news: [bi('title'), { k: 'limit', kind: 'num' }],
  events: [bi('title'), { k: 'limit', kind: 'num' }],
  rich_text: [bi('title'), area('body')],
  cta: [bi('title'), bi('text'), bi('label'), url('url')],
  logos: [bi('title'), { k: 'items', kind: 'list', fields: [bi('name'), url('image'), url('url')] }],
  faq: [bi('title'), { k: 'items', kind: 'list', fields: [bi('question'), area('answer')] }],
  video: [bi('title'), url('video_url'), bi('text')],
  custom_html_safe: [area('html')],
}

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

function Inputs({ fields, value, onChange }: { fields: F[]; value: any; onChange: (v: any) => void }) {
  const { t } = useTranslation()
  const set = (k: string, v: any) => onChange({ ...value, [k]: v })
  return (
    <div className="grid gap-3">
      {fields.map((f) => {
        const label = String(t(`comm.home.f.${f.k}`))
        if (f.kind === 'list') {
          const items: any[] = value?.[f.k] ?? []
          return (
            <div key={f.k} className="space-y-2">
              <div className="text-xs font-bold text-slate-500">{label}</div>
              {items.map((it, i) => (
                <div key={i} className="relative rounded-xl border border-navy-100 bg-ivory/50 p-3">
                  <button className="absolute end-2 top-2 text-slate-400 hover:text-danger" onClick={() => set(f.k, items.filter((_, j) => j !== i))} aria-label="remove"><Trash2 className="size-4" /></button>
                  <Inputs fields={f.fields!} value={it} onChange={(v) => set(f.k, items.map((x, j) => (j === i ? v : x)))} />
                </div>
              ))}
              <Button size="sm" variant="outline" icon={<Plus className="size-3" />} onClick={() => set(f.k, [...items, {}])}>{t('comm.home.f.addItem')}</Button>
            </div>
          )
        }
        if (f.kind === 'bi' || f.kind === 'area') {
          const v = value?.[f.k] ?? {}
          const Tag = f.kind === 'area' ? 'textarea' : 'input'
          return (
            <Field key={f.k} label={label}>
              <div className="grid gap-2 sm:grid-cols-2">
                {(['ar', 'en'] as const).map((l) => <Tag key={l} className={`${cls} ${f.kind === 'area' ? 'min-h-24 font-mono text-xs' : ''}`} dir={l === 'en' ? 'ltr' : 'rtl'} placeholder={String(t(`comm.home.f.${l}`))} value={v[l] ?? ''} onChange={(e: any) => set(f.k, { ...v, [l]: e.target.value })} />)}
              </div>
            </Field>
          )
        }
        return (
          <Field key={f.k} label={label}>
            <input className={cls} type={f.kind === 'num' ? 'number' : 'text'} dir={f.kind === 'url' ? 'ltr' : undefined} placeholder={f.kind === 'url' ? 'https://' : undefined} min={f.kind === 'num' ? 1 : undefined} max={f.kind === 'num' ? 12 : undefined}
              value={value?.[f.k] ?? ''} onChange={(e) => set(f.k, f.kind === 'num' ? Number(e.target.value) : e.target.value)} />
          </Field>
        )
      })}
    </div>
  )
}

/** The fields of one block, from its type. */
export default function BlockForm({ type, config, onChange }: { type: string; config: any; onChange: (c: any) => void }) {
  return <Inputs fields={SCHEMA[type] ?? []} value={config ?? {}} onChange={onChange} />
}
