# Lusail

Lusail is the Ministry's approved Arabic typeface. It is licensed, so it is not bundled in this repository.

Place the licensed web fonts in `web/public/fonts/lusail/` with these names:

- `Lusail-Regular.woff2` (400)
- `Lusail-Medium.woff2` (500)
- `Lusail-Bold.woff2` (700)

The site checks once per browser session whether the files exist and registers them; nothing is requested when they are
absent, and the text falls back to Qatar Sans → Tajawal. You can also upload the files in **Admin → Brand Studio →
Typography** (family name `Lusail`). For certificates, add the same files to mPDF's font directory
(`backend/storage/fonts`) and to `mobile/assets/fonts` + `pubspec.yaml` for the app.
