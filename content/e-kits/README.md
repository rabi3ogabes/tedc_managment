# E-kits — الحقائب التفاعلية

The kit content (chapters, knowledge checks, final questions, both languages) lives in `backend/resources/ekits/kits.json` so it ships with the API.
This folder keeps the trainer guides, trainee guide and session plan.

Build and export: `php artisan tedc:ekits-build [--rebuild] [--export=DIR]` or the admin page *Interactive e-kits*.
