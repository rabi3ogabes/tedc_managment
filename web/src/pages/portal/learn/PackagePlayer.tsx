/* eslint-disable @typescript-eslint/no-explicit-any */
import { Scorm12API, Scorm2004API } from 'scorm-again'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Spinner } from '@/components/ui'
import { API_URL, api, errorMessage, sessionStore } from '@/lib/api'
import { toast } from '@/lib/toast'
import type { LessonDetail, ProgressResult } from './types'

const hms = (s: number) => `${String(Math.floor(s / 3600)).padStart(4, '0')}:${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}:${String(Math.floor(s % 60)).padStart(2, '0')}`
const iso = (s: number) => `PT${Math.floor(s / 3600)}H${Math.floor((s % 3600) / 60)}M${Math.floor(s % 60)}S`

/** What the server needs from a scorm-again commit: the nested CMI tree, built from the commit's own summary fields. */
function toCmi(co: any, is2004: boolean) {
  const rd = co.runtimeData ?? {}
  const pick = (k: string) => rd[`cmi.${k}`] ?? k.split('.').reduce((o: any, p) => o?.[p], rd.cmi) ?? k.split('.').reduce((o: any, p) => o?.[p], rd)
  const s = co.score ?? {}
  if (is2004) return { completion_status: co.completionStatus, success_status: co.successStatus, score: { raw: s.raw, min: s.min, max: s.max, scaled: s.scaled }, total_time: iso(co.totalTimeSeconds ?? 0), suspend_data: pick('suspend_data'), location: pick('location'), progress_measure: pick('progress_measure') }
  const status = co.successStatus === 'passed' || co.successStatus === 'failed' ? co.successStatus : co.completionStatus === 'completed' ? 'completed' : co.completionStatus === 'incomplete' ? 'incomplete' : 'not attempted'
  return { core: { lesson_status: status, lesson_location: pick('core.lesson_location'), total_time: hms(co.totalTimeSeconds ?? 0), score: { raw: s.raw, min: s.min, max: s.max } }, suspend_data: pick('suspend_data') }
}

/** Plays a content package inside a lesson: SCORM through the scorm-again runtime, H5P with h5p-standalone, cmi5 and HTML5 in a frame, LTI tools through the OIDC launch. */
export default function PackagePlayer({ lesson, onProgress }: { lesson: LessonDetail & { package?: any; lti_tool_id?: string | null }; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  if (lesson.type === 'lti') return <LtiFrame lesson={lesson} />
  const std = lesson.package?.standard
  if (!std) return <div className="rounded-2xl bg-ivory p-6 text-sm text-slate-500">{t('content.noPackage')}</div>
  if (std === 'scorm12' || std === 'scorm2004') return <ScormFrame lesson={lesson} is2004={std === 'scorm2004'} onProgress={onProgress} />
  if (std === 'h5p') return <H5pFrame lesson={lesson} onProgress={onProgress} />
  return <GenericFrame lesson={lesson} cmi5={std === 'cmi5'} onProgress={onProgress} />
}

function useStatusPoll(lessonId: string, onProgress: (r: ProgressResult) => void, on: boolean) {
  useEffect(() => {
    if (!on) return
    const id = window.setInterval(async () => {
      try { const { data } = await api.get(`/me/lessons/${lessonId}`); const p = data.data.progress; onProgress({ percent: p.percent, status: p.status, completed: p.status === 'completed' }) } catch { /* try again */ }
    }, 15000)
    return () => window.clearInterval(id)
  }, [lessonId, onProgress, on])
}

function ScormFrame({ lesson, is2004, onProgress }: { lesson: any; is2004: boolean; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  const [url, setUrl] = useState<string | null>(null)
  const apiRef = useRef<any>(null)
  useEffect(() => {
    let cancelled = false
    api.post(`/me/packages/${lesson.id}/scorm/start`).then(({ data }) => {
      if (cancelled) return
      const d = data.data
      const token = sessionStore.get()?.access_token ? `Bearer ${sessionStore.get()?.access_token}` : undefined
      const settings: any = {
        autocommit: true, autocommitSeconds: 8, lmsCommitUrl: `${API_URL}/me/scorm/${d.attempt_id}/commit`, xhrHeaders: token ? { Authorization: token } : {}, commitRequestDataType: 'application/json;charset=UTF-8', useAsynchronousCommits: true,
        requestHandler: (co: any) => ({ cmi: toCmi(co, is2004) }),
        responseHandler: async (r: Response) => { const ok = r.ok; if (ok) { r.clone().json().then((j) => onProgress({ percent: j.data?.completion_status === 'completed' ? 100 : 0, status: j.data?.completion_status === 'completed' ? 'completed' : 'in_progress', completed: j.data?.completion_status === 'completed' })).catch(() => undefined) } return { result: ok ? 'true' : 'false', errorCode: ok ? 0 : 101 } },
      }
      const scorm = is2004 ? new Scorm2004API(settings) : new Scorm12API(settings)
      if (d.suspend_data || d.location) scorm.loadFromJSON(is2004 ? { suspend_data: d.suspend_data, location: d.location } : { suspend_data: d.suspend_data, core: { lesson_location: d.location } }, 'cmi')
      apiRef.current = scorm
      ;(window as any)[is2004 ? 'API_1484_11' : 'API'] = scorm
      setUrl(d.launch_url)
    }).catch((e) => toast(errorMessage(e), 'error'))
    return () => { cancelled = true; try { apiRef.current?.commit?.('') } catch { /* closing */ } delete (window as any)[is2004 ? 'API_1484_11' : 'API'] }
  }, [lesson.id, is2004, onProgress])
  return url ? <iframe title={lesson.title} src={url} className="h-[75vh] w-full rounded-2xl border border-navy-100 bg-white" allow="fullscreen" /> : <Spinner className="min-h-64" aria-label={t('common.loading')} />
}

function GenericFrame({ lesson, cmi5, onProgress }: { lesson: any; cmi5: boolean; onProgress: (r: ProgressResult) => void }) {
  const [url, setUrl] = useState<string | null>(null)
  useEffect(() => {
    api.post(cmi5 ? `/me/packages/${lesson.id}/cmi5/launch` : `/me/packages/${lesson.id}/launch`).then(({ data }) => setUrl(data.data.launch_url)).catch((e) => toast(errorMessage(e), 'error'))
  }, [lesson.id, cmi5])
  // HTML5 units report completion with postMessage({type: 'tedc:complete', score}).
  useEffect(() => {
    if (cmi5) return
    const h = (e: MessageEvent) => {
      if (e.origin !== window.location.origin || e.data?.type !== 'tedc:complete') return
      api.post(`/me/packages/${lesson.id}/complete`, { completed: true, score: typeof e.data.score === 'number' ? e.data.score : undefined }).then(({ data }) => onProgress({ percent: data.data.percent, status: data.data.status, completed: true })).catch(() => undefined)
    }
    window.addEventListener('message', h)
    return () => window.removeEventListener('message', h)
  }, [lesson.id, cmi5, onProgress])
  useStatusPoll(lesson.id, onProgress, cmi5)
  return url ? <iframe title={lesson.title} src={url} className="h-[75vh] w-full rounded-2xl border border-navy-100 bg-white" allow="fullscreen" /> : <Spinner className="min-h-64" />
}

function H5pFrame({ lesson, onProgress }: { lesson: any; onProgress: (r: ProgressResult) => void }) {
  const box = useRef<HTMLDivElement>(null)
  const queue = useRef<any[]>([])
  const flush = useCallback(async () => {
    if (!queue.current.length) return
    const statements = queue.current.splice(0, 50)
    try { await api.post(`/me/packages/${lesson.id}/xapi`, { statements }) } catch { /* dropped: the lesson result is reported separately */ }
  }, [lesson.id])
  useEffect(() => {
    let alive = true
    const timer = window.setInterval(() => void flush(), 8000)
    api.post(`/me/packages/${lesson.id}/launch`).then(async ({ data }) => {
      const { H5P } = await import('h5p-standalone')
      if (!alive || !box.current) return
      box.current.innerHTML = ''
      await new H5P(box.current, { h5pJsonPath: String(data.data.base_url).replace(/\/$/, ''), frameJs: '/h5p/frame.bundle.js', frameCss: '/h5p/styles/h5p.css', frame: true, copyright: false, export: false, icon: false, embed: false })
      const ext = (window as any).H5P?.externalDispatcher
      ext?.on('xAPI', (ev: any) => {
        const s = ev.data?.statement
        if (!s?.verb?.id) return
        queue.current.push({ verb: s.verb, object: s.object, result: s.result })
        if (/\/(completed|passed|answered)$/.test(s.verb.id) && s.result?.completion) {
          const scaled = s.result?.score?.scaled
          void api.post(`/me/packages/${lesson.id}/complete`, { completed: true, score: typeof scaled === 'number' ? Math.round(scaled * 100) : undefined }).then(({ data: d }) => onProgress({ percent: d.data.percent, status: d.data.status, completed: true })).catch(() => undefined)
          void flush()
        }
      })
    }).catch((e) => toast(errorMessage(e), 'error'))
    return () => { alive = false; window.clearInterval(timer); void flush() }
  }, [lesson.id, flush, onProgress])
  return <div ref={box} className="min-h-64 rounded-2xl bg-white p-2" />
}

/** Opens an LTI tool: the platform answers with a form (OIDC login for 1.3, a signed launch for 1.1) that is posted into the frame. */
function LtiFrame({ lesson }: { lesson: any }) {
  const form = useRef<HTMLFormElement>(null)
  const [data, setData] = useState<{ action: string; method: string; fields: Record<string, string> } | null>(null)
  useEffect(() => { api.post(`/me/lti/${lesson.id}/launch`).then(({ data: d }) => setData(d.data)).catch((e) => toast(errorMessage(e), 'error')) }, [lesson.id])
  useEffect(() => { if (data) form.current?.submit() }, [data])
  return (
    <>
      {data && <form ref={form} method="POST" action={data.action} target={`lti-${lesson.id}`} className="hidden">{Object.entries(data.fields).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}</form>}
      <iframe name={`lti-${lesson.id}`} title={lesson.title} className="h-[75vh] w-full rounded-2xl border border-navy-100 bg-white" allow="fullscreen; microphone; camera" />
    </>
  )
}
