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
