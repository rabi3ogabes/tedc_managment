# Phase 15 — Artificial intelligence: recommendations, smart feedback, adaptive learning, forecasts and the trainee assistant

Closes: AI-01, AI-02, AI-04 (available); AI-03, AI-05, RPT-11 (partly — see limits). Everything sits behind the `ai` feature flag (on by default; Settings → Features) and the per-feature switches in **Settings → AI & privacy**.

## Principles built into every call (`App\Ai\AiGuard`)

- **Residency.** Each connection carries `data_residency` (`qatar | approved | external`); the new `azure` driver (Azure OpenAI: deployment path, `api-key` header, API version) defaults to *approved*. With enforcement on (default), a feature that sends personal data is **blocked** from an `external` connection unless the administrator explicitly allows it for that feature. A blocked or failed call returns nothing and the caller uses its rule-based behaviour.
- **Redaction.** E-mails, phone numbers, Qatar ID numbers, long numbers and the names given by the caller are replaced by tokens before the prompt leaves (`Redactor`) and put back in the answer.
- **Logs.** `ai_logs` keeps feature, model, residency, sizes, timings, outcome and number of redactions — never the text. Retention is configurable (default 30 days); `tedc:ai-nightly` prunes.
- **Control.** Every output shows why and can be dismissed; nothing that changes a record (grade, plan) happens without a person.

## Features

1. **Recommendations (AI-01)** — `HybridRecommender` re-ranks the rule engine's eligible programmes with colleagues who completed the same programmes (item–item cosine similarity, `tedc:ai-nightly`), the person's own behaviour (categories completed or clicked), ratings from the same job title, and calendar overlap with registered programmes. Weights are editable; A/B toggle compares with the rule engine alone; *shown / clicked / enrolled / dismissed / liked* are recorded (an enrolment after a recommendation counts as a conversion); a dismissed programme is hidden for 30 days. `GET /me/recommendations` (and the portal home) return the explained list; with AI off they return the rule engine's result unchanged.
2. **Smart assessment feedback (AI-02)** — objective questions: your choice, the right answer, explanation, how many colleagues made the same mistake, the competency, and lessons to review (retrieval into the course); shown only as far as the assessment's feedback rules allow. Essays: a draft per question for the grader (`ai_feedback_drafts`) with rubric criteria and a suggested score — from the model when allowed, otherwise from keyword coverage of each criterion. The grader accepts, edits or rejects; the grade is then written through the normal grading path. A draft **never** becomes a grade by itself (AI scoring of practice tests is deliberately not enabled).
3. **Adaptive learning (AI-03)** — mastery per competency (`learner_mastery`) is updated from every graded assessment (moving average). Trainers set rules per programme: *skip a module* when mastery ≥ threshold (the module's lessons are recorded as completed and the event is audited) or *add a remedial lesson* when mastery < threshold (shown in the learner's personal path with the reason). Remedial lessons can be drafted by the model from the course material; they are created as **draft** lessons marked AI-made and reach learners only when the trainer publishes them.
4. **Forecasts and risks (AI-04, RPT-11)** — next-year demand per competency, job title and school from five years of needs (Holt exponential smoothing with a damped trend; a range from past error; confidence from history length and range width); licence expiries add to the job titles concerned. Risk flags with reasons: hours shortfall (projected at the current pace), licence expiring while hours are short, groups starting within 45 days with under 40 % of seats, programmes with low satisfaction that run again. Flags clear themselves and are kept as history. "Add to plan" creates suggested items (`source = forecast`, with confidence and range) through the normal plan service — draft plans only.
5. **Trainee assistant (AI-05)** — retrieval over published lessons (visible to people registered in the programme), programme descriptions and library items for everyone (`embeddings`), plus tools that read only the asker's own schedule, progress, certificates and professional-development hours. Answers cite their sources; questions unrelated to training are declined; when unsure it offers to hand the question to the trainer (an *Ask the trainer* question) or to support (a Saaed ticket). Re-indexing happens when content is published or changed (`tedc:ai-nightly` and *Settings → AI → Re-index* rebuild everything). Streaming is server-sent events.

## Permissions

`ai.settings` (admins), `ai.forecasts.view` (planning, training head, leadership, executives, supervisors), `ai.feedback.review` (trainers and training team), `adaptive.manage` (trainers and training team).

## Known limits

- Retrieval vectors are computed locally (hashed Arabic/English words and word pairs, 256 dimensions) and stored as JSON with cosine similarity in PHP; PostgreSQL `pgvector` and a neural embeddings model are not enabled. This is fine for thousands of chunks and keeps all text in-country; swap the embedder for Azure OpenAI embeddings later if recall needs it.
- Without a connected text model the assistant quotes the best passages instead of writing an answer, and essay drafts use keyword coverage. Azure OpenAI and the other adapters were exercised against a mock only — they have not been run against a real endpoint.
- The model's streamed output is not streamed from the provider: the full answer is generated, then sent in pieces.
- Adaptive paths do not yet change the difficulty of practice question draws; remedial lessons are *recommended* in the path rather than hidden from everyone else.
- Calendar-aware recommendations consider overlap with the person's registered programmes, not their personal calendar. Library items are retrieved by the assistant but not recommended.
- Forecast and risk exports (Excel / PDF) are not built; the pages and the plan hand-off are.
- Forecast quality depends on how many years of needs history the platform holds; with one or two years the model is a simple trend and says so.
