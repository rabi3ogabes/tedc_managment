// A one-minute check that the scenarios still work, run on every release in staging: k6 run -e BASE_URL=... -e PERF_PASSWORD=... perf/smoke.js
import { check, sleep } from 'k6'
import http from 'k6/http'
import { BASE, auth, login, thresholds } from './lib.js'

export const options = { vus: 20, duration: '1m', thresholds }

export default function () {
  check(http.get(`${BASE}/public/health/ready`), { ready: (r) => r.status === 200 })
  check(http.get(`${BASE}/public/programs?per_page=6`), { catalogue: (r) => r.status === 200 })
  const token = login(__VU)
  check(http.get(`${BASE}/me/home`, auth(token)), { home: (r) => r.status === 200 })
  sleep(1)
}
