# Third-party register

**ملخص:** كل خدمة خارجية، الغرض منها، البيانات المشاركة، مكان المعالجة، حالة الاتفاقية، التقييم، ومفتاح الإيقاف.

| Service | Purpose | Data shared | Residency | Contract / DPA | Risk | Mitigation | Kill-switch |
|---|---|---|---|---|---|---|---|
| Microsoft Azure (Qatar Central) | hosting | all platform data | Qatar | Ministry enterprise agreement | Low | private networking, CMK option | n/a |
| Microsoft Entra ID / Graph | SSO, Teams meetings | names, e-mails, meeting metadata | Microsoft tenant of the Ministry | tenant terms | Low | least-privilege app permissions | Integrations → entra / teams off |
| Firebase Cloud Messaging | Android/iOS push | device token, notification **titles and ids only** | Google (outside Qatar) | Google terms — **DPA to be confirmed** | Medium | no personal content in payloads | Settings → Notifications off |
| AI providers (Azure OpenAI; optional others) | Phase 15 features | redacted prompts | per connection `data_residency` | per provider — **to be confirmed** | Medium | residency guard, redaction, per-feature switch, no prompt storage | Settings → AI & privacy |
| Hudhud SMS | SMS | phone number, message text | Qatar (assumed) | Ministry — **to be confirmed** | Low | templates without sensitive data | Channels → SMS off |
| Ministry e-payment gateway | payments | order number, amount | Qatar | Ministry | Low | hosted page, no card data | `payments` flag |
| Saaed, Sijil, NSIS, QNEDS, HR/Mawared | Ministry integrations | per integration | Ministry networks | internal | Low | circuit breakers, signed messages | Integrations hub per system |
| Supabase (optional drivers) | legacy storage/auth/realtime | files, auth data | outside Qatar | **not used in production on Azure** | n/a | disabled by configuration | `TEDC_*` drivers |
| Content providers (SCORM/H5P/LTI tools) | learning content | launch context, learner id | per provider | per provider | Medium | LTI privacy settings per tool | tool `is_active` |
| Google MediaPipe Tasks Vision `@mediapipe/tasks-vision` 1.0.1 and the BlazeFace short-range model (`blaze_face_short_range.tflite`, SHA-256 `b4578f35940bf5a1a655214a1cce5cab13eba73c1297cd78e1a04c2380b0152f`) — Apache-2.0 | the camera presence check in remote exams (EXM-10) | **none**: the library and the model are served from TEDC's own origin (`/vendor/mediapipe/`) and run in the learner's browser; no frame or face data reaches Google or TEDC | in the learner's browser | Apache-2.0 open-source licence (no service contract) | Low | pinned versions, no CDN at run time, worker isolation, debounced events only | per assessment `proctoring.face_check`; feature `proctoring` |

## Open-source components bundled for offline and exam features

| Component | Version | Licence | Used for |
|---|---|---|---|
| `@mediapipe/tasks-vision` (WebAssembly runtime, module-worker build) | 1.0.1 | Apache-2.0 | face presence detection in the exam page's Web Worker |
| BlazeFace short-range model | float16 v1 | Apache-2.0 | the face detector's model |
| `cryptography` (Dart) | 2.9.0 | Apache-2.0 | AES-256-GCM of offline content in the app |
| `pdfx` (Flutter) | 2.11.0 | MIT | PDF slides in the app |
| `connectivity_plus` (Flutter) | 7.3.1 | BSD-3-Clause | Wi-Fi only downloads and sync on reconnect |
| `@playwright/test` (development only) | 1.63.0 | Apache-2.0 | browser smoke tests (never shipped) |
