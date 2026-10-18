import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Hash, Languages, MapPin, Tags } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardTitle, Field, PageHeader, SaveDock, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { Switch } from './notifications/shared'

type TypeRow = { id: string; name_ar: string; name_en: string; hint_ar: string; hint_en: string; prefix: string; enabled: boolean; next_code: string; programs: number }
type Settings = { types: Record<string, Omit<TypeRow, 'id' | 'next_code' | 'programs'>>; modes: Record<'in_person' | 'online' | 'hybrid', { name_ar: string; name_en: string }>; code: { default_prefix: string; digits: number; separator: string } }
type Payload = { settings: Settings; types: TypeRow[]; default_next_code: string; untyped_programs: number }
const MODES = ['in_person', 'online', 'hybrid'] as const

/** Settings → Programme catalogue: the names and code prefixes of the programme types, the code format, and the names of the delivery modes. */
export default function ProgramCatalogSettings() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Payload }>('/admin/settings/program-catalog', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Settings | null>(null)
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  if (res.isLoading || !res.data) return <Spinner />
  const d = res.data.data
  const s = draft ?? d.settings
  const dirty = draft !== null
  const setType = (id: string, patch: Partial<Settings['types'][string]>) => setDraft({ ...s, types: { ...s.types, [id]: { ...s.types[id], ...patch } } })
  const setMode = (id: (typeof MODES)[number], patch: Partial<{ name_ar: string; name_en: string }>) => setDraft({ ...s, modes: { ...s.modes, [id]: { ...s.modes[id], ...patch } } })
  const setCode = (patch: Partial<Settings['code']>) => setDraft({ ...s, code: { ...s.code, ...patch } })
  const prefixes = Object.values(s.types).map((x) => x.prefix.toUpperCase())
  const duplicate = (p: string) => prefixes.filter((x) => x === p.toUpperCase()).length > 1 || p.toUpperCase() === s.code.default_prefix.toUpperCase()
  const badPrefix = (p: string) => !/^[A-Za-z]{2,5}$/.test(p)
  const invalid = Object.values(s.types).some((x) => badPrefix(x.prefix) || duplicate(x.prefix) || !x.name_ar.trim() || !x.name_en.trim()) || badPrefix(s.code.default_prefix) || MODES.some((m) => !s.modes[m].name_ar.trim() || !s.modes[m].name_en.trim())
  const sample = (prefix: string) => `${prefix.toUpperCase()}${s.code.separator}${'1'.padStart(s.code.digits, '0')}`

  const save = async () => {
    setSaving(true); setNotice(null)
    try {
      await api.put('/admin/settings/program-catalog', s)
      setDraft(null); await res.refetch()
      setNotice({ ok: true, text: String(t('mgmt.catalog.saved')) })
    } catch (e) { setNotice({ ok: false, text: errorMessage(e) }) } finally { setSaving(false) }
  }

  return (
    <>
      <PageHeader title={t('mgmt.settings.sections.programcatalog.title')} subtitle={t('mgmt.settings.sections.programcatalog.desc')} />
      {dirty && <SaveDock label={t('common.save')} loading={saving} onClick={save} disabled={invalid} />}
      {notice && <div className={clsx('mb-4 flex items-center gap-2 rounded-xl p-3 text-sm', notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{notice.ok ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}{notice.text}</div>}

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Card>
            <CardTitle subtitle={t('mgmt.catalog.typesHint')}>{t('mgmt.catalog.types')}</CardTitle>
            <div className="space-y-3">
              {d.types.map((row) => {
                const x = s.types[row.id]
                return (
                  <div key={row.id} className={clsx('rounded-2xl border p-4 transition', x.enabled ? 'border-navy-100 bg-white' : 'border-slate-200 bg-slate-50/70 opacity-80')}>
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                      <span className="grid size-10 place-items-center rounded-xl bg-navy-900 font-mono text-xs font-bold text-gold-300">{x.prefix.toUpperCase() || '—'}</span>
                      <div className="min-w-0 flex-1">
                        <div className="font-bold text-navy-900">{en ? x.name_en : x.name_ar} <span className="font-mono text-[11px] font-normal text-slate-400">{row.id}</span></div>
                        <div className="text-xs text-slate-500">{t('mgmt.catalog.nextCode')}: <span className="font-mono text-navy-900">{dirty ? sample(x.prefix) : row.next_code}</span> · {t('mgmt.catalog.programsN', { n: row.programs })}</div>
                      </div>
                      <Badge color={x.enabled ? 'green' : 'gray'}>{x.enabled ? t('mgmt.catalog.offered') : t('mgmt.catalog.hidden')}</Badge>
                      <Switch checked={x.enabled} label={t('mgmt.catalog.offered')} onChange={(v) => setType(row.id, { enabled: v })} small />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-5">
                      <Field label={t('mgmt.catalog.nameAr')} className="sm:col-span-2"><input className="input" value={x.name_ar} onChange={(e) => setType(row.id, { name_ar: e.target.value })} /></Field>
                      <Field label={t('mgmt.catalog.nameEn')} className="sm:col-span-2"><input className="input" dir="ltr" value={x.name_en} onChange={(e) => setType(row.id, { name_en: e.target.value })} /></Field>
                      <Field label={t('mgmt.catalog.prefix')} hint={badPrefix(x.prefix) ? t('mgmt.catalog.prefixRule') : duplicate(x.prefix) ? t('mgmt.catalog.prefixTaken') : undefined}>
                        <input className={clsx('input font-mono uppercase', (badPrefix(x.prefix) || duplicate(x.prefix)) && 'border-red-400')} dir="ltr" maxLength={5} value={x.prefix} onChange={(e) => setType(row.id, { prefix: e.target.value.replace(/[^A-Za-z]/g, '').toUpperCase() })} />
                      </Field>
                      <Field label={t('mgmt.catalog.hintAr')} className="sm:col-span-2"><input className="input" value={x.hint_ar} onChange={(e) => setType(row.id, { hint_ar: e.target.value })} /></Field>
                      <Field label={t('mgmt.catalog.hintEn')} className="sm:col-span-3"><input className="input" dir="ltr" value={x.hint_en} onChange={(e) => setType(row.id, { hint_en: e.target.value })} /></Field>
                    </div>
                  </div>
                )
              })}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardTitle subtitle={t('mgmt.catalog.codeHint')}><Hash className="me-1 inline size-4 text-gold-600" />{t('mgmt.catalog.code')}</CardTitle>
            <div className="grid grid-cols-2 gap-3">
              <Field label={t('mgmt.catalog.defaultPrefix')} hint={t('mgmt.catalog.defaultPrefixHint')} className="col-span-2"><input className={clsx('input font-mono uppercase', badPrefix(s.code.default_prefix) && 'border-red-400')} dir="ltr" maxLength={5} value={s.code.default_prefix} onChange={(e) => setCode({ default_prefix: e.target.value.replace(/[^A-Za-z]/g, '').toUpperCase() })} /></Field>
              <Field label={t('mgmt.catalog.digits')}><select className="input" value={s.code.digits} onChange={(e) => setCode({ digits: Number(e.target.value) })}>{[3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
              <Field label={t('mgmt.catalog.separator')}><select className="input" value={s.code.separator} onChange={(e) => setCode({ separator: e.target.value })}>{[['-', '-'], ['', t('mgmt.catalog.none')], ['/', '/'], ['.', '.']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
            </div>
            <div className="mt-3 rounded-xl bg-ivory p-3 text-sm">
              <div className="text-xs text-slate-500">{t('mgmt.catalog.preview')}</div>
              <div className="mt-1 flex flex-wrap gap-2 font-mono text-navy-900">{d.types.filter((r) => s.types[r.id].enabled).map((r) => <span key={r.id} className="rounded-lg bg-white px-2 py-1 shadow-sm">{sample(s.types[r.id].prefix)}</span>)}<span className="rounded-lg bg-white px-2 py-1 text-slate-500 shadow-sm">{sample(s.code.default_prefix)}</span></div>
              {d.untyped_programs > 0 && <p className="mt-2 text-xs text-slate-500">{t('mgmt.catalog.untyped', { n: d.untyped_programs })}</p>}
            </div>
          </Card>
          <Card>
            <CardTitle subtitle={t('mgmt.catalog.modesHint')}><MapPin className="me-1 inline size-4 text-gold-600" />{t('mgmt.catalog.modes')}</CardTitle>
            <div className="space-y-3">
              {MODES.map((m) => (
                <div key={m} className="rounded-xl border border-navy-100 p-3">
                  <div className="mb-2 flex items-center gap-2 text-xs text-slate-500"><Languages className="size-3.5" /><span className="font-mono">{m}</span></div>
                  <div className="grid grid-cols-2 gap-2">
                    <input className="input" value={s.modes[m].name_ar} onChange={(e) => setMode(m, { name_ar: e.target.value })} placeholder={String(t('mgmt.catalog.nameAr'))} />
                    <input className="input" dir="ltr" value={s.modes[m].name_en} onChange={(e) => setMode(m, { name_en: e.target.value })} placeholder={String(t('mgmt.catalog.nameEn'))} />
                  </div>
                </div>
              ))}
            </div>
            <p className="mt-3 text-xs text-slate-400"><Tags className="me-1 inline size-3" />{t('mgmt.catalog.modesNote')}</p>
          </Card>
        </div>
      </div>
    </>
  )
}
