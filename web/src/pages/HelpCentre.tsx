/* eslint-disable @typescript-eslint/no-explicit-any */
import { BookOpen, Download, LifeBuoy, Mail, Phone, RefreshCw, Search, Ticket } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import ArticleView from '@/components/help/ArticleView'
import { useLang, type HelpArticle } from '@/components/help/helpApi'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** Help centre: articles for the person's roles, manuals as PDF, support channels and service times, tours and the person's tickets. */
export default function HelpCentre() {
  const { t } = useTranslation()
  const { pick, lang } = useLang()
  const [q, setQ] = useState('')
  const [slug, setSlug] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const query = q.trim().length > 1 ? { q: q.trim() } : undefined
  const list = useGet<{ data: HelpArticle[]; support: any }>('/me/help/articles', query, { staleTime: 30_000 })
  const manuals = useGet<{ data: any[] }>('/me/help/manuals', undefined, { staleTime: 120_000 })
  const tickets = useGet<{ data: any[] }>('/me/tickets', undefined, { staleTime: 15_000 })

  const grouped = useMemo(() => {
    const g: Record<string, HelpArticle[]> = {}
    for (const a of list.data?.data ?? []) (g[a.module] ??= []).push(a)
    return g
  }, [list.data])

  const pdf = async (role: string, l: 'ar' | 'en') => {
    setBusy(`${role}-${l}`)
    try { await downloadFile(`/me/help/manuals/${role}/pdf?lang=${l}`, `manual-${role}-${l}.pdf`); toast(String(t('hlp.downloaded'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const replay = async () => {
    try { await api.delete('/me/tours'); window.dispatchEvent(new Event('tedc:tours-reset')); toast(String(t('hlp.replayed'))) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const support = list.data?.support

  return (
    <>
      <PageHeader title={t('hlp.title')} subtitle={t('hlp.subtitle')} actions={<Button variant="gold" icon={<LifeBuoy className="size-4" />} onClick={() => window.dispatchEvent(new Event('tedc:report-problem'))}>{t('hlp.report')}</Button>} />
      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-5">
          {slug ? (
            <Card className="space-y-4">
              <Button size="sm" variant="outline" onClick={() => setSlug(null)}>{t('hlp.back')}</Button>
              <ArticleView slug={slug} />
            </Card>
          ) : (
            <>
              <label className="relative block">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('hlp.search'))} aria-label={String(t('hlp.search'))} />
              </label>
              {list.isLoading ? <Spinner /> : Object.keys(grouped).length === 0 ? <Empty text={String(q ? t('hlp.noResults') : t('hlp.none'))} /> : Object.entries(grouped).map(([module, items]) => (
                <section key={module} className="space-y-2">
                  <h2 className="flex items-center gap-2 font-bold text-navy-900"><BookOpen className="size-5 text-gold-600" />{t(`hlp.modules.${module}`, { defaultValue: module })}</h2>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {items.map((a) => (
                      <button key={a.id} type="button" onClick={() => setSlug(a.slug)} className="rounded-xl border border-navy-100 bg-white p-3 text-start transition hover:border-gold-400">
                        <b className="block text-navy-900">{pick(a, 'title')}</b>
                        <span className="mt-1 block text-xs text-slate-500">{pick(a, 'excerpt')}</span>
                        {a.has_video && <Badge color="gold" className="mt-2">{t('hlp.video')}</Badge>}
                      </button>
                    ))}
                  </div>
                </section>
              ))}
            </>
          )}
        </div>
        <aside className="space-y-5">
          <Card className="space-y-3">
            <h2 className="font-bold text-navy-900">{t('hlp.manuals')}</h2>
            <p className="text-xs text-slate-500">{t('hlp.manualsHint')}</p>
            {manuals.isLoading ? <Spinner /> : (manuals.data?.data ?? []).map((m) => (
              <div key={m.role} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-ivory p-3">
                <div><b className="text-sm text-navy-900">{lang === 'en' ? m.name_en : m.name_ar}</b><span className="block text-xs text-slate-500">{t('hlp.articlesCount', { n: m.articles })}</span></div>
                <div className="flex gap-1.5">
                  {(['ar', 'en'] as const).map((l) => <Button key={l} size="sm" variant="outline" loading={busy === `${m.role}-${l}`} icon={<Download className="size-3.5" />} onClick={() => pdf(m.role, l)}>{l === 'ar' ? t('hlp.pdfAr') : t('hlp.pdfEn')}</Button>)}
                </div>
              </div>
            ))}
          </Card>
          <Card className="space-y-3">
            <h2 className="font-bold text-navy-900">{t('hlp.support')}</h2>
            {(support?.channels ?? []).map((c: any) => (
              <div key={c.key} className="flex items-start gap-3 text-sm">
                {c.key === 'phone' ? <Phone className="mt-0.5 size-4 text-gold-600" /> : c.key === 'email' ? <Mail className="mt-0.5 size-4 text-gold-600" /> : <LifeBuoy className="mt-0.5 size-4 text-gold-600" />}
                <div>
                  <b className="text-navy-900">{t(`hlp.channels.${c.key}`)}</b>
                  <div dir="ltr" className="text-start">{c.value ? (c.key === 'saaed' ? <a className="underline" href={c.value} target="_blank" rel="noopener noreferrer">{c.value}</a> : c.value) : <span className="text-slate-400" dir="auto">{t('hlp.notSet')}</span>}</div>
                  <div className="text-xs text-slate-500">{lang === 'en' ? c.hours_en : c.hours_ar}</div>
                </div>
              </div>
            ))}
            <h3 className="pt-2 text-sm font-bold text-navy-900">{t('hlp.sla')}</h3>
            <table className="w-full text-xs"><thead><tr className="text-slate-500"><th className="text-start">{t('hlp.priority')}</th><th className="text-start">{t('hlp.response')}</th><th className="text-start">{t('hlp.resolution')}</th></tr></thead>
              <tbody>{(support?.sla ?? []).map((s: any) => <tr key={s.priority} className="border-t border-navy-100"><td className="py-1 font-bold">{s.priority}</td><td>{lang === 'en' ? s.response_en : s.response_ar}</td><td>{lang === 'en' ? s.resolution_en : s.resolution_ar}</td></tr>)}</tbody></table>
          </Card>
          <Card className="space-y-2">
            <h2 className="flex items-center gap-2 font-bold text-navy-900"><Ticket className="size-5 text-gold-600" />{t('hlp.myTickets')}</h2>
            {tickets.isLoading ? <Spinner /> : (tickets.data?.data.length ?? 0) === 0 ? <p className="text-sm text-slate-500">{t('hlp.noTickets')}</p> : tickets.data!.data.slice(0, 6).map((k) => (
              <div key={k.id} className="rounded-xl bg-ivory p-3 text-sm">
                <b className="block text-navy-900">{k.subject}</b>
                <span className="text-xs text-slate-500" dir="ltr">{k.ticket_no ?? '—'} · {fmt.date(k.created_at)}</span>
                <div className="mt-1"><Badge color={k.status === 'sent' ? 'green' : 'navy'}>{k.saaed_status ?? k.status}</Badge></div>
              </div>
            ))}
          </Card>
          <Card className="space-y-2">
            <h2 className="font-bold text-navy-900">{t('hlp.tours')}</h2>
            <p className="text-xs text-slate-500">{t('hlp.toursHint')}</p>
            <Button size="sm" variant="outline" icon={<RefreshCw className="size-4" />} onClick={replay}>{t('hlp.replay')}</Button>
          </Card>
        </aside>
      </div>
    </>
  )
}
