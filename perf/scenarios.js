// k6 run -e BASE_URL=https://staging.example/api/v1 -e PERF_PASSWORD=... -e SCENARIO=mixed perf/scenarios.js
import http from 'k6/http'
import { check, sleep, group } from 'k6'
import { BASE, auth, login, thresholds } from './lib.js'

const stage = (target, duration) => ({ target, duration })
// Ramp to 10,000 virtual users over 20 minutes, hold 30 minutes, ramp down (TEC-02). Scale down with -e PEAK=1000 on a smaller environment and extrapolate (docs/ops/performance-plan.md).
const PEAK = Number(__ENV.PEAK || 10000)

export const options = {
  scenarios: {
    browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 0, stages: [stage(PEAK * 0.5, '20m'), stage(PEAK * 0.5, '30m'), stage(0, '5m')], gracefulRampDown: '1m' },
    portal: { executor: 'ramping-vus', exec: 'portal', startVUs: 0, stages: [stage(PEAK * 0.35, '20m'), stage(PEAK * 0.35, '30m'), stage(0, '5m')], gracefulRampDown: '1m' },
    burst_register: { executor: 'ramping-arrival-rate', exec: 'registerBurst', startTime: '25m', timeUnit: '1s', preAllocatedVUs: 500, maxVUs: 3000, stages: [stage(Math.round(PEAK * 0.05), '1m'), stage(Math.round(PEAK * 0.05), '3m'), stage(0, '1m')] },
    burst_checkin: { executor: 'ramping-arrival-rate', exec: 'checkIn', startTime: '30m', timeUnit: '1s', preAllocatedVUs: 500, maxVUs: 3000, stages: [stage(Math.round(PEAK * 0.08), '1m'), stage(Math.round(PEAK * 0.08), '2m'), stage(0, '1m')] },
    exam: { executor: 'constant-vus', exec: 'exam', vus: Math.round(PEAK * 0.1), duration: '15m', startTime: '35m' },
  },
  thresholds,
}

export function browse() {
  group('catalogue', () => {
    check(http.get(`${BASE}/public/programs?per_page=12`, { tags: { name: 'catalogue' } }), { ok: (r) => r.status === 200 })
    check(http.get(`${BASE}/public/home`, { tags: { name: 'public-home' } }), { ok: (r) => r.status === 200 })
  })
  sleep(Math.random() * 3 + 1)
}

export function portal() {
  const token = login(__VU)
  for (let i = 0; i < 10; i++) {
    group('portal', () => {
      check(http.get(`${BASE}/me/home`, { ...auth(token), tags: { name: 'me-home' } }), { ok: (r) => r.status === 200 })
      check(http.get(`${BASE}/dashboard`, { ...auth(token), tags: { name: 'dashboard' } }), { ok: (r) => r.status === 200 })
      check(http.get(`${BASE}/me/notifications?per_page=10`, { ...auth(token), tags: { name: 'notifications' } }), { ok: (r) => r.status === 200 })
    })
    sleep(Math.random() * 5 + 2)
  }
}

export function registerBurst() {
  const token = login(__VU + __ITER)
  const res = http.get(`${BASE}/me/registrations`, { ...auth(token), tags: { name: 'registrations' } })
  check(res, { ok: (r) => r.status === 200 })
}

export function checkIn() {
  const token = login(__VU + __ITER)
  // The QR code of the live session is posted by every trainee in the first minutes of the morning.
  const res = http.post(`${BASE}/me/attendance/scan`, JSON.stringify({ token: __ENV.QR_TOKEN || 'x', latitude: 25.2854, longitude: 51.531, accuracy: 20 }), { ...auth(token), headers: { ...auth(token).headers, 'Content-Type': 'application/json' }, tags: { name: 'checkin' } })
  check(res, { 'handled': (r) => r.status < 500 })
}

export function exam() {
  const token = login(__VU)
  const ass = http.get(`${BASE}/me/assessments`, { ...auth(token), tags: { name: 'assessments' } })
  check(ass, { ok: (r) => r.status === 200 })
  for (let i = 0; i < 20; i++) {
    // autosave every 30 s while the exam runs
    http.get(`${BASE}/me/notifications?per_page=1`, { ...auth(token), tags: { name: 'autosave-proxy' } })
    sleep(30)
  }
}
