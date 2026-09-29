import type { Audience } from './types'

const listKeys = ['school_ids', 'regions', 'school_types', 'stages', 'job_title_ids', 'specializations', 'nationalities', 'genders', 'qualifications'] as const
export const numberKeys = ['experience_min', 'experience_max', 'age_min', 'age_max'] as const

/** Count of active filters (used for the "clear" button and the empty-filter hint). */
export const activeFilters = (a: Audience) => listKeys.filter((k) => (a[k]?.length ?? 0) > 0).length + numberKeys.filter((k) => a[k] !== undefined).length
