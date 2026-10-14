import { ExternalLink, Library, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Driver = 'link' | 'fake' | 'figshare'
interface LibraryConfig { enabled: boolean; driver: Driver; name_ar: string; name_en: string; search_url: string; api_url: string; api_key: string; figshare_institution?: number; portal_host?: string }
type Libraries = Record<'maktabati' | 'qnl', LibraryConfig>
interface LibraryRecord { library: string; external_id: string | null; title: string; authors: string[]; year: number | null; type: string; url: string; deep_link: boolean; doi?: string | null }
interface SearchAnswer { data: LibraryRecord[]; meta?: { problems: { library: string; message: string }[] } }

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const LIBRARIES = ['maktabati', 'qnl'] as const

/**
 * Settings → Standards and integrations → External libraries. Qatar National Library's Manara repository is searched through the public Figshare
 * API its own site documents; Maktabati and the QNL catalogue document no public interface, so they stay a link to the library's own search page.
 * An item added to the library keeps its source link and its rights.
 */
export default function ExternalLibraries() {
  const { t, i18n } = useTranslation()
  const res = useGet<{ data: Libraries }>('/admin/settings/external-libraries', undefined, { staleTime: 0 })
  const [cfg, setCfg] = useState<Libraries | null>(null)
  const [q, setQ] = useState('')
  const [searching, setSearching] = useState(false)
  const [answer, setAnswer] = useState<SearchAnswer | null>(null)
  const [adding, setAdding] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  useEffect(() => { if (res.data) setCfg(res.data.data) }, [res.data])
  if (!cfg) return <Spinner />

  const set = (lib: keyof Libraries, patch: Partial<LibraryConfig>) => setCfg({ ...cfg, [lib]: { ...cfg[lib], ...patch } })
  const save = async () => {
    setSaving(true)
    try { await api.put('/admin/settings/external-libraries', cfg); toast(String(t('content.standards.saved'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setSaving(false) }
  }
  const search = async () => {
    setSearching(true)
    try { setAnswer((await api.get<SearchAnswer>('/admin/external-libraries/search', { params: { q } })).data) } catch (e) { toast(errorMessage(e), 'error') } finally { setSearching(false) }
  }
  const add = async (r: LibraryRecord) => {
    setAdding(`${r.library}:${r.external_id}`)
    try { await api.post('/admin/external-libraries/import', r); toast(String(t('min.libraries.imported'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setAdding(null) }
  }
  const nameOf = (lib: string) => { const c = cfg[lib as keyof Libraries]; return c ? c[lang === 'en' ? 'name_en' : 'name_ar'] : lib }

  return (
    <div className="space-y-6">
      <div className="grid gap-5 lg:grid-cols-2">
        {LIBRARIES.map((lib) => {
          const c = cfg[lib]
          const drivers: Driver[] = lib === 'qnl' ? ['figshare', 'link', 'fake'] : ['link', 'fake']
          return (
            <Card key={lib} className="space-y-4">
              <div className="flex items-start justify-between gap-3">
                <div><h3 className="text-base font-bold text-navy-900">{c[lang === 'en' ? 'name_en' : 'name_ar']}</h3><p className="mt-1 text-xs text-slate-500">{t(`min.libraries.how.${lib}`)}</p></div>
                <label className="flex shrink-0 items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={c.enabled} onChange={(e) => set(lib, { enabled: e.target.checked })} />{t('content.standards.enabled')}</label>
              </div>
              {c.enabled && (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={t('min.libraries.driver')}>
                    <select className={input} value={c.driver} onChange={(e) => set(lib, { driver: e.target.value as Driver })}>
                      {drivers.map((d) => <option key={d} value={d}>{t(`min.libraries.drivers.${d}`)}</option>)}
                    </select>
                  </Field>
                  <Field label={t('content.libs.searchUrl')} hint={c.driver === 'figshare' ? t('min.libraries.catalogueLink') : undefined}>
                    <input dir="ltr" className={input} value={c.search_url} placeholder="https://" onChange={(e) => set(lib, { search_url: e.target.value })} />
                  </Field>
                  {lib === 'qnl' && c.driver === 'figshare' && (
                    <>
                      <Field label={t('min.libraries.institution')} hint={t('min.libraries.institutionHint')}>
                        <input dir="ltr" type="number" min={0} className={input} value={c.figshare_institution ?? 0} onChange={(e) => set(lib, { figshare_institution: Number(e.target.value) })} />
                      </Field>
                      <Field label={t('min.libraries.portal')} hint={t('min.libraries.portalHint')}>
                        <input dir="ltr" className={input} value={c.portal_host ?? ''} onChange={(e) => set(lib, { portal_host: e.target.value })} />
                      </Field>
                    </>
                  )}
                </div>
              )}
              {c.enabled && c.driver === 'figshare' && <p className="rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-800">{t('min.libraries.figshareNote')}</p>}
            </Card>
          )
        })}
      </div>
      <div><Button variant="gold" loading={saving} onClick={() => void save()}>{t('content.standards.save')}</Button></div>

      <Card>
        <CardTitle subtitle={t('min.libraries.searchHint')}>{t('content.libs.title')}</CardTitle>
        <form className="mb-5 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (q.trim().length >= 2) void search() }}>
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input type="search" className={`${input} ps-9`} placeholder={t('content.libs.search')} aria-label={t('content.libs.search')} value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          <Button type="submit" variant="outline" loading={searching} disabled={q.trim().length < 2}>{t('min.libraries.search')}</Button>
        </form>
        {answer?.meta?.problems.map((p) => <p key={p.library + p.message} className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status"><b>{nameOf(p.library)}:</b> {p.message}</p>)}
        {answer && answer.data.length === 0 && <Empty text={t('min.libraries.none')} icon={<Library className="size-6" />} />}
        <ul className="divide-y divide-navy-100/70">
          {answer?.data.map((r, i) => (
            <li key={`${r.library}-${r.external_id ?? i}`} className="flex items-start gap-4 py-4 first:pt-0 last:pb-0">
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-navy-900" dir="auto">{r.title}</p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-400">
                  <Badge>{nameOf(r.library)}</Badge>{!r.deep_link && r.type !== 'link' && <span>{t(`min.libraries.types.${r.type}`, { defaultValue: r.type })}</span>}{r.year && <span>{r.year}</span>}{r.doi && <span dir="ltr">DOI {r.doi}</span>}
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-end gap-2">
                {r.deep_link
                  ? <Button size="sm" variant="outline" icon={<ExternalLink className="size-4" aria-hidden="true" />} onClick={() => window.open(r.url, '_blank', 'noopener')}>{t('content.libs.deepLink')}</Button>
                  : <>
                    <Button size="sm" variant="gold" loading={adding === `${r.library}:${r.external_id}`} onClick={() => void add(r)}>{t('content.libs.import')}</Button>
                    <a href={r.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-navy-800"><ExternalLink className="size-3.5" aria-hidden="true" />{t('min.libraries.source')}</a>
                  </>}
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}
