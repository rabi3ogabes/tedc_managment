# Programmes — codes from the type, and the catalogue in the settings

Date: 2026-10-18 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [01-voice-order-and-arabic-first.md](01-voice-order-and-arabic-first.md).

The programme code is no longer typed: a new programme is stamped with the prefix of its type and a running number (LIC-00001 for licensing, PRO-00001 for professionalisation, EMP-, SPE-, ELE-, and PRG- when a programme has no type), the form shows the next code live as the type changes, and a lock lets an administrator type a code by hand when they must. A new settings page, "أنواع البرامج ورموزها", lets the administrator rename the five programme types in both languages, change each one's prefix, hide a type from the pickers, set the code format (default prefix, digits, separator) and rename the three delivery modes (حضوري، عن بُعد، مدمج); the mode names reach every screen at once through the interface labels.

## 1. Files changed

New (backend)
- `app/Services/ProgramCatalogSettings.php` — the setting `program_catalog`: per type name, hint, prefix and enabled flag (ids fixed: the annual plan, the eligibility rules and the mobile app key on them); per mode the two names; the code format; `nextCode(type)` (the prefix, the separator, the highest number used so far plus one, deleted programmes keep their numbers); `update()` (prefixes two to five Latin letters and unique across types and the default; mode names written as label overrides, removed when back to the default); `overview()` for the page (next code and programme count per type).
- `app/Http/Controllers/Api/V1/Admin/ProgramCatalogSettingsController.php` — `GET / PUT admin/settings/program-catalog` (`settings.manage`).
- `tests/Feature/ProgramCatalogTest.php`.

Changed (backend)
- `ProgramTypes::catalogue()` now reads the settings (names, hints, prefix, enabled) over the built-in `baseCatalogue()`, so `admin/program-types`, the plan studio, the exports and the reports show the administrator's names.
- `ProgramController` — `code` optional on create and stamped from the type (or the category's type, or the default prefix); `GET admin/programs/next-code?program_type=…` for the form; the English fallback also applies when the English title is absent.
- `ProgramPlanner::nextCode()` and the voice assistant stamp codes the same way; `lang/{ar,en}/messages.php` (`program_catalog.prefix_taken`); routes.

New / changed (web)
- `src/pages/admin/ProgramCatalogSettings.tsx` — the settings page: a card per type (prefix badge, next code, how many programmes, offered switch, names and descriptions in both languages, the prefix with its rule and duplicate check), the code format card with live examples, the delivery modes card; registered in the settings workspace (`programcatalog`, training group).
- `ProgramEditor.tsx` — the code field is read-only and follows the type (fetched from the server as the type or category changes), with a lock to type a code by hand; the type picker lists offered types only (plus the saved one); `plans/programTypes.ts`, `i18n/ar.ts`, `en.ts`, `management.ts`.

## 2. What the administrator can do now

- Create a programme without thinking about its code: pick the type and read the code that will be stamped; unlock the field only when a specific code is required.
- Rename "ترخيص" or any type, give it the prefix the centre uses, hide a type the centre does not offer, choose five or four digits and the separator, and rename the delivery modes, all from one settings page with live examples.

## 3. Tests executed and actual results

- `php artisan test --filter="ProgramCatalogTest"` → passed, 1 test, 36 assertions: the defaults (five prefixes, LIC-00001 and PRG-00001 next, the mode names); a trainer is refused; the next-code preview, a licensing programme created without a code gets LIC-00001 and the preview moves to LIC-00002, a professionalisation one gets PRO-00001, a category's type gives EMP-00001, no type gives PRG-00001, an explicit code is kept, a deleted programme keeps its number; renaming a type, changing its prefix to LC, hiding electives, four digits, a GEN default and a renamed online mode are reflected by the types endpoint, the next code (LC-0001) and the public labels, while unchanged names add no override; a prefix shared by two types, a prefix with a digit, and a default prefix equal to a type's are refused.
- With `ProgramVoiceAssistantTest` → 4 tests, 92 assertions, all passing.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing effect warnings.
- Playwright sweep in Arabic against the local servers: the settings page and the new-programme form with the stamped code after choosing the licensing type.

## 4. Security-sensitive changes

- The settings need `settings.manage`; prefixes are validated server-side (letters only, unique); the code's uniqueness stays enforced by the database.

## 5. Known limitations

- Changing a prefix later does not renumber existing programmes (their codes are identifiers on certificates and reports); new programmes take the new prefix and start from the highest number already used under it.
- The type ids and the mode ids are fixed by design; adding a sixth type would need a change to the annual-plan rules.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-18): 1178 tests, 1107 passed, 16077 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted in the previous report; every programme, catalogue, plan-rules and PLC test passes.
