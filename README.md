# TEDC — Training Management & Impact Platform
### منصة إدارة التدريب وقياس الأثر — مركز التدريب والتطوير التربوي

An enterprise, Arabic-first (RTL) training ecosystem for a training center serving the schools of the State of Qatar.
It manages the **complete training lifecycle**:

```
Training Needs → Program Creation → Registration → Nomination → Attendance → Tasks
→ Evaluation → Certificate → Impact Measurement (30/60/90 days) → Reports
```

| Layer | Technology | Folder |
|-------|------------|--------|
| REST API | PHP 8.4 · Laravel 13 · JWT (Supabase Auth) · RBAC | [`backend/`](backend) |
| Database / Auth / Storage / Realtime | Supabase (PostgreSQL, GoTrue, Storage, Realtime) | [`supabase/`](supabase) |
| Web (public website + admin dashboard + employee portal) | React 19 · TypeScript · Vite · Tailwind CSS v4 | [`web/`](web) |
| Mobile (employees & schools) | Flutter 3 · Riverpod · go_router · Dio | [`mobile/`](mobile) |
| AI Training Assistant | Anthropic Claude (with a rule-based fallback) | `backend/app/Services/AiAssistant.php` |

Documentation: [Architecture](docs/ARCHITECTURE.md) · [API reference](docs/API.md) · [Supabase setup](supabase/README.md)

---

## Highlights

- **Arabic first.** All numbers and dates use Western (English) digits. Arabic is the default language across the API (`X-Locale`), web (RTL) and mobile, with a full English
  translation. All content is bilingual (`*_ar` / `*_en`).
- **Luxury government design.** Deep navy, off-white and gold, glass cards, El Messiri / Tajawal typography. The homepage
  opens with an **image slider of Qatar**, including a slide dedicated to the **Ministry of Education and Higher Education**
  (see [`web/public/images/hero`](web/public/images/hero/README.md) to drop in official photography).
- **Smart Eligibility Engine.** Declarative rules per program (job title, category, department, school type/stage,
  education stage, region, experience, qualification, completed programs, skill levels) evaluated with an explanation
  for every rule: green **"مؤهل / Eligible"** or red **"غير مؤهل / Not Eligible"**.
- **Smart Recommendation Engine.** Explainable 0–100 score from skill gaps, role fit, school training needs, career stage
  and peer rating — *"Recommended Training For You"*.
- **Four registration channels.** Employee self-registration, school-admin nomination, training-center nomination
  (with audited eligibility override) and Excel/CSV bulk import — with capacity control and an automatic waiting list.
- **Dynamic QR attendance.** HMAC-signed codes rotating every 30 s; check-in / check-out, lateness, attendance %.
- **Smart Certificate Engine.** Issues only when registration, attendance %, required tasks and evaluation are complete;
  bilingual PDF (mPDF, proper Arabic shaping) with QR verification and a public verification page.
- **Impact measurement.** Surveys at 30/60/90 days, supervisor evaluation, and a weighted **Training Impact Score**.
- **AI Training Assistant.** Ask *"What training programs should we create for teachers?"* — the assistant grounds
  Claude in aggregated platform data and returns suggested programs, seats, target groups and rationale.
- **Brand Studio.** Administrators restyle the platform live — primary/accent colors, buttons, links, banners and
  slider images, background colors and background patterns (Islamic star, arabesque, grid…), with curated luxury
  presets, a live desktop/mobile preview and an accessibility contrast check. Published instantly to every page.
- **Analytics.** Admin dashboard, executive dashboard, geographic map of coverage and gaps, training-needs analytics.
- **Security.** Supabase JWT verification (HS256 or JWKS), 8 roles / 33 permissions, row-level security, private storage
  with short-lived signed URLs, encrypted national IDs, rate limiting, security headers and an append-only audit log.

## Quick start (local development)

Prerequisites: PHP 8.3+, Composer, Node 22+, Flutter 3.x (for mobile).

```bash
# 1. API — runs with SQLite and local auth out of the box
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # roles, permissions, reference data + Qatar demo data
php artisan serve                   # http://localhost:8000

# 2. Web
cd ../web
npm install
npm run dev                         # http://localhost:5173 (proxies /api to :8000)

# 3. Mobile (Android emulator reaches the host at 10.0.2.2)
cd ../mobile
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api/v1
```

### Demo accounts

All demo passwords: `Tedc@2026!`

| Role | E-mail |
|------|--------|
| Super Admin | admin@tedc.qa |
| Training Center Admin | center@tedc.qa |
| Program Coordinator | coordinator@tedc.qa |
| Trainer | trainer@tedc.qa |
| School Admin | school@tedc.qa |
| Employee (teacher) | teacher@tedc.qa |
| Supervisor | supervisor@tedc.qa |
| Executive | executive@tedc.qa |

A demo certificate can be verified at `/verify/TEDCDEMO2026`.

## Production with Supabase

1. Create the Supabase project and follow the step-by-step guide in [`supabase/README.md`](supabase/README.md)
   (keys, migrations, RLS, buckets, realtime, and `php artisan tedc:supabase-sync-users` to create the logins).
2. API `.env`: `DB_CONNECTION=pgsql`, `DB_URL=…`, `TEDC_AUTH_DRIVER=supabase`, `TEDC_STORAGE_DRIVER=supabase`,
   `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`, `SUPABASE_JWT_SECRET`, `CORS_ALLOWED_ORIGINS`,
   `TEDC_WEB_URL`, and optionally `ANTHROPIC_API_KEY` for the AI assistant.
3. Run the scheduler (`php artisan schedule:work` or cron `* * * * * php artisan schedule:run`) — it advances program
   statuses, sends session reminders and dispatches 30/60/90-day impact surveys.
4. Web: `VITE_API_URL=https://api.example.qa/api/v1 npm run build` (plus `VITE_SUPABASE_URL` /
   `VITE_SUPABASE_ANON_KEY` for live notifications) and serve `web/dist` as a SPA.
5. Mobile: `flutter build appbundle|ipa --dart-define=API_URL=… --dart-define=SUPABASE_URL=… --dart-define=SUPABASE_ANON_KEY=…`

## Quality

| Check | Command |
|-------|---------|
| API tests (38 feature tests) | `cd backend && php artisan test` |
| PHP code style | `cd backend && vendor/bin/pint --test` |
| Web type-check, lint & build | `cd web && npm run lint && npm run build` |
| Mobile analysis & tests | `cd mobile && flutter analyze && flutter test` |

CI runs all of the above on every push ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).
