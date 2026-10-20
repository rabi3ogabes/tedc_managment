import { Copy, Download, Link2, Mail, Printer, QrCode, RefreshCw, Send, Users } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Field, Modal, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { API_URL, api, errorMessage, sessionStore } from '@/lib/api'
import { toast } from '@/lib/toast'

type Dist = { id: string; channel: string; slug: string; link: string | null; duplicate_rule: 'open' | 'device' | 'email_code'; max_responses: number | null; is_active: boolean; qr_version: number; submissions: number; full: boolean }
type Invitation = { id: string; email: string; name?: string | null; send_status: 'pending' | 'sent' | 'failed' | 'manual'; send_error?: string | null; sent_at?: string | null; reminded_at?: string | null; opened_at?: string | null; responded_at?: string | null; expires_at?: string | null }
type Data = { public: Dist | null; invitations: Invitation[]; invitation_stats: { total: number; sent: number; manual: number; failed: number; opened: number; responded: number }; mail_ready: boolean; web_url: string }

/** How the survey reaches people beyond the signed-in audience: the public link with its QR, and personal invitations. */
export default function DistributionTab({ surveyId, title, isOpen, canManage }: { surveyId: string; title: string; isOpen: boolean; canManage: boolean }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Data }>(`/admin/surveys/${surveyId}/distributions`, undefined, { staleTime: 0 })
  const inv = ['/admin/surveys']
  const createPublic = useSend<Record<string, unknown>, { data: Dist }>('post', `/admin/surveys/${surveyId}/distributions/public`, inv)
  const update = useSend<{ id: string } & Record<string, unknown>, { data: Dist }>('put', (b) => `/admin/surveys/${surveyId}/distributions/${b.id}`, inv)
  const regenerate = useSend<{ id: string }, { data: Dist }>('post', (b) => `/admin/surveys/${surveyId}/distributions/${b.id}/regenerate`, inv)
  const invite = useSend<{ people: { email: string; name?: string }[] }, { data: { created: number; sent: number; manual: number; failed: number; links: { email: string; link: string }[] } }>('post', `/admin/surveys/${surveyId}/invitations`, inv)
  const remind = useSend<unknown, { data: { pending: number; sent: number; mail_ready: boolean } }>('post', `/admin/surveys/${surveyId}/invitations/remind`, inv)
  const [emails, setEmails] = useState('')
  const [links, setLinks] = useState<{ email: string; link: string }[]>([])
  const [card, setCard] = useState(false)
  if (res.isLoading || !res.data) return <Spinner />
  const d = res.data.data
  const pub = d.public
  const act = async (fn: () => Promise<unknown>, msg?: string) => { try { await fn(); if (msg) toast(msg); await res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const copy = async (text: string) => { try { await navigator.clipboard.writeText(text); toast(String(t('ss.dist.copied'))) } catch { window.prompt(String(t('ss.dist.copyManual')), text) } }
  const qrUrl = (format: 'svg' | 'png') => `${API_URL}/admin/surveys/${surveyId}/distributions/${pub?.id}/qr.${format}`
  const download = async (format: 'svg' | 'png') => {
    try {
      const r = await api.get(qrUrl(format), { responseType: 'blob' })
      const url = URL.createObjectURL(r.data as Blob); const a = document.createElement('a'); a.href = url; a.download = `survey-${pub?.slug}.${format}`; a.click(); URL.revokeObjectURL(url)
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const sendInvites = async () => {
    const people = emails.split(/[\n,;،]+/).map((x) => x.trim()).filter(Boolean).map((x) => { const m = x.match(/^(.*?)<([^>]+)>$/); return m ? { email: m[2].trim(), name: m[1].trim() || undefined } : { email: x } })
    if (!people.length) return
    try {
      const { data } = await invite.mutateAsync({ people })
      toast(String(t('ss.dist.invited', { n: data.created, sent: data.sent, manual: data.manual + data.failed })))
      setLinks(data.links); setEmails(''); await res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const statusTone: Record<Invitation['send_status'], 'gray' | 'green' | 'red' | 'amber'> = { pending: 'gray', sent: 'green', failed: 'red', manual: 'amber' }
  void sessionStore

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card>
        <CardTitle subtitle={t('ss.dist.publicHint')}>{t('ss.dist.public')}</CardTitle>
        {!isOpen && <p className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">{t('ss.dist.notOpen')}</p>}
        {!pub ? (
          canManage ? <Button variant="gold" icon={<Link2 className="size-4" />} loading={createPublic.isPending} onClick={() => act(() => createPublic.mutateAsync({ duplicate_rule: 'device' }), String(t('ss.dist.created')))}>{t('ss.dist.create')}</Button> : <p className="text-sm text-slate-500">{t('ss.dist.none')}</p>
        ) : (
          <div className="grid gap-4 sm:grid-cols-[auto_1fr]">
            <div className="flex flex-col items-center gap-2">
              <div className={`rounded-2xl border border-slate-200 bg-white p-3 ${pub.is_active ? '' : 'opacity-40 grayscale'}`}><QRCodeSVG value={pub.link ?? ''} size={168} level="M" includeMargin /></div>
              <div className="flex gap-1"><Button size="sm" variant="outline" icon={<Download className="size-3.5" />} onClick={() => download('svg')}>SVG</Button><Button size="sm" variant="outline" icon={<Download className="size-3.5" />} onClick={() => download('png')}>PNG</Button><Button size="sm" variant="outline" icon={<Printer className="size-3.5" />} onClick={() => setCard(true)}>{t('ss.dist.card')}</Button></div>
            </div>
            <div className="min-w-0 space-y-3">
              <div className="flex items-center gap-2"><input className="input min-w-0 flex-1 font-mono text-xs" dir="ltr" readOnly value={pub.link ?? ''} onFocus={(e) => e.currentTarget.select()} /><Button size="sm" variant="outline" icon={<Copy className="size-3.5" />} onClick={() => copy(pub.link ?? '')}>{t('ss.dist.copy')}</Button></div>
              <div className="flex flex-wrap gap-1.5 text-xs"><Badge color={pub.is_active ? 'green' : 'gray'}>{pub.is_active ? t('ss.dist.active') : t('ss.dist.inactive')}</Badge><Badge color="gray">{t('ss.dist.submissions', { n: pub.submissions })}</Badge>{pub.full && <Badge color="amber">{t('ss.dist.full')}</Badge>}<Badge color="gray">QR v{pub.qr_version}</Badge></div>
              <Field label={t('ss.dist.rule')} hint={t(`ss.dist.rules.${pub.duplicate_rule}Hint`)}>
                <select className="input" value={pub.duplicate_rule} disabled={!canManage} onChange={(e) => act(() => update.mutateAsync({ id: pub.id, duplicate_rule: e.target.value }))}>{(['device', 'email_code', 'open'] as const).map((r) => <option key={r} value={r}>{t(`ss.dist.rules.${r}`)}</option>)}</select>
              </Field>
              {pub.duplicate_rule === 'email_code' && !d.mail_ready && <p className="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700">{t('ss.dist.mailOff')}</p>}
              <div className="grid grid-cols-2 gap-3">
                <Field label={t('ss.dist.max')}><input type="number" min={1} className="input" defaultValue={pub.max_responses ?? ''} disabled={!canManage} onBlur={(e) => { const v = e.target.value ? Number(e.target.value) : null; if (v !== pub.max_responses) act(() => update.mutateAsync({ id: pub.id, max_responses: v })) }} /></Field>
                <Field label={t('ss.dist.status')}><label className="flex h-10 items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={pub.is_active} disabled={!canManage} onChange={(e) => act(() => update.mutateAsync({ id: pub.id, is_active: e.target.checked }))} />{t('ss.dist.active')}</label></Field>
              </div>
              {canManage && <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} onClick={() => { if (window.confirm(String(t('ss.dist.regenerateConfirm')))) act(() => regenerate.mutateAsync({ id: pub.id }), String(t('ss.dist.regenerated'))) }}>{t('ss.dist.regenerate')}</Button>}
            </div>
          </div>
        )}
      </Card>

      <Card>
        <CardTitle subtitle={d.mail_ready ? t('ss.dist.inviteHint') : t('ss.dist.inviteManualHint')}>{t('ss.dist.invitations')}</CardTitle>
        {canManage && (
          <div className="space-y-2">
            <textarea className="input min-h-24 font-mono text-xs" dir="ltr" placeholder={'name <a@school.qa>\nb@school.qa'} value={emails} onChange={(e) => setEmails(e.target.value)} />
            <div className="flex flex-wrap gap-2"><Button variant="gold" icon={<Send className="size-4" />} loading={invite.isPending} disabled={!emails.trim() || !isOpen} onClick={sendInvites}>{t('ss.dist.send')}</Button>
              <Button variant="outline" icon={<Mail className="size-4" />} loading={remind.isPending} disabled={!d.invitation_stats.sent} onClick={() => act(async () => { const { data } = await remind.mutateAsync({}); toast(String(t('ss.dist.reminded', { n: data.sent, p: data.pending }))) })}>{t('ss.dist.remind')}</Button></div>
          </div>
        )}
        {!!links.length && (
          <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs">
            <div className="mb-1 font-semibold text-amber-900">{t('ss.dist.manualLinks')}</div>
            <ul className="space-y-1">{links.map((l) => <li key={l.email} className="flex items-center gap-2"><span className="w-40 truncate" dir="ltr">{l.email}</span><input className="input flex-1 py-1 font-mono text-[11px]" dir="ltr" readOnly value={l.link} /><button type="button" className="text-navy-700 hover:text-gold-700" onClick={() => copy(l.link)}><Copy className="size-3.5" /></button></li>)}</ul>
          </div>
        )}
        <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
          {(['total', 'opened', 'responded'] as const).map((k) => <div key={k} className="rounded-xl bg-slate-50 px-2 py-2"><div className="text-lg font-bold text-navy-900">{d.invitation_stats[k]}</div>{t(`ss.dist.stats.${k}`)}</div>)}
        </div>
        {!!d.invitations.length && (
          <div className="mt-3 max-h-64 overflow-auto rounded-xl border border-slate-200">
            <table className="w-full text-xs"><thead className="sticky top-0 bg-slate-50 text-slate-500"><tr><th className="px-2 py-1.5 text-start">{t('ss.dist.email')}</th><th className="px-2 py-1.5 text-start">{t('ss.dist.status')}</th><th className="px-2 py-1.5 text-start">{t('ss.dist.stats.opened')}</th><th className="px-2 py-1.5 text-start">{t('ss.dist.stats.responded')}</th></tr></thead>
              <tbody className="divide-y divide-slate-100">{d.invitations.map((i) => <tr key={i.id}><td className="px-2 py-1.5" dir="ltr">{i.email}{i.name ? <span className="text-slate-400"> · {i.name}</span> : null}</td><td className="px-2 py-1.5"><Badge color={statusTone[i.send_status]}>{t(`ss.dist.sendStatus.${i.send_status}`)}</Badge></td><td className="px-2 py-1.5">{i.opened_at ? '✓' : '—'}</td><td className="px-2 py-1.5">{i.responded_at ? '✓' : '—'}</td></tr>)}</tbody></table>
          </div>
        )}
      </Card>

      <Modal open={card && !!pub} onClose={() => setCard(false)} title={t('ss.dist.card')} wide>
        <div className="mx-auto max-w-md rounded-3xl border-2 border-gold-300 bg-gradient-to-b from-white to-gold-50 p-8 text-center" id="survey-qr-card">
          <div className="text-xs font-bold uppercase tracking-[0.2em] text-gold-700">{t('ss.dist.cardEyebrow')}</div>
          <h3 className="mt-2 font-display text-2xl font-bold text-navy-900">{title}</h3>
          <div className="mx-auto my-6 inline-block rounded-2xl bg-white p-4 shadow-sm"><QRCodeSVG value={pub?.link ?? ''} size={220} level="M" /></div>
          <p className="text-sm text-slate-600">{t('ss.dist.cardHint')}</p>
          <p className="mt-2 font-mono text-xs text-slate-500" dir="ltr">{pub?.link}</p>
          <div className="mt-6 flex items-center justify-center gap-2 text-xs text-slate-400"><QrCode className="size-3.5" />{en ? 'Shaheen – Training Portal Project' : 'شاهين - مشروع بوابة التدريب'}<Users className="size-3.5" /></div>
        </div>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setCard(false)}>{t('common.close')}</Button><Button variant="gold" icon={<Printer className="size-4" />} onClick={() => { const el = document.getElementById('survey-qr-card'); if (!el) return; const w = window.open('', '_blank'); if (!w) return; w.document.write(`<html dir="${en ? 'ltr' : 'rtl'}"><head><title>${title}</title><style>body{font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0}</style></head><body>${el.outerHTML}</body></html>`); w.document.close(); w.focus(); w.print() }}>{t('ss.dist.print')}</Button></div>
      </Modal>
    </div>
  )
}
