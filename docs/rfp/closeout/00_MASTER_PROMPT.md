# 00 — MASTER PROMPT · TEDC close-out: the last 50 RFP requirements

> **How to use.** Open the `tedc_managment` repository in Claude Code and paste this whole file **once** at the start of the session. It loads the rules and ends by running the **Preflight** (section 9). Then paste the phase files in order: `19` → `27`.
> **New session later?** Paste this file, then the line `RULES ONLY — the Preflight is done; do not run section 9.`, then the next phase file.
> **Before you start, read** `docs/rfp/closeout/OPEN_ITEMS.md`. It is the checklist for this pack: every open item, what exists today, exactly what is missing, and which phase closes it.

---

## 1. Your role and the mission

You are the lead engineer and product designer of **TEDC**, the Educational Training Portal of the Training & Development Centre, Ministry of Education and Higher Education (MOEHE), Qatar. The platform is in production (Laravel 13 API · React 19 web · Flutter app · Supabase on Vercel today; Azure Qatar Central is the target).

A requirement-by-requirement check against the RFP (*مشروع بوابة التدريب التربوي*, May 2026) on **10 October 2026** found **278 requirements: 228 available, 50 partial, 0 missing**. Of the 25 mandatory software items, 21 are met. The 4 that are partial are 25 PLC-03, 27 EXM-10, 28 NTF-03 and 31 AI-03 / AI-05.

**Your mission:** close the 50 partial requirements in nine phases (19 → 27). Wherever software can finish an item, finish it completely. Where only the Ministry or a real environment can finish it, build everything up to that point so that going live is configuration plus a test run, never new code. Then say plainly what is still outside your reach.

The finished work must be:
- **Professional.** Production code only: no `TODO`, no placeholder text, no dead code, no mock data in production paths.
- **Luxury and modern.** It looks and feels like the existing Qatar Government identity (Al Adaam maroon `#8A1538`, Dune `#A29475`, through the theme tokens, never hard-coded): calm layouts, generous spacing, refined typography, purposeful motion.
- **Easy.** The shortest path for every task, clear labels, helpful empty states, sensible defaults.
- **Smart.** Rules are automated and explained ("why") in plain Arabic and English.
- **Bug-free and proven.** Every behaviour has a test that failed before the code existed. Every screen is clicked through in Arabic and English, at desktop and phone width.

## 2. Read before Phase 19

1. `docs/rfp/closeout/OPEN_ITEMS.md`: the 50 items and the phase that owns each one.
2. `docs/rfp/gap-register.md`: the register. Its full table is the source of truth. The "Open gaps" checklist, the counts and the 31 mandatory items are **derived** by `scripts/rfp_sync.py`.
3. `docs/rfp/rfp-comparison-2026-10-10.md`: the latest verified comparison and its method.
4. The "Limits" or "Known limits" section of each earlier phase document (`docs/rfp/phase-NN-*.md`). Your phase extends what they built; never build a parallel version.
5. The original RFP text, when a phase quotes it: `~/Downloads/tedc_rfp/src/rfp.txt` (Arabic). When the RFP gives an example value, make it the default **and** make it configurable.

File paths in the phase prompts were verified on 11 October 2026. **Always open the real file before changing it** and follow what is there.

## 3. Project conventions (exactly as the code does them today)

### Backend (`backend/`, PHP 8.4, Laravel 13)
- **IDs and text:** primary keys are UUIDs (`uuid('id')->primary()`, `foreignUuid(...)`). Bilingual columns come in pairs (`*_ar` / `*_en`). Models use `#[Fillable([...])]`, `HasTranslations` and `Auditable` like their neighbours.
- **Migrations:** new files continue the sequence after `2026_01_01_005800_lesson_attention.php`, so the next one is `2026_01_01_005900_<name>.php`. Every migration has a working `down()`. Never edit an old migration.
- **Route parameters:** a new route parameter name that binds a model must be added to the UUID pattern list in `app/Providers/AppServiceProvider.php`. The list once hid `rooms/calendar` behind `rooms/{room}`. Literal routes go **before** parameter routes.
- **No closure routes.** Vercel runs `route:cache`; use controller classes.
- **Services and errors:** business logic lives in `app/Services/…` (or the domain folders `app/Social`, `app/Ai`, `app/Integrations`, `app/Payments`, `app/Learning`, `app/Help`). Controllers stay thin. Domain errors throw `App\Exceptions\BusinessRuleException` with a message in **both** `lang/ar/messages.php` and `lang/en/messages.php`.
- **Permissions:** add each new permission to `RolePermissionSeeder::PERMISSIONS`, attach it to the right roles, **and append it to `ADDITIVE`** so `tedc:deploy` grants it on existing databases. Routes use the `permission:` middleware.
- **Feature flags:** `config/features.php` + `App\Support\Features` + the `feature:<key>` middleware (a disabled feature answers 403 with a reason). Optional, risky or Ministry-dependent features get a flag that is **off by default**.
- **Settings:** the SiteSetting service pattern (`App\Services\SecuritySettings`, `EvaluationSettings`): defaults, validation, audit, an admin screen. Anything the centre must configure belongs in a settings screen, not only in `.env`.
- **Notifications:** register events in `app/Services/Notifications/NotificationCatalog.php` (bilingual) and send through `NotificationService`. `DeliveryPolicy` decides channels.
- **Files:** always `App\Services\FileStorage`. Vercel rejects request bodies over **≈ 4.5 MB**, so larger browser uploads use `signedUpload()` (direct to storage) and then a "complete" call. Validate MIME, extension and size on the server.
- **Integrations:** go through `App\Integrations\IntegrationManager` / `IntegrationRegistry`. Each one is a card with encrypted settings, a health check, a call log, a real HTTP driver and a `fake` driver; the fake driver is never the production default.
- **PostgreSQL is stricter than SQLite.** uuid columns reject `''` and non-UUID strings; varchar lengths are enforced; integer columns reject strings. The `backend-postgres` CI job and `FeatureSamplesTest` catch these. Keep the sample seeders (`database/seeders/Samples/*`) valid for every new table.
- **Data classification:** classify every new column in `config/data_classification.php`, then run `php artisan tedc:data-classification --write`. It reads the **local** schema, so run `php artisan migrate` first. `OpsSecurityTest` fails if the register is stale.
- **Tests:** `backend/tests/Feature`, using the `Tests\TestCase` helpers (`makeUser`, `makeSchool`, `makeEmployee`, `makeProgram`, `makeSession`, `asUser`). `php artisan test --filter=X` prints JSON. Throttle limiters can trip tests (`withoutMiddleware(ThrottleRequests::class)`). Code style: `vendor/bin/pint`.

### Web (`web/`, React 19, TypeScript strict, Vite, Tailwind v4)
- **Data and strings:** `useGet` / `api` from `hooks/useApi.ts` and `lib/api.ts`. Every string goes in `web/src/i18n/*.ts`, Arabic first. The English object is typed `typeof <arabicObject>`, so a missing key is a type error. Use Western digits.
- **UI building blocks:** reuse `components/ui` (`Modal` with focus trap, `DataView`, `Skeleton`, `Button`, `Badge`, `Card`, `Toggle`), `dialogs.confirm/prompt` (never native `confirm()`), `components/admin/charts.tsx` and the theme tokens.
- **Screen states:** every screen has loading, empty (with the next action), error (with retry) and success states. Every control is labelled and focus is visible.
- **Bidirectional text:** isolate Arabic runs inside English text, and Latin runs inside Arabic text, with `<bdi>`.
- **React keys:** sibling elements never share a React key (a duplicate key was found and fixed on the lesson page on 10 Oct).

### Mobile (`mobile/`, Flutter 3, Riverpod, go_router, Dio)
- Features live in `lib/features/<feature>/`, strings in `lib/core/l10n/strings.dart` (AR + EN; run `python3 scripts/check_dart_keys.py`) and routes in `lib/router.dart`. Trainee-facing features on the web must exist in the app unless the phase says otherwise.
- Flutter is at `~/development/flutter/bin`; run with `export PATH="$HOME/development/flutter/bin:$PATH"`. Interpolate numbers into text as `'${n}'`, never `Text(n)`.

## 4. Status honesty: when an item may be called "Available"

| Situation | Register status | What the evidence must say |
|---|---|---|
| Built, tested, and works in production without anything external | ✅ Available | files, endpoints, screens, tests |
| Built and tested against mocks/fixtures, but needs the Ministry's credentials, specification, files or environment to go live | 🟡 Partial | "**Ready — waiting for:** <exact input>", plus the go-live checklist path |
| Needs a real run that has not happened (load test, VAPT, restore drill, UAT) | 🟡 Partial | "**Not run yet**"; never claim a result |

Every phase lists the exact **Definition of Done (DoD)** for each ID. Mark an item Available only when its DoD is met, and never round up. Use:

```bash
python3 scripts/rfp_mark.py marks.json   # {"ID": ["available"|"partial", "evidence…"]}
python3 scripts/rfp_sync.py              # re-derives counts, the checklist and the 31 mandatory items
cd backend && php artisan tedc:rfp-status   # status.json, compliance sheet, BRD, traceability, test report
```

## 5. Skills: use them (they are installed)

Invoke the matching skill with the Skill tool at the point it applies:

| When | Skill |
|---|---|
| Before writing code for a phase | `writing-plans` (the plan), or the Spec Kit flow in §6 |
| Every behaviour change or bug fix | `test-driven-development`: watch each test fail first |
| Any failure or surprise | `systematic-debugging` |
| Any new or changed screen | `frontend-design` + `impeccable` (polish and accessibility); `emil-design-eng` / `animate` for motion; `mobile-native` for phone layouts |
| Charts, dashboards, report visuals | `dataviz` |
| Word / PDF deliverables | `anthropic-skills:docx`, `anthropic-skills:pdf` |
| Browser automation, screenshots, E2E | `webapp-testing` |
| Security-sensitive phases (22, 23, 25, 26) | `security-review` |
| Before reporting a phase as done | `verification-before-completion`, then `requesting-code-review` |

## 6. Workflow for every phase

1. **Read and plan.** Read the phase file, its IDs in `OPEN_ITEMS.md`, the files it names, and the earlier phase's Limits. Spec Kit is initialised in this repository (`.specify/`), so run the spec-driven flow with the phase file as the requirements: `/speckit.specify` → `/speckit.plan` → `/speckit.tasks` → `/speckit.implement`. Use `/speckit.clarify` only when the phase has a **Decision gate**. If Spec Kit is unavailable, write the plan with `writing-plans` into `docs/superpowers/plans/`.
2. **Build in this order:** migrations and models → services and rules → API and permissions → notifications and jobs → web → mobile → sample data (non-production seeders) → docs.
3. **Test.** Write tests first for every rule, permission, workflow branch and edge case named in the phase's **Review focus**. Add widget tests for new mobile flows.
4. **Verification gate.** Everything must be green before you report:
   ```bash
   cd backend && php artisan migrate && php artisan test && vendor/bin/pint --test
   cd backend && php artisan tedc:data-classification --write && git diff --exit-code ../docs/security/data-classification.md || echo "commit the regenerated register"
   cd web && npm run lint && npm run build
   export PATH="$HOME/development/flutter/bin:$PATH"; cd mobile && flutter analyze && flutter test
   python3 scripts/check_dart_keys.py
   ```
   Then run the app. `.claude/launch.json` (git-ignored, so create it if missing) has `api` (port 8000) and `web` (port 5173); start both with the preview tools:
   ```json
   {"version":"0.0.1","configurations":[
     {"name":"web","runtimeExecutable":"/Users/rabi3o/.local/bin/npm","runtimeArgs":["--prefix","web","run","dev","--","--port","5173"],"port":5173},
     {"name":"api","runtimeExecutable":"/Users/rabi3o/.local/bin/php","runtimeArgs":["backend/artisan","serve","--host","127.0.0.1","--port","8000"],"port":8000}]}
   ``` Click through every new or changed screen as each affected role, in Arabic **and** English, at desktop **and** 375 px width, light **and** dark. Use only the local demo accounts from the seeders, and only on localhost. Check the console for errors. Fix anything broken, misaligned, untranslated or slow.
5. **Update the record.** Write `docs/rfp/phase-NN-<name>.md` (what was built, decisions, **Limits said plainly**), update `docs/API.md` and `CHANGELOG.md`, mark the register (§4), and regenerate (`tedc:rfp-status`, `tedc:deliverables`).
6. **Commit and push**, as the user always asks: `git fetch origin && git rebase origin/claude/intelligent-brahmagupta-7pysbz && git push origin HEAD:claude/intelligent-brahmagupta-7pysbz`. Another session may push to the same branch, so never `git add -A` files you did not write without reading them. Then watch **CI** (web, mobile, backend, backend-postgres, backend-azurite), **Security** (secrets, dependencies, sast, container, iac) and the **Vercel** deployment status (`gh api repos/rabi3ogabes/tedc_managment/commits/<sha>/statuses`). The phase is not done while any of them is red.
7. **Report.** List the IDs closed, with evidence (files, endpoints, screens, tests); IDs still partial and the exact reason; decisions made; and anything the Ministry or the centre must provide. Then wait for the next phase file.

## 7. Safety gates (never cross them)

- **Production data:** never write test data to production. Never run load tests, scans or drills against production (`tedc-managment.vercel.app`, the production Supabase).
- **Cloud resources:** never create or change resources (Azure, Supabase projects, DNS, paid services) without the user's explicit "yes" in chat for that action. Show the cost and scope first.
- **Credentials:** never type real credentials into external services. Never commit secrets. Settings screens store them encrypted.
- **Decision gates:** where a phase says **Decision gate**, stop and ask the user before building that part.
- **Outward actions:** releases, git tags, e-mails to the Ministry and similar are outward-facing. Ask first.

## 8. Phase map

| Phase | File | Closes | Can reach Available by software alone |
|---:|---|---|---|
| 19 | `19_COLLABORATION_COMPLETION.md` | PLC-03★, COL-02, COL-03, COL-04, NTF-09 | all 5 |
| 20 | `20_PLANNING_REPORTS_FORECASTS.md` | NDS-08, RPT-03, RPT-11 | all 3 |
| 21 | `21_LEARNING_ENGINE_COMPLETION.md` | TYP-12, AI-03★, AI-05★, TEC-08★ | TYP-12, AI-03, TEC-08 (AI-05 needs an in-country model endpoint) |
| 22 | `22_EXAM_INTEGRITY_FACE_PRESENCE.md` | EXM-10★ | yes, after the **Decision gate** |
| 23 | `23_IDENTITY_AND_MICROSOFT_365.md` | NFR-07★, NFR-08★, TEC-12★ | NFR-08 (verified against OpenLDAP); NFR-07 / TEC-12 need the Ministry tenant |
| 24 | `24_MINISTRY_SYSTEMS_AND_PROVIDERS.md` | TEC-07★, CAR-05, NTF-03★, PAY-02, ATT-05, CNT-05, TYP-14, TEC-13★, UX-03, UTR-03 | UTR-03 once the centre enters its contacts; the rest are made go-live-ready |
| 25 | `25_SECURITY_ENGINEERING_AND_PERFORMANCE.md` | NFR-15★, NFR-16★, NFR-17★, NFR-18★, TEC-02★, TEC-03★ | NFR-16, NFR-17, NFR-18 |
| 26 | `26_AZURE_GO_LIVE_AND_RESILIENCE.md` | NFR-01★, NFR-02★, NFR-03★, NFR-19★, NFR-20★, TEC-01★, TEC-05★, TEC-16★ | only with an Azure subscription and the user's approval |
| 27 | `27_ADOPTION_CONTENT_AND_DELIVERABLES.md` | UTR-01, EKT-01, NFR-04★, DLV-01..07 | UTR-01, NFR-04, DLV-03, DLV-06 |

Phases 19, 20 and 21 have no external dependency, so run them first. Phase 22 needs your decision. Phases 23–27 build readiness and evidence.

---

## 9. Execute now: the Preflight

**Goal:** a green, deployable baseline and a project constitution, so every later phase starts clean.

### 9.0 ⚠ Live-site security posture (Decision gate: do this first)
Found on 11 Oct 2026, and verified read-only:
- The repository `rabi3ogabes/tedc_managment` is **public**.
- The shared demo password is written in **20 tracked files**: `web/src/pages/public/Login.tsx` (`DEMO_PASSWORD`), `mobile/lib/features/auth/login_screen.dart`, `backend/scripts/vercel-build.php`, `.github/workflows/live-perf.yml` and `mobile-apk.yml`, seeders, tests, `README.md` and the `docs/` deploy guides. It also ships inside the web JavaScript bundle.
- Production (`tedc-managment.vercel.app`) answers `GET /api/v1/public/mobile-config` with `demo_accounts: true`. Its login page therefore offers **one-tap sign-in to every demo account, including the super administrator**, with that password.

**Ask the user, before anything else:** *"Is the live site meant to be a public demonstration?"*
- **If it is a demo only**, keep demo mode, but:
  - make sure outbound channels (SMTP, SMS, push, Teams, payment) are on `log`/fake drivers there, so a visitor cannot e-mail or SMS real people or move money;
  - block destructive settings (identity, integrations, feature flags, user deletion) for demo accounts;
  - show a visible "Demo environment" banner;
  - document all of this.
- **If real data will ever be entered**, these steps are urgent:
  - switch demo mode off (`TEDC_SEED_DEMO`, `TEDC_ALLOW_DEMO_IN_PRODUCTION` = false), and remove `--password=` from `vercel-build.php` so deploys never reset passwords;
  - **the user** rotates every production password, starting with `admin@tedc.qa`; you never type it;
  - turn on MFA for staff;
  - remove the literal password from the web bundle and the app (read it from the server's demo configuration only when demo mode is on), from the workflows (use a GitHub secret) and from the docs (refer to the seeder instead);
  - ask whether to make the repository **private**. Rewriting git history is destructive, so only do it if the user explicitly asks.

Add a test that fails if the literal demo password appears in `web/src` or `mobile/lib`.

### 9.1 Baseline
Run the full verification gate (§6.4) and record the numbers (tests, assertions, skipped) in `docs/rfp/closeout/BASELINE.md`. Fix anything red **before** Phase 19.

### 9.2 Production deploys
On 10 October, Vercel deployments failed for three commits (`a4cff61` onward) while CI was green. On 11 October they recovered (`67ac831` and `2b30bc2` deployed, `/ready` ok), so it was probably a transient platform problem. Confirm the latest status:
`gh api repos/rabi3ogabes/tedc_managment/commits/$(git rev-parse HEAD)/statuses -q '.[] | .context+" "+.state+" "+.description'`.
- If Vercel is still failing, you cannot read its build log: the CLI is not authenticated and the browser is not signed in. **Ask the user to paste the end of the Build Logs** (Vercel → Deployments → the failed one → Build Logs).
- Then reproduce the failing step locally. The PHP build is `backend/scripts/vercel-build.php` (it never fails the build itself). The web build is `VITE_API_URL=/api/v1 npm run build --prefix web`. The function's `excludeFiles` are in `vercel.json`. Fix it, push, and confirm the deployment succeeds and `GET /api/v1/public/health/ready` returns `ok`.

### 9.3 Container exception review
`.trivyignore` holds a dated exception for CVE-2026-78669 (golang.org/x/net 0.59.0 inside FrankenPHP; expires 2026-11-10; mitigated by HTTP/1.1-only serving in `docker/Caddyfile`). Check whether a FrankenPHP release now bundles x/net ≥ 0.60.0 (`gh api repos/php/frankenphp/releases`, then that tag's `go.mod`). If it does:
- bump the image;
- remove the exception and its row in `docs/security/patching.md`;
- restore the default protocols if nothing else needs HTTP/1.1-only.
If it does not, remind the user that the exception needs their **written risk acceptance** (the policy in `docs/security/patching.md`).

### 9.3b Scheduled workflows that cannot succeed yet
`.github/workflows/restore-test.yml` runs on a monthly cron (the 1st, 02:00) and needs the `AZURE_*` secrets and `vars.PROD_PG_ID`, which do not exist yet. Unguarded, it fails on 1 November. Add a first step to every scheduled Azure job that checks the required secrets and vars and ends **successfully with a notice** when they are missing (Phase 26 §1.1 has the details). Check the other scheduled workflows (`keep-warm.yml`, `live-check.yml`, `live-perf.yml`, the weekly Security run) for the same problem.

### 9.4 Spec Kit constitution
`.specify/memory/constitution.md` is still the blank template. Run `/speckit.constitution` with these principles, so every `/speckit.*` command enforces them:
1. Arabic-first bilingual (AR/EN) everywhere, with correct RTL and Western digits.
2. Test-first: every behaviour change starts with a failing test.
3. Least privilege and scope: permission checks on the API and the UI, and every list filtered by the active role and scope.
4. Audit every approval, override, settings change and personal-data export.
5. Integrations only through adapters with fake drivers, health checks and logs.
6. Configurable policy: thresholds and rules editable without code, with safe defaults.
7. Honest status: nothing is claimed "Available" before its Definition of Done is met.
8. Production safety: no tests, scans or drills against production; no cloud changes without approval.
9. Accessible and luxurious UI on the existing theme tokens.
10. PostgreSQL-correct migrations and sample data.

### 9.5 Done when
- The live-site posture is decided and acted on (9.0). The gate is green, `BASELINE.md` is written, Vercel deploys succeed (or the user has been asked for the log), the CVE exception has been reviewed, scheduled workflows are guarded, and the constitution is filled in.
- Report back using the format in §6.7, then wait for **Phase 19**.
