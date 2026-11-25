import { areaOf } from '@/lib/pageAccess'

/**
 * How the features of a page are laid out when they are listed one by one («الصفحات» → «المزايا»). An area with many
 * rights reads better by what the rights act on; سابر+ is laid out by hand, in the order of the work. Any other area is
 * one list in the order of the catalogue.
 */
const LAYOUTS: Record<string, readonly (readonly [string, readonly string[]])[]> = {
  saber: [
    ['page', ['saber.view']],
    ['programs', ['saber.program.create', 'saber.program.edit', 'saber.program.cancel', 'saber.program.reactivate']],
    ['groups', ['saber.group.create', 'saber.group.edit', 'saber.group.rename', 'saber.group.delete', 'saber.group.validate']],
    ['schedule', ['saber.day.edit', 'saber.room.assign', 'saber.trainer.assign', 'saber.supervisor.assign', 'saber.plan.view']],
    ['tools', ['saber.import', 'saber.survey_links.manage', 'saber.meeting.recurring']],
    ['review', ['saber.publish']],
  ],
}

export type FeatureGroup = { id: string; features: string[] }

/** The features in groups: laid out by hand where a layout exists, with anything it does not name kept under «other». */
export function featureGroups(features: readonly string[]): FeatureGroup[] {
  const areas = [...new Set(features.map(areaOf))]
  const layout = areas.length === 1 ? LAYOUTS[areas[0]] : undefined
  if (!layout) return features.length ? [{ id: '', features: [...features] }] : []
  const placed = new Set(layout.flatMap(([, list]) => list))
  const rest = features.filter((f) => !placed.has(f))
  return [
    ...layout.map(([id, list]) => ({ id, features: list.filter((f) => features.includes(f)) })),
    { id: 'other', features: rest },
  ].filter((group) => group.features.length > 0)
}
