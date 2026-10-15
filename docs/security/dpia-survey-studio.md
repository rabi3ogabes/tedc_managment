# DPIA note — Survey Studio v2 (ستوديو الاستبانات)

**For:** the Ministry's data-protection officer. **Status:** prepared 15 Oct 2026 with Gate 7 of the Survey Studio programme; the engine is behind the feature flag `survey_studio_v2`. **Law:** Qatar Personal Data Privacy Protection Law (Law No. 13 of 2016).

**ملخص:** يجمع محرك الاستبانات إجابات الموظفين وأولياء الأمور في ثلاثة أوضاع للخصوصية (معرّف، سري باسم مستعار، مجهول)، ولا يحتفظ بأي هوية في الوضعين الأخيرين. تُعرض النتائج مجمّعة ولا تُعرض شريحة أصغر من حد الإظهار. تُحذف المسودات والدعوات ومفتاح الأسماء المستعارة ثم الإجابات نفسها تلقائياً وفق مدد الحفظ، وتبقى الإحصاءات المجمّعة. يملك كل شخص الاطلاع على إجاباته المعرّفة وطلب فصل هويته عنها.

## 1. What is processed, and in which mode
| Mode | What a submission carries | One answer per person enforced by | Who can see who answered |
|---|---|---|---|
| **identified** | the user id and the segment fields the author chose (school, job title, stage, specialisation, nationality, gender, experience band, qualification) | the user id | only holders of `surveys.results.identified` |
| **confidential** | an HMAC pseudonym made with a per-survey key, and only the chosen segment fields; never the user id | the pseudonym | nobody; the key is deleted after close + grace, after which the pseudonyms match nothing |
| **anonymous** | nothing about the person | the participation registry, which is never joined to the answers | nobody |
| **public link / invitation** (Gate 4) | nothing about the person; a pseudonymous key from the device id, a verified e-mail or the invitation token | that key, when the author's duplicate rule asks for it | nobody; e-mail addresses used for codes are never stored with an answer |

Evaluation answers (Gate 5) keep the assignment they belong to as their context: an assignment is addressed to a named person by nature, and "anonymous" there means names are hidden in results.

## 2. Purpose and lawful basis
* Training-needs analysis, programme evaluation and satisfaction measurement within the Ministry's mandate; the employment relationship for staff, and consent (a visible welcome screen stating the privacy mode and the retention period) for public respondents.
* Every respondent sees, before answering: the number of questions, the expected time, the privacy mode in plain words, and how long the answers are kept.

## 3. Minimisation and disclosure controls
* Segment fields are chosen per survey; confidential surveys never store the user id; anonymous ones store nothing.
* Results, individual answers and exports hide any segment smaller than the survey's threshold (default 5, at least 2) unless the viewer may see identified answers of an identified survey. Segment values are only offered as filters when at least the threshold of answers stand behind them.
* Exports are audited (format, rows, whether identities were included, filters). Identities appear in exports only for identified surveys and holders of the identified permission.
* Open-text answers are shown as samples and are never sent to an external AI unless the survey opts in (Gate 8; opt-in applies only to anonymous or pseudonymised text).

## 4. Retention (Gate 7), applied daily by `tedc:surveys-retention`
| Data | Kept | Then |
|---|---|---|
| Autosaved drafts and uploaded files not yet bound to an answer | 30 days | deleted |
| Personal invitations (e-mail address, token) | 90 days after the survey closes | deleted |
| Pseudonym key of a confidential survey | 30 days after close | deleted; drafts under pseudonymous keys go with it; answers can never be matched to a person again |
| Answers, files, participation rows, invitations | 36 months after close (per survey, editable from 30 days) | deleted; the summary and per-question statistics are frozen on the survey and remain |
| Audit and survey events | with the platform's audit log | — |

A **legal hold** on a survey stops the deletion of its answers until lifted; the hold, the deletions and the key removals are recorded as survey events, audit rows and security events (SIEM).

## 5. Rights of the person
* **Access:** the "my data" download includes the surveys the person was asked to answer and, for identified surveys, their answers by question title; it states that confidential and anonymous answers cannot be linked to them.
* **Erasure:** the privacy handlers detach the person from every identified answer (the answer stays, anonymous, so statistics hold), delete their files, drafts and participation rows; recorded per survey and in the audit log. Confidential and anonymous answers need no erasure: they are not personal data of an identifiable person once the key is gone.
* Requests go through the existing data-subject queue (30-day deadline, reminders).

## 6. Residual risks and decisions for the Ministry
* Small identified surveys may still single out a person to viewers with the identified permission; grant it sparingly (today: system administrators only).
* The production database is Supabase (Singapore) for the pilot; the approved plan moves it to Azure before go-live.
* Whether aggregated statistics kept after retention should themselves expire (currently kept indefinitely).
