import { api } from '@/lib/api'

export type LessonType = 'video' | 'presentation' | 'quiz' | 'survey' | 'article'

export type Option = { id: string; text_ar: string; text_en?: string | null; correct?: boolean }
export type QuizQ = { id?: string; type: 'single' | 'multiple' | 'true_false'; text_ar: string; text_en?: string | null; points: number; explanation_ar?: string | null; explanation_en?: string | null; options: Option[] }
export type SurveyQ = { id?: string; type: 'rating' | 'nps' | 'choice' | 'multiple' | 'text'; text_ar: string; text_en?: string | null; required: boolean; options?: Option[] | null }

export type LessonSettings = {
  allow_seeking?: boolean; min_watch_percent?: number; max_speed?: number; pause_when_hidden?: boolean
  min_view_percent?: number; downloadable?: boolean
  pass_percent?: number; max_attempts?: number | null; shuffle_questions?: boolean; shuffle_options?: boolean; show_answers?: 'after_submit' | 'after_pass' | 'never'; time_limit_minutes?: number | null
}

export type Lesson = {
  id: string; module_id: string; type: LessonType; title_ar: string; title_en: string; description_ar: string | null; description_en: string | null
  body_ar: string | null; body_en: string | null; sort_order: number; is_required: boolean; status: 'draft' | 'published'; duration_seconds: number
  source: 'upload' | 'url' | null; file_name: string | null; file_mime: string | null; file_size: number; external_url: string | null; slide_count: number; has_file: boolean
  settings: LessonSettings; questions: QuizQ[]; survey_questions: SurveyQ[]
}

export type Module = { id: string; title_ar: string; title_en: string; description_ar: string | null; description_en: string | null; sort_order: number; lessons: Lesson[] }

export type Course = {
  settings: { has_course: boolean; sequential: boolean; completion_percent: number }
  modules: Module[]
  totals: { modules: number; lessons: number; published: number; video_seconds: number; by_type: Record<string, number> }
}

type Signed = { path: string; url: string; method: 'PUT'; mode: 'form' | 'raw'; headers: Record<string, string> }

/** Sends a big file straight to storage with a progress callback (the API cannot take large bodies on serverless hosting). */
export function putFile(signed: Signed, file: File, onProgress: (percent: number) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open(signed.method, signed.url)
    Object.entries(signed.headers).forEach(([k, v]) => xhr.setRequestHeader(k, v))
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(Math.round((e.loaded / e.total) * 100))
    xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error(`Upload failed (${xhr.status})`)))
    xhr.onerror = () => reject(new Error('Upload failed'))
    if (signed.mode === 'form') {
      const form = new FormData()
      form.append('cacheControl', '3600')
      form.append('', file)
      xhr.send(form)
    } else {
      xhr.send(file)
    }
  })
}

/** Reads the length of a video file in the browser. */
export function videoDuration(file: File): Promise<number> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file)
    const el = document.createElement('video')
    el.preload = 'metadata'
    el.onloadedmetadata = () => { resolve(Number.isFinite(el.duration) ? Math.round(el.duration) : 0); URL.revokeObjectURL(url) }
    el.onerror = () => { resolve(0); URL.revokeObjectURL(url) }
    el.src = url
  })
}

export async function uploadLessonFile(lessonId: string, file: File, extra: { duration_seconds?: number; slide_count?: number }, onProgress: (p: number) => void): Promise<Lesson> {
  const signed = (await api.post<{ data: Signed }>(`/admin/course/lessons/${lessonId}/upload-url`, { filename: file.name, mime: file.type || 'application/octet-stream', size: file.size })).data.data
  await putFile(signed, file, onProgress)
  return (await api.post<{ data: Lesson }>(`/admin/course/lessons/${lessonId}/file`, { path: signed.path, name: file.name, mime: file.type || 'application/octet-stream', size: file.size, ...extra })).data.data
}

export const fmtDuration = (seconds: number) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = Math.floor(seconds % 60)
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`
}

export const fmtSize = (bytes: number) => (bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : bytes >= 1024 ** 2 ? `${(bytes / 1024 ** 2).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

export const newId = () => Math.random().toString(36).slice(2, 10)
