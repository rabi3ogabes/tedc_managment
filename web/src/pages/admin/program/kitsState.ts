export type Kit = { id: string; code: string; title_ar: string; title_en: string; delivery: string; duration_hours: number; version: number; files: number; matches: boolean; has_pre: boolean; has_post: boolean; assessment_files: string[] }
export type KitsState = {
  kitIds: string[]
  instruments: { trainee_survey: boolean; trainer_reflection: boolean; pre_test: boolean; post_test: boolean; survey_auto_hours: number }
}
export const EMPTY_KITS: KitsState = { kitIds: [], instruments: { trainee_survey: true, trainer_reflection: true, pre_test: true, post_test: true, survey_auto_hours: 24 } }
