# حزمة المخرجات · Deliverables pack

Each document has an **Arabic** part first and an **English copy** below it, a version table and an approval block. Sources are Markdown; `php artisan tedc:deliverables --format=docx|pdf` builds editable Word files (styles ready for tracked changes) and PDFs into `storage/app/deliverables/`.

| # | Document | File | Status |
|---|---|---|---|
| DLV-01 | As-Is / To-Be process analysis | `01-as-is-to-be.md` | draft — As-Is items marked ⚑ need workshop confirmation |
| DLV-02 | Needs assessment, scope, project plan | `02-needs-scope-plan.md` | draft |
| DLV-02 | Business requirements document (BRD) | `brd.generated.md` | **generated** from the gap register by `tedc:deliverables` |
| DLV-03 | UX design and architecture | `03-ux-and-architecture.md` | draft |
| DLV-04 | Release management (Alpha/Beta/Final) | `04-release-management.md`, `../../CHANGELOG.md` | draft |
| DLV-05 | Training & adoption plan; user manuals | `05-training-adoption-plan.md`; manuals = Help centre PDFs per role | draft |
| DLV-06 | Test plan, reports, bug tracker | `06-test-plan.md`, `test-report.generated.md` | draft + generated |
| DLV-07 | Go-live, handover, QA certificate, SLA | `07-go-live-handover-sla.md` | draft |
| — | RFP compliance sheet; traceability matrix | `../rfp/compliance-sheet.md`; `traceability.generated.md` | generated |

**Version table (all documents)** — v0.1 draft prepared by the delivery team; v1.0 after the centre's review; approval: ______ (Centre) / ______ (Supplier) / date.
