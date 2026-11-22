# صلاحياتي ومسؤولياتي — the charter of the roles

2026-11-16. The Ministry's roles document («الصلاحيات»: one table of twelve roles, each with its الصلاحيات and its المسؤوليات) is now
part of the system: every person sees, on the main page of their dashboard, what the role they are acting in may do and what it
answers for, **in the words of the document**, and every role holds in the system the rights those words need.

## What a person sees

- A card «صلاحياتي ومسؤولياتي» at the top of the dashboard (`/admin`) and of the trainee portal (`/portal`), with the name of the role as
  the document writes it, and two tabs: **الصلاحيات** and **المسؤوليات**. Each heading of the document is a small panel with its lines.
- The text is the document's own, so it is shown in Arabic in both languages of the interface.
- A heading that has a page in the system carries «افتح», which opens that page. The link is shown only to a role that may open the page.
- «إخفاء» folds the card; the choice is remembered on that browser.
- A person with several roles sees the charter of the role they are acting in, and another one after switching roles.
- The dashboard is now in the menu of every role of the admin area. A role without «عرض لوحة التحكم» (a trainer, a kit developer) sees its
  charter and its own widgets there, not the centre-wide figures.

## The roles

| The document | Role of the system | Headings (الصلاحيات / المسؤوليات) | Lines |
| --- | --- | --- | --- |
| المتدرب | `employee` | 11 / 6 | 22 |
| مدير المدرسة | `school_admin` | 2 / 2 | 6 |
| مسؤول التطوير المهني (النائب الأكاديمي) | `academic_deputy` | 6 / 5 | 21 |
| رئيس قسم التدريب | `training_head` | 4 / 3 | 13 |
| مشرف التدريب (قسم التدريب التربوي) | `program_coordinator` | 6 / 6 | 28 |
| قيادات المركز وواضعو السياسات | `center_leadership` | 4 / 5 | 15 |
| المدربون | `trainer` | 4 / 4 | 11 |
| مسؤول النظام | `super_admin` | 4 / 4 | 24 |
| معد الحقيبة | `kit_developer` | 3 / 3 | 8 |
| رئيس قسم التخطيط | `planning_head` | 5 / 4 | 15 |
| أخصائي التخطيط | `planning_specialist` | 3 / 5 | 9 |
| مسؤول الدعم اللوجستي | `logistics_officer` | 3 / 4 | 7 |

Roles of the system that the document does not describe have no card: `center_admin`, `executive`, `qa_reviewer`,
`finance_officer`, and any custom role.

**«مشرف» (`supervisor`) takes the charter of «مشرف التدريب (قسم التدريب التربوي)»** (decided by the centre on 2026-11-22;
`RoleCharter::SHARED`). The document has no role of that name at school level.

* **The card** shows the training supervisor's text under the supervisor's own role.
* **The rights**: the role holds what that text rests on (`attendance.manage`, `leaves.manage`, `announcements.manage`,
  `materials.manage`, `trainers.assign`, `saber.trainer.assign`, `kits.review`, `kits.publish`, `kits.manage`, `dashboard.view`,
  `reports.view`, `live_training.view`) and the right each heading's page asks for (`programs.view`, `saber.view`, `kits.view`). It
  keeps every right it had. It does not become the training supervisor: rights of that role that the charter does not name
  (`programs.manage`, for one) are not given.
* **These rights are not limited to the person's own school.** They are rights of the centre: attendance and absences of every
  programme, announcements, assigning trainers, materials, reviewing and publishing training kits, and the live training board with
  the names of the trainees in each room. The centre confirmed this before the change. The per-programme grants of
  `ProgramGrant` (attendance, notifications, task review for one programme) still exist for other roles; for «مشرف» the
  notifications one is now covered by the role.
* **On a database that already has its roles** the rights are given once by the migration `2026_01_01_009400_supervisor_charter_rights`;
  a new database gets them from `RolePermissionSeeder`. An administrator can still remove any of them in Settings → Roles & permissions.
* **Where he finds the pages**: the «الإشراف» section of the portal menu lists them (الغياب، البرامج، سابر+، الحقائب التدريبية،
  التقارير، متابعة التدريب المباشرة), each for whoever holds the right its page asks for, and the headings of the card link to them.

## What each heading rests on

For each heading of «الصلاحيات»: the rights of the system the role must hold for it to be true, and the page «افتح» goes to. An empty
rights cell is a person's own record (the trainee portal) or something the system does by itself (a notification that arrives).
`RoleCharterTest` fails the build if a role is not seeded with a right its charter names, or if a page offered to a role needs a right
the role does not hold.

### المتدرب — `employee`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| التسجيل في البرامج التدريبية | — | `/programs` |
| الاطلاع على الجداول | — | `/calendar` |
| استقبال التنبيهات | — | `/portal/notifications` |
| توثيق نقل المعرفة | — | `/portal/growth` |
| إضافة مجالات التطوير المهني | — | `/portal/growth` |
| حضور البرامج والدورات | — | `/portal/training` |
| الوصول للمواد التدريبية | — | `/portal/training` |
| التواصل والتفاعل | — | `/portal/communities` |
| الإجابة على الاستبانات | — | `/portal/surveys` |
| استخراج الشهادات والتقارير | — | `/portal/certificates` |
| طلب إضافة دورات تدريبية | — | `/portal/growth` |

### مدير المدرسة — `school_admin`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| التقارير واللوحات المعلوماتية | `dashboard.view`, `reports.view` | `/admin/reports` |
| التفاعل مع الإشعارات والتنبيهات | — | — |

### مسؤول التطوير المهني (النائب الأكاديمي) — `academic_deputy`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| الاطلاع والتسجيل | `calendar.view`, `nominations.school` | `/admin/calendar` |
| اعتماد الطلبات | `pd.approve`, `registrations.approve_manager` | `/admin/approvals` |
| الترشيح | `nominations.school` | `/admin/registrations` |
| التقارير واللوحات المعلوماتية | `dashboard.view`, `reports.view` | `/admin/reports` |
| الإشعارات | — | — |
| إدارة الورش التدريبية الداخلية | `workshops.internal`, `plc.internal.submit`, `plc.attendance.manage` | `/admin/internal-workshops` |

### رئيس قسم التدريب — `training_head`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| تكليف وإسناد المهام | `saber.supervisor.assign`, `trainers.assign` | `/admin/saber` |
| إدارة الحضور والإشعارات | `program_grants.manage` | `/admin/programs` |
| الوصول للبيانات والتقارير | `dashboard.view`, `analytics.view`, `reports.view` | `/admin/reports` |
| اتخاذ الإجراءات التصحيحية | `attendance.manage` | `/admin/absence` |

### مشرف التدريب (قسم التدريب التربوي) — `program_coordinator`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| الحضور والإشعارات | `attendance.manage`, `leaves.manage`, `announcements.manage` | `/admin/absence` |
| إدارة الأنشطة والمواد | `materials.manage` | `/admin/programs` |
| التكليف والإسناد | `trainers.assign`, `saber.trainer.assign` | `/admin/saber` |
| إدارة الحقائب التدريبية | `kits.review`, `kits.publish`, `kits.manage` | `/admin/kits` |
| التقارير واللوحات المعلوماتية | `dashboard.view`, `reports.view` | `/admin/reports` |
| مراقبة البرامج التدريبية | `dashboard.view`, `live_training.view` | `/admin/live-training` |

### قيادات المركز وواضعو السياسات — `center_leadership`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| لوحة معلوماتية (Dashboard) متقدمة | `analytics.executive`, `dashboard.view` | `/admin/analytics` |
| استقبال الإشعارات والتنبيهات | — | — |
| التقارير والإحصائيات | `reports.view` | `/admin/reports` |
| اعتماد تكليف المدربين | `trainers.approve` | `/admin/trainers` |

### المدربون — `trainer`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| إدارة الحضور والانصراف | `attendance.manage` | `/admin/teaching` |
| إدارة التقييم | `assessments.grade`, `tasks.review` | `/admin/teaching` |
| اللوحة المعلوماتية والتقارير | — | `/admin/reports` |
| إدارة البيانات الشخصية | — | `/admin/profile` |

### مسؤول النظام — `super_admin`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| إدارة البرامج التدريبية | `programs.manage`, `banks.manage`, `assessments.manage`, `passing.manage` | `/admin/programs` |
| إدارة الصلاحيات والإشعارات | `roles.manage`, `users.manage`, `program_grants.manage`, `announcements.manage` | `/admin/settings` |
| إدارة الاستثناءات | `pass_exceptions.grant` | `/admin/passing-policy` |
| الاستعلام والتقارير واللوحات المعلوماتية | `reports.view`, `dashboard.view`, `search.global` | `/admin/reports` |

### معد الحقيبة — `kit_developer`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| إدارة الحقائب التدريبية | `kits.manage` | `/admin/kits` |
| إرسال ومتابعة الاعتماد | `kits.manage` | `/admin/kits` |
| لوحة البيانات والتقارير | `kits.view` | `/admin/reports` |

### رئيس قسم التخطيط — `planning_head`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| الخطة التدريبية السنوية | `plans.approve`, `plans.manage` | `/admin/plans` |
| أدوات ونماذج الحصر | `instruments.approve` | `/admin/needs-hub` |
| توزيع المهام وتفويض الصلاحيات | — | — |
| البرامج المعتمدة | `plans.approve` | `/admin/plans` |
| متابعة الأداء | `dashboard.view` | — |

### أخصائي التخطيط — `planning_specialist`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| إدارة الاحتياجات والتقييم | `needs.manage`, `evaluations.respond_planning` | `/admin/needs-hub` |
| إدارة البيانات والتواصل | `evaluation_reports.prepare` | `/admin/satisfaction` |
| متابعة الأداء | `dashboard.view` | — |

### مسؤول الدعم اللوجستي — `logistics_officer`

| الصلاحية (heading) | Rights | Page |
| --- | --- | --- |
| إدارة بيانات القاعات | `rooms.manage` | `/admin/rooms` |
| إدارة الحجوزات | `rooms.book` | `/admin/room-ops` |
| لوحة البيانات والتقارير | `dashboard.view` | — |

## What changed in the system

- **Rights.** The seeded rights of the twelve roles already covered every heading of the document. A one-time step
  (`2026_01_01_009300_role_charter_rights`, `RoleCharter::grantMissing`) gives each role of an existing database any of these rights it
  lacks (for example one an administrator had unticked), creates a right the database does not have yet, and writes what it added to the
  application log. It takes nothing away from any role.
- **Dashboards.** The document gives the kit developer, the head of planning and the planning specialist a dashboard that «تظهر الحقائب
  التدريبية والبرامج المرتبطة بها». A new widget, «الحقائب التدريبية والبرامج المرتبطة بها» (`kits_programs`), is in the dashboard of those
  three roles: the kit, its state and the programmes it is linked to. A kit developer sees the kits they own or made; the planning roles
  see every kit in use; nobody sees the content of a kit through it. A role whose dashboard was rearranged by an administrator
  (Dashboard presets) keeps that arrangement; add the widget there.
- **API.** `GET /api/v1/dashboard/charter` returns the charter of the active role, or `null`.

## Left as it is, on purpose

- **Rights beyond the document.** Several roles hold more rights than the document lists (the system does more than the table
  describes: SABER+, surveys, learning communities, finance …). None was removed. Settings → Roles & permissions shows and edits them.
- **«إذا منح الصلاحية».** The document lets the training supervisor and the trainer record attendance and send notifications *if granted*.
  In the system both roles hold «إدارة الحضور» as a right of the role, and the head of training can also grant it per programme
  (Programme → Rights). Making it per programme only would mean taking the right away from the two roles: a decision for the centre.

## Keeping it in step with the document

`backend/resources/roles/charter.json` is generated, never edited by hand:

```bash
python scripts/roles-charter/charter_build.py "<path to الصلاحيات.docx>" backend/resources/roles/charter.json
```

`charter_parse.py` reads the table of the document (a bold paragraph is a heading, the paragraphs under it are its lines);
`charter_build.py` adds, per heading, the rights and the page (`META`, at the top of the file). After a change of the document, update
`META` if headings were added or removed, regenerate, and update the three counts in `RoleCharterTest`.
