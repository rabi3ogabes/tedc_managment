# Deploying the web app to Vercel

Vercel hosts the **React web app** (public site, dashboard and portal) as a static site. The **Laravel API** cannot
run on Vercel: it needs a long-running PHP server, the scheduler and PDF generation. Deploy the API to Railway (see
[DEPLOY-RAILWAY.md](DEPLOY-RAILWAY.md)) and point the web app at it.

```
https://<project>.vercel.app          → web app (Vercel)
https://<api>.up.railway.app/api/v1   → API (Railway)
```

Railway alone is also enough: it serves the web app and the API together. Use Vercel only if you want the web app
on Vercel's CDN.

## 1. Deploy the API on Railway first

Follow [DEPLOY-RAILWAY.md](DEPLOY-RAILWAY.md) and note its domain, e.g. `https://tedc-api.up.railway.app`.

In the Railway service **Variables**, allow the Vercel site to call the API (comma-separated, no trailing slash):

```env
CORS_ALLOWED_ORIGINS=https://<project>.vercel.app
# Optional, for Vercel preview deployments:
CORS_ALLOWED_ORIGIN_PATTERNS=#^https://<project>-[a-z0-9-]+\.vercel\.app$#
```

## 2. Import the repository in Vercel

1. Vercel → **Add New… → Project** → import `tedc_managment`.
2. **Root Directory**: leave it as the repository root (`./`). The root `vercel.json` builds `web/` and serves
   `web/dist`. Choosing `web` as the Root Directory also works, with `web/vercel.json`.
3. **Environment Variables** (Production and Preview):

   | Name | Value |
   |---|---|
   | `VITE_API_URL` | `https://<api>.up.railway.app/api/v1` |
   | `VITE_SUPABASE_URL` | `https://<project-ref>.supabase.co` (optional: live notifications) |
   | `VITE_SUPABASE_PUBLISHABLE_KEY` | `sb_publishable_...` (optional; **never** the secret key) |

4. **Deploy.** For another branch, set **Settings → Git → Production Branch** or open a preview deployment of
   that branch.

`VITE_*` values are built into the web app, so **redeploy** after changing them. The build stops with a clear
message if `VITE_API_URL` is missing on Vercel.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `404: NOT_FOUND` on the Vercel URL | The project was built without `vercel.json`: redeploy the latest commit, with the Root Directory set to `./` (or `web`). |
| `404` when refreshing a page such as `/admin/programs` | Same cause: the SPA rewrite in `vercel.json` is missing. |
| Build fails: `Set VITE_API_URL …` | Add `VITE_API_URL` (step 2.3) and redeploy. |
| Login shows a network error; the browser console mentions CORS | Add the Vercel domain to `CORS_ALLOWED_ORIGINS` on Railway (step 1). |
| Login: `بيانات الدخول غير صحيحة` | Create the Supabase Auth users: see step 3 of DEPLOY-RAILWAY.md. |
