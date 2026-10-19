# 09 — The programme page as a board of widgets

**Request.** Besides the tabs, the administrator can switch the programme page to a second view: every tab becomes a widget on one page, and pressing a widget opens it in a popup where its options are edited. "Edit programme" is available in that view too.

## What was built

| Piece | Where |
|---|---|
| View switch — **تبويبات / ودجات** (segmented control, `aria-pressed`) | `web/src/pages/admin/ProgramManage.tsx` |
| Section list shared by both views (`SectionDef`: id, label, icon, render) — the same permission conditions as the tabs, so a widget appears only when its tab would | `ProgramManage.tsx` |
| Widget board: one card per section with icon, name, one-line description and up to two live numbers | `web/src/pages/admin/program/ProgramWidgets.tsx` |
| Popup: opens the section's own screen, ready to edit; header with «فتح كتبويب» and close | `ProgramWidgets.tsx` (`WidgetPopup`) |
| **بيانات البرنامج** widget (widget view only): the full edit form embedded, with its own Save | `ProgramEditor.tsx` (`embedded`, `onSaved`) |
| Numbers for the cards: `GET admin/programs/{program}/overview` — counts only, a handful of cheap queries, permission `programs.view\|programs.manage` | `ProgramOverviewController` |
| Strings | `admin.programs.widgets.*` in `ar.ts` / `en.ts` |

## Behaviour

- The chosen view is remembered per browser (`tedc.program.view`) and can be linked: `?view=widgets&open=<section>` opens the board with that widget already open.
- A widget's content is fetched only when it is opened; the board itself needs only the one overview request.
- The popup has no blur or transform, so windows a section opens itself (add trainees, dialogs) sit above it correctly. **Esc** closes only the top-most window; a click on the backdrop does **not** close (a form may be half filled). Page scrolling is locked while it is open and focus returns to the card on close.
- Closing a popup refreshes the board's numbers and the programme header, so what was changed shows at once.
- «فتح كتبويب» switches to the tabs view on that section.
- The edit-programme widget saves in place (no navigation); the normal edit page and the new-programme page are unchanged.

## Tests and checks

- `ProgramOverviewTest` — numbers for participants and groups, every widget key present, an employee is refused.
- `tsc`, `oxlint` (no new warnings), `pint` clean.
- Checked in the browser (Playwright): toggle, board with numbers, popup, nested dialogs, embedded editor, Esc behaviour.

## Not done (not requested)

Reordering or hiding widgets per administrator, and keeping the preferred view on the server per user rather than per browser.

**Full suite:** 1203 tests, 1132 passed; the 5 failures and 54 errors are the known set that existed before this change (VercelEnvTest ×2, ContractDriverTest mTLS, IntegrationDocsTest line endings, SocialFilesTest dangerous files, openssl errors) — none touches the programme page.
