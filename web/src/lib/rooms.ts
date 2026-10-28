import type { QueryClient } from '@tanstack/react-query'

/** Rooms a person may pick: active ones (and those under maintenance), plus the one already chosen even if it was deactivated since. */
export const pickableRooms = <T extends { id: string; status?: string }>(rooms: T[] | undefined, current?: string | null): T[] =>
  (rooms ?? []).filter((r) => r.status !== 'inactive' || r.id === current)

/** After rooms change in bulk, every screen holding a room list reloads it: rooms, lookups, bookings, SABER+ calendars and pickers. */
export const refreshRoomLists = (qc: QueryClient) =>
  qc.invalidateQueries({ predicate: (q) => ['/admin/rooms', '/admin/lookups', '/admin/room', '/admin/saber', '/admin/places'].some((p) => String(q.queryKey[0] ?? '').startsWith(p)) })
