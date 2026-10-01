export type Audience = {
  school_ids?: string[]; regions?: string[]; school_types?: string[]; stages?: string[]; job_title_ids?: string[]; specializations?: string[]
  nationalities?: string[]; genders?: string[]; qualifications?: string[]
  experience_min?: number; experience_max?: number; age_min?: number; age_max?: number
}

export type NeedTopic = {
  key: string; skill_id: string | null; skill: string; skill_code: string | null; category: string | null; need_ids: string[]; requests: number; employees: number; schools: number
  school_names: string[]; priority: string; score: number; reasons: string[]; job_titles: string[]; from_survey: boolean
  existing_programs: { id: string; code: string; title: string; status: string; seats_available: number }[]
}

export type BuilderOptions = {
  needs: NeedTopic[]
  totals: { topics: number; requests: number; employees: number; critical: number }
  filters: {
    specializations: string[]; nationalities: string[]; qualifications: string[]; stages: string[]; genders: string[]; experience_bands: string[]; age_bands: string[]
    job_titles: { id: string; code: string; name_ar: string; name_en: string; category: string }[]
    schools: { id: string; name_ar: string; name_en: string; region: string; type: string; stage: string }[]
    regions: string[]; school_types: string[]
  }
  categories: { id: string; slug: string; name_ar: string; name_en: string }[]
  skills: { id: string; code: string; name_ar: string; name_en: string; category: string }[]
  session_hours: number
  day: { day_start: string; day_end: string; enforce_window: boolean; one_session_per_room_per_day: boolean }
}

export type AudiencePreview = {
  count: number; schools: number; summary: string
  by_school: { label: string; value: number }[]; by_job_title: { label: string; value: number }[]; by_specialization: { label: string; value: number }[]
  by_experience: { label: string; value: number }[]; by_age: { label: string; value: number }[]; by_gender: { label: string; value: number }[]
  sample: { id: string; employee_no: string; name: string; school: string | null; job_title: string | null; specialization: string | null; age: number | null; experience_years: number }[]
}

export type PlannedSession = {
  sequence: number; title_ar: string; title_en: string; starts_at: string; ends_at: string; training_room_id: string | null; room: string | null; trainer_id: string | null
  mode?: 'in_person' | 'online'; online_url?: string
}

export type TrainerSuggestion = { id: string; name: string; source: string; source_label: string; organization: string | null; rating: number; score: number; available: boolean; matched: string[] }

export type Draft = {
  code: string; title_ar: string; title_en: string; summary_ar: string; summary_en: string; category_id: string | null; objectives: string[]
  skills: { id: string; name: string; target_level: number }[]; delivery_mode: 'in_person' | 'online' | 'hybrid'; level: 'beginner' | 'intermediate' | 'advanced'
  total_hours: number; capacity: number; cohorts: number; demand: number; min_attendance_percent: number; requires_tasks: boolean; requires_evaluation: boolean
  registration_modes: string[]; start_date: string | null; end_date: string | null; registration_opens_at: string | null; registration_closes_at: string | null
  audience: Audience; audience_summary: string; need_ids: string[]; sessions: PlannedSession[]; trainers: TrainerSuggestion[]; priority: string | null
}
