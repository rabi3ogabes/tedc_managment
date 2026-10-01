export type Paginated<T> = {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number; per_page?: number }
  links?: unknown
}

export type LaravelPage<T> = { data: T[]; current_page: number; last_page: number; total: number }

export type Skill = { id: string; code: string; name: string; target_level?: number; level?: number; category?: string; source?: string }

export type TrainerSource = 'center' | 'school' | 'ministry' | 'partner' | 'external' | 'international'

export type Trainer = {
  id: string; name: string; name_ar: string; name_en: string; title?: string; title_ar?: string | null; title_en?: string | null; bio?: string; bio_ar?: string | null; bio_en?: string | null
  photo_url?: string | null; specializations: string[]; is_external: boolean; organization?: string | null; rating: number; programs_count?: number; sessions_count?: number; role?: string
  source: TrainerSource; source_label: string; country?: string | null; languages: string[]; experience_years: number; status: string
  email?: string | null; phone?: string | null; employee_id?: string | null; school_id?: string | null; school?: { id: string; name: string } | null
  partner_id?: string | null; partner?: { id: string; name: string; type: string } | null; city?: string | null; hourly_rate?: number | null; currency?: string; notes?: string | null
}

export type Partner = {
  id: string; name_ar: string; name_en: string; type: string; country: string; contact_name?: string | null; email?: string | null; phone?: string | null
  website?: string | null; notes?: string | null; status: string; trainers_count?: number
}

export type Room = {
  id: string; code?: string | null; name: string; name_ar: string; name_en: string; office?: string | null; building?: string | null; location?: string | null; floor?: string | null
  capacity: number; area_m2?: number | null; layout: string; layouts: { key: string; label: string; capacity: number }[]
  equipment: { key: string; qty: number; label: string }[]; is_accessible: boolean; status: 'active' | 'maintenance' | 'inactive'; notes?: string | null; latitude?: number | null; longitude?: number | null
  sessions_count?: number; upcoming_sessions_count?: number
}

export type CalendarKind = 'workday' | 'weekend' | 'vacation' | 'exam' | 'normal'

export type CalendarDayInfo = {
  date: string; weekday: number; kind: CalendarKind; is_working_day: boolean; is_weekend: boolean; training_allowed: boolean; requires_approval: boolean; sessions_count: number
  entry: { id: string; type: string; title: string; title_ar: string; title_en: string; notes?: string | null } | null
  approval: { id: string; reason: string; approved_at?: string | null; approved_by?: string | null } | null
}

export type CalendarSummary = { total: number; working: number; off: number; vacation: number; exam: number; normal: number; weekend: number; approved: number; open_for_training: number; sessions: number }

export type Session = {
  id: string; program_id: string; sequence: number; title: string; title_ar: string; title_en: string; description?: string | null
  starts_at: string; ends_at: string; duration_minutes: number; location?: string | null; location_text?: string | null; online_url?: string | null
  mode?: 'in_person' | 'online'; online_platform?: string | null; online_passcode?: string | null; recording_url?: string | null
  activities: string[]; status: string; trainer_id?: string | null; trainer?: { id: string; name: string } | null; training_room_id?: string | null
  room?: { id: string; name: string; building?: string } | null; program?: { id: string; code: string; title: string; capacity: number; status: string }
  attendance_count?: number
}

export type Program = {
  id: string; code: string; title: string; title_ar: string; title_en: string; summary?: string | null; summary_ar?: string | null; summary_en?: string | null
  description?: string | null; description_ar?: string | null; description_en?: string | null; objectives: string[]
  category?: { id: string; slug: string; name: string; color?: string; icon?: string } | null; category_id?: string | null
  delivery_mode: 'in_person' | 'online' | 'hybrid'; level: 'beginner' | 'intermediate' | 'advanced'; total_hours: number; capacity: number
  seats_taken?: number | null; seats_available?: number | null; min_attendance_percent: number; requires_tasks: boolean; requires_evaluation: boolean
  start_date?: string | null; end_date?: string | null; registration_opens_at?: string | null; registration_closes_at?: string | null
  registration_open: boolean; registration_modes: string[]; status: string; is_featured: boolean; cover_url?: string | null
  remote?: { platform?: string; join_url?: string | null; passcode?: string | null; join_opens_minutes?: number; instructions_ar?: string | null; instructions_en?: string | null } | null
  certificate_template_id?: string | null; trainer_certificate_template_id?: string | null
  skills?: Skill[]; trainers?: Trainer[]; sessions?: Session[]
  target_groups?: { id: string; job_title_id?: string | null; job_title?: string | null; school_type?: string | null; education_stage?: string | null; description?: string | null }[]
  eligibility_rules?: EligibilityRule[]
}

export type EligibilityRule = {
  id?: string; field: string; operator: string; value: unknown; message_ar?: string | null; message_en?: string | null; is_mandatory: boolean
}

export type EligibilityCheck = { key: string; field: string; field_label?: string; passed: boolean; mandatory: boolean; message: string }

export type Eligibility = {
  eligible: boolean; status: 'eligible' | 'not_eligible'; label: string; color: 'green' | 'red'; summary: string
  checks: EligibilityCheck[]; warnings: EligibilityCheck[]
  registration_open?: boolean; self_registration?: boolean; seats_available?: number; registration?: { id: string; status: string } | null
}

export type Employee = {
  id: string; employee_no: string; user_id: string; name?: string; name_ar?: string; name_en?: string; email?: string
  school?: { id: string; name: string; region: string; type: string } | null; job_title?: { id: string; code: string; name: string; category: string } | null
  experience_years: number; education_stage?: string | null; qualification?: string | null; skills?: Skill[]
}

export type Registration = {
  id: string; program_id: string; program?: Program; employee_id: string; employee?: Employee; source: string; status: string
  eligibility?: Eligibility | null; attendance_percent: number; tasks_completed: boolean; evaluation_completed: boolean
  certificate_status: string; impact_score?: number | null; completed_at?: string | null; created_at?: string; certificate?: Certificate | null
  has_course?: boolean; course_percent?: number; course_completed?: boolean
}

export type Certificate = {
  id: string; certificate_no: string; verification_code: string; verification_url: string; issued_at: string; hours: number; status: string
  program?: { id: string; code: string; title: string }; employee?: { id: string; employee_no: string; name: string }; download_url: string
  is_sent: boolean; sent_at: string | null; sent_count: number; sent_to: string | null; sent_by: string | null; send_error: string | null
}

export type CertificateSendResult = {
  sent: number; total: number
  skipped: { id: string; certificate_no: string; employee: string | null; reason: string }[]
  failed: { id: string; certificate_no: string; employee: string | null; reason: string }[]
}

export type Me = {
  id: string; name: string; name_en: string; name_ar?: string | null; email: string; locale: string
  roles: { slug: string; name: string }[]; permissions: string[]; employee: Employee | null; trainer_id?: string | null
}

export type NotificationItem = { id: string; type: string; title: string; body?: string | null; data: Record<string, unknown>; read: boolean; created_at: string }
