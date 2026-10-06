# Bill of materials, sizing and growth

**ملخص:** قائمة الموارد والمواصفات المقترحة وحساب السعة للوصول إلى 10,000 مستخدم متزامن مع نمو 20–30٪ سنويًا. الأرقام مقترحة وتُعتمد بعد اختبار الأحمال على بيئة التجهيز.

> Prices are not included: they depend on the Ministry's enterprise agreement. SKUs must be confirmed as available in Qatar Central.

## Production bill of materials
| Resource | SKU / size | Qty | Notes |
|---|---|---|---|
| Application Gateway WAF v2 | autoscale 2–20 | 1 | zones 1-3, OWASP 3.2 + bot rules |
| Public IP (gateway), NAT IP | Standard, zonal | 2 | DDoS Protection plan attached to the VNet |
| Virtual network + NSGs + NAT gateway | — | 1 | spoke; optional hub peering |
| Container Apps environment | zone-redundant, Consumption profile (dedicated profile if sustained load) | 1 | |
| api | 1 vCPU / 2 GiB | 2–40 | scale 80 req/replica |
| worker | 1 vCPU / 2 GiB | 2–40 | |
| scheduler | 1 vCPU / 2 GiB | 1 | |
| realtime (SSE) | 1 vCPU / 2 GiB | 2–40 | 400 connections/replica |
| PostgreSQL Flexible Server | GP D4ds_v5 (4 vCPU/16 GiB) → D8 at growth, 512 GB, zone-redundant HA | 1 (+1 read replica for reports) | PITR 35 d |
| Azure Cache for Redis | Premium P1, zones | 1 | |
| Storage account | StorageV2 ZRS, hot | 1 | ≈ 2 TB year one |
| Service Bus | Premium 1 MU | 1 | |
| Key Vault | Premium (HSM-backed keys) | 1 | purge protection |
| Container Registry | Premium, zone-redundant | 1 | |
| Log Analytics + Application Insights | PerGB2018, 365 d | 1 | ≈ 60 GB/month |
| API Management | Developer (staging) / Premium zonal (prod) | 1 | partner APIs |
| Backup vault | zone-redundant, 7-year monthly | 1 | |
| Azure OpenAI (optional) | approved region | 1 | Phase 15 |

Licences: no per-user software licences (all components open source or PaaS). Microsoft Sentinel is optional (Splunk HEC supported).

## Sizing for 10,000 concurrent users
Working assumptions (to be replaced with k6 measurements): a signed-in user generates ≈ 1 API request per 5 s (≈ 2,000 req/s at peak, plus bursts at registration opening and 08:00 check-in of ≈ 800 req/s each). A PHP replica serves ≈ 60–120 req/s for cached/light endpoints and 25–40 req/s for heavier ones, so the peak needs ≈ 30–40 api replicas — the autoscale ceiling is set to 40. PostgreSQL: ≈ 25 % of requests hit the database; with Redis caching of catalogue, dashboards and permissions this is ≈ 400 queries/s sustained, comfortably inside D4ds_v5; the read replica takes the heavy reports. Bandwidth: ≈ 40 KB average response (compressed) × 2,000 req/s ≈ 640 Mbit/s at the gateway — within WAF v2 autoscale; video and large files are served from Blob with SAS links, not through the API.

## Growth (TEC-03)
| Year | Users | API replicas (peak) | DB | Storage |
|---|---|---|---|---|
| 1 | 1.0 × | 40 | D4ds_v5 | 2 TB |
| 2 | 1.3 × | 52 (raise ceiling) | D8ds_v5 | 2.8 TB |
| 3 | 1.7 × | 68 | D8ds_v5 + replica | 3.9 TB |
Autoscale limits, the DB tier and storage alerts (80 %) are reviewed quarterly against the capacity dashboard in the Ops workbook; the model is re-run after each load test.
