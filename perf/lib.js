import http from 'k6/http'
import { check } from 'k6'

export const BASE = __ENV.BASE_URL || 'http://localhost:8000/api/v1'
export const USERS = Number(__ENV.USERS || 100)

/** Test accounts come from the staging seed (never production): trainee01@perf.test … password from PERF_PASSWORD. */
export function login(i) {
  const res = http.post(`${BASE}/auth/login`, JSON.stringify({ email: `trainee${String((i % USERS) + 1).padStart(2, '0')}@perf.test`, password: __ENV.PERF_PASSWORD }), { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { name: 'login' } })
  check(res, { 'login ok': (r) => r.status === 200 })
  return res.json('access_token')
}

export const auth = (token) => ({ headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })

/** Shared thresholds: RFP TEC-02 — p95 under 1.5 s and fewer than 0.1 % errors. */
export const thresholds = {
  http_req_failed: ['rate<0.001'],
  http_req_duration: ['p(95)<1500'],
}
