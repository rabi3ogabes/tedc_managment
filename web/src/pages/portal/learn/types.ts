export type OutlineLesson = {
  id: string; type: 'video' | 'presentation' | 'quiz' | 'survey' | 'article' | 'package' | 'lti' | 'external'; title: string; description: string | null; duration_seconds: number; is_required: boolean
  locked: boolean; status: 'not_started' | 'in_progress' | 'completed'; percent: number; score: number | null; last_activity_at: string | null
}
export type Outline = {
  registration_id: string; program: { id: string; code: string; title: string }
  modules: { id: string; title: string; description: string | null; lessons: OutlineLesson[] }[]
  summary: { lessons: number; required: number; completed: number; percent: number; completed_course: boolean; required_percent: number; sequential: boolean; duration_seconds: number; certificate: { issued: boolean; downloadable: boolean; eligible: boolean; missing: string[] }; next_lesson_id: string | null; resume_lesson_id: string | null }
}

export type QuizQuestion = { id: string; type: 'single' | 'multiple' | 'true_false'; text: string; points: number; options: { id: string; text: string }[] }
export type SurveyQuestion = { id: string; type: 'rating' | 'nps' | 'choice' | 'multiple' | 'text'; text: string; required: boolean; options: { id: string; text: string }[] }

export type LessonDetail = {
  id: string; type: OutlineLesson['type']; title: string; description: string | null; body: string | null; is_required: boolean; duration_seconds: number; slide_count: number; registration_id: string
  media: { kind: 'file'; url: string; mime: string | null; name: string | null } | { kind: 'link'; url: string; embed: string | null } | null
  rules: { allow_seeking: boolean; max_speed: number; pause_when_hidden: boolean; min_watch_percent: number; min_view_percent: number; downloadable: boolean }
  progress: { status: string; percent: number; position: number; furthest: number; segments: [number, number][]; best_score: number | null; attempts: number }
  quiz: { questions: QuizQuestion[]; pass_percent: number; max_attempts: number | null; attempts: number; time_limit_minutes: number | null } | null
  survey: { submitted: boolean; questions: SurveyQuestion[] } | null
}

export type ProgressResult = { percent: number; status: string; completed: boolean; position?: number; furthest?: number; credited?: number }

export const clock = (seconds: number) => {
  const s = Math.max(0, Math.floor(seconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}` : `${m}:${String(s % 60).padStart(2, '0')}`
}
