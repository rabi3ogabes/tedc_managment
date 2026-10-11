import { api } from '@/lib/api'
import { putFile, type SignedUpload as Signed } from '@/lib/upload'

export { putFile }

export type LessonType = 'video' | 'presentation' | 'quiz' | 'survey' | 'article' | 'package' | 'lti' | 'external'

export type Option = { id: string; text_ar: string; text_en?: string | null; correct?: boolean }
export type QuizQ = { id?: string; type: 'single' | 'multiple' | 'true_false'; text_ar: string; text_en?: string | null; points: number; explanation_ar?: string | null; explanation_en?: string | null; options: Option[] }
export type SurveyQ = { id?: string; type: 'rating' | 'nps' | 'choice' | 'multiple' | 'text'; text_ar: string; text_en?: string | null; required: boolean; options?: Option[] | null }

export type LessonSettings = {
  allow_seeking?: boolean; min_watch_percent?: number; max_speed?: number; pause_when_hidden?: boolean; require_visible?: boolean; require_fullscreen?: boolean; lock_pause?: number | null; require_pass?: boolean
  min_view_percent?: number; downloadable?: boolean; min_seconds_per_slide?: number
  pass_percent?: number; max_attempts?: number | null; shuffle_questions?: boolean; shuffle_options?: boolean; show_answers?: 'after_submit' | 'after_pass' | 'never'; time_limit_minutes?: number | null
}

export type Lesson = {
  id: string; module_id: string; type: LessonType; title_ar: string; title_en: string; description_ar: string | null; description_en: string | null
  body_ar: string | null; body_en: string | null; sort_order: number; is_required: boolean; status: 'draft' | 'published'; duration_seconds: number
  source: 'upload' | 'url' | null; file_name: string | null; file_mime: string | null; file_size: number; external_url: string | null; slide_count: number; has_file: boolean
  settings: LessonSettings; questions: QuizQ[]; survey_questions: SurveyQ[]
  package_id?: string | null; package_item_id?: string | null; lti_tool_id?: string | null; version?: number
}

export type Module = { id: string; title_ar: string; title_en: string; description_ar: string | null; description_en: string | null; sort_order: number; lessons: Lesson[] }

export type Course = {
  settings: { has_course: boolean; sequential: boolean; completion_percent: number; auto_certificate: boolean }
  modules: Module[]
  totals: { modules: number; lessons: number; published: number; video_seconds: number; by_type: Record<string, number> }
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
