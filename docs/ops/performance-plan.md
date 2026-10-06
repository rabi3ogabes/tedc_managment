# Performance plan and report

**ملخص:** خطة اختبار الأحمال حتى 10,000 مستخدم متزامن بزمن استجابة p95 أقل من 1.5 ثانية. **لم يُنفَّذ الاختبار بعد**: يحتاج بيئة تجهيز على Azure. هذا المستند هو القالب الذي ستُسجَّل فيه النتائج.

**Status: NOT YET EXECUTED.** The scripts exist (`perf/`), the pipeline runs a one-minute smoke test per release, and the full test needs the staging environment at scale. No result below may be quoted until it is measured.

## Method
`perf/scenarios.js`: browse (50 %), signed-in portal (35 %), registration burst, 08:00 QR check-in burst, exams (10 % of peak), ramp 20 min, hold 30 min. Thresholds p95 < 1.5 s, errors < 0.1 %. Run on staging at 20 % of production capacity (`PEAK=2000`), then extrapolate using the per-replica throughput measured, and confirm once on a production-sized staging window if the Ministry agrees to the cost.

## Optimisations already in place
Edge caching headers for public catalogue, cached permissions and dashboards, compact JSON, pagination caps, queue offloading for notifications/exports/reports, signed direct-to-Blob transfers for large files, SSE tier separate from the API.

## Results (to fill)
| Date | Env | Peak VUs | p95 | Error rate | Replicas | DB CPU | Verdict |
|---|---|---|---|---|---|---|---|
| — | — | — | — | — | — | — | — |
