/**
 * The training centre as a map: two floors drawn on the plans in /public/campus (1290 × 942 pixels each), the walking routes
 * between rooms, and the stages of a training journey. Everything here is plain data, so the centre can correct a room or a route
 * without touching the animation. Room-to-stage assignments are illustrative: they show the journey, not the real use of a room.
 */
export type Floor = 'ground' | 'first'
export type Pt = [number, number]

export const PLAN = { w: 1290, h: 942 } as const

/**
 * Walking routes along the yellow corridors of each plan. They were traced once, offline, as the shortest centred path through the
 * corridor pixels of the two plan images between the doors below (so they stay on the corridors), then simplified to a few points.
 * Keys are "from>to"; the walk in the other direction uses the same points reversed.
 */
export const FLOOR_ROUTES: Record<Floor, { nodes: Record<string, Pt>; paths: Record<string, Pt[]> }> = {
  ground: {
    nodes: {
      entry: [470, 758],
      admin: [630, 740],
      atrium: [650, 394],
      rooms12: [814, 670],
      stairs: [818, 550],
      excel: [816, 420],
    },
    paths: {
      'entry>admin': [[471,759],[471,749],[485,735],[625,735],[631,741]],
      'entry>atrium': [[471,759],[457,739],[459,409],[477,393],[651,395]],
      'entry>rooms12': [[471,759],[471,749],[485,735],[799,739],[811,727],[815,671]],
      'entry>stairs': [[471,759],[471,749],[485,735],[799,739],[813,715],[809,561],[819,551]],
      'entry>excel': [[471,759],[457,739],[455,501],[459,409],[473,395],[591,391],[611,401],[673,401],[689,391],[803,393],[817,421]],
      'admin>atrium': [[631,741],[621,735],[467,735],[457,725],[459,409],[477,393],[651,395]],
      'admin>rooms12': [[631,741],[799,739],[811,727],[815,671]],
      'admin>stairs': [[631,741],[799,739],[811,727],[809,561],[819,551]],
      'admin>excel': [[631,741],[621,735],[467,735],[457,725],[459,409],[477,393],[591,391],[611,401],[673,401],[689,391],[803,393],[817,421]],
      'atrium>rooms12': [[651,395],[475,393],[459,409],[457,725],[467,735],[799,739],[811,727],[815,671]],
      'atrium>stairs': [[651,395],[475,393],[459,409],[457,725],[467,735],[799,739],[813,715],[809,561],[819,551]],
      'atrium>excel': [[651,395],[803,393],[817,421]],
      'rooms12>stairs': [[815,671],[809,561],[819,551]],
      'rooms12>excel': [[815,671],[811,727],[799,739],[467,735],[457,725],[455,501],[459,409],[477,393],[591,391],[611,401],[673,401],[689,391],[803,393],[817,421]],
      'stairs>excel': [[819,551],[809,561],[813,717],[799,739],[467,735],[457,725],[459,409],[473,395],[591,391],[611,401],[673,401],[689,391],[803,393],[817,421]],
    },
  },
  first: {
    nodes: {
      stairs: [820, 550],
      lab: [900, 166],
      oasis: [560, 740],
      meet: [640, 742],
    },
    paths: {
      'stairs>lab': [[821,551],[813,543],[811,389],[911,289],[1017,285],[1031,271],[1033,187],[1015,169],[901,167]],
      'stairs>oasis': [[821,551],[813,543],[811,401],[793,383],[703,385],[669,403],[607,403],[587,393],[465,397],[431,431],[431,489],[447,509],[453,539],[455,729],[463,737],[561,741]],
      'stairs>meet': [[821,551],[813,543],[811,401],[793,383],[703,385],[669,403],[607,403],[587,393],[469,395],[431,431],[431,489],[447,509],[453,539],[455,729],[463,737],[635,737],[641,743]],
      'lab>oasis': [[901,167],[1015,169],[1033,187],[1033,269],[1017,285],[915,287],[809,383],[717,383],[669,403],[607,403],[587,393],[469,395],[431,431],[431,489],[447,509],[453,539],[455,729],[463,737],[561,741]],
      'lab>meet': [[901,167],[1015,169],[1033,187],[1033,269],[1017,285],[915,287],[809,383],[717,383],[669,403],[607,403],[587,393],[469,395],[431,431],[431,489],[447,509],[453,539],[455,729],[463,737],[635,737],[641,743]],
      'oasis>meet': [[561,741],[635,737],[641,743]],
    },
  },
}

export type Stage = {
  key: 'arrive' | 'needs' | 'register' | 'train' | 'practise' | 'assess' | 'certify' | 'impact'
  view: Floor | 'campus'
  /** the door the walker heads for */
  node?: string
  /** where the room's label stands on the plan */
  pin?: Pt
  /** which public statistic the stage highlights */
  stat: 'schools' | 'participants' | 'programs' | 'training_hours' | 'certificates' | 'satisfaction' | 'trainers' | 'employees'
  link?: string
}

export const STAGES: Stage[] = [
  { key: 'arrive', view: 'campus', stat: 'schools' },
  { key: 'needs', view: 'ground', node: 'admin', pin: [630, 800], stat: 'employees', link: '/about' },
  { key: 'register', view: 'ground', node: 'excel', pin: [905, 430], stat: 'participants', link: '/programs' },
  { key: 'train', view: 'ground', node: 'rooms12', pin: [990, 672], stat: 'training_hours', link: '/programs' },
  { key: 'practise', view: 'first', node: 'lab', pin: [1075, 95], stat: 'programs', link: '/programs' },
  { key: 'assess', view: 'first', node: 'oasis', pin: [580, 810], stat: 'trainers', link: '/trainers' },
  { key: 'certify', view: 'ground', node: 'admin', pin: [630, 800], stat: 'certificates', link: '/verify' },
  { key: 'impact', view: 'campus', stat: 'satisfaction' },
]

/** The walk between two doors of a floor: the traced route, reversed when it was traced the other way round. */
export function route(floor: Floor, from: string, to: string): Pt[] {
  const r = FLOOR_ROUTES[floor]
  if (from === to) return [r.nodes[from]]
  const direct = r.paths[`${from}>${to}`]
  if (direct) return direct
  const back = r.paths[`${to}>${from}`]
  return back ? [...back].reverse() : [r.nodes[from], r.nodes[to]]
}

/** The door where a walker steps onto a floor, and where the stairs come out. */
export const ENTRY: Record<Floor, string> = { ground: 'entry', first: 'stairs' }

/** Routes the ambient walkers pace up and down (they only add life; the story is the trainee's walk). */
export const AMBIENT: Record<Floor, string[]> = {
  ground: ['entry>atrium', 'admin>rooms12', 'stairs>atrium', 'entry>stairs'],
  first: ['stairs>oasis', 'stairs>lab', 'lab>oasis', 'stairs>meet'],
}

export function length(points: Pt[]): number {
  let l = 0
  for (let i = 1; i < points.length; i++) l += Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1])
  return l
}

/** The point a given fraction (0–1) along a polyline. */
export function pointAt(points: Pt[], f: number): Pt {
  const total = length(points)
  if (total === 0) return points[0]
  let want = Math.max(0, Math.min(1, f)) * total
  for (let i = 1; i < points.length; i++) {
    const seg = Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1])
    if (want <= seg) { const k = seg === 0 ? 0 : want / seg; return [points[i - 1][0] + (points[i][0] - points[i - 1][0]) * k, points[i - 1][1] + (points[i][1] - points[i - 1][1]) * k] }
    want -= seg
  }
  return points[points.length - 1]
}
