# The version shown in the footer

Every page of the platform ends with a small line — «الإصدار 0.9.2 · آخر تحديث 20 أكتوبر 2026، 11:20 م» / "Version 0.9.2 · Last updated 20 October 2026, 11:20 pm": the signed-in pages (administration and the trainee portal) at the bottom of the content, the public site in the footer bar. The time is Qatar time (UTC+3) whatever the visitor's clock says.

It is read from one file, `web/src/lib/version.ts` (`APP_VERSION`, `APP_UPDATED_AT`).

## With every change that ships

```bash
cd web
npm run stamp            # 0.9.1 → 0.9.2 and the current date/time
npm run stamp -- minor   # 0.9.2 → 0.10.0 (a new feature set)
```

Run it before the commit of the change, and commit `version.ts` with it. The script only edits `src/lib/version.ts`.
