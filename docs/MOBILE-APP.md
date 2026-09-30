# Mobile app (Android APK)

## Download and install

Every change to `mobile/` builds a new APK on GitHub Actions (workflow **Mobile APK**) and publishes it as a
pre-release: **GitHub → Releases → "TEDC Mobile 1.0.N" → `tedc-mobile-1.0.N.apk`**.

1. Open the release page on the phone and download the APK.
2. Allow installing from your browser or files app when Android asks ("Install unknown apps").
3. Open **مركز التدريب**. The icon is the Ministry of Education and Higher Education emblem.

To build on demand: **Actions → Mobile APK → Run workflow**. You can optionally enter another API address.

The app connects to `https://tedc-managment.vercel.app/api/v1` by default. To test against another server,
**long-press the emblem** on the sign-in screen, enter the address (e.g. `my-branch.vercel.app`), then
**Test connection** and **Save**.

## Demo accounts

The sign-in screen lists the 8 demo roles. Tap one to sign in; the password is `Tedc@2026!`. For a production
build, pass `--dart-define=SHOW_DEMO_ACCOUNTS=false` to hide them.

## What to test

| Area | Where |
|---|---|
| Home: KPIs, attendance QR, upcoming sessions, recommendations | Home tab |
| Catalog, filters, eligibility, registration | Programs tab → a program → **سجّل الآن** |
| Registrations, sessions and attendance, materials | My Training → Programs → a registration |
| Calendar, tasks (text answer or file), impact surveys (30/60/90 days) | My Training tabs |
| QR attendance check-in (camera) | Home → **تسجيل الحضور** |
| Certificate wallet, PDF, verification QR | Certificates tab |
| Notifications, push notifications, mark all read | Notifications tab |
| Training passport, skills, language, push status, sign out | Profile tab |
| School management (School Admin account) | Profile → **إدارة المدرسة** |

## Push notifications (Firebase)

Push notifications are managed from the web dashboard: **Settings → Notifications**
(`/admin/settings/notifications`, permission `settings.manage`). The APK does not include a
`google-services.json` file; it downloads the Firebase settings from the platform. You can therefore change the
Firebase project at any time without a new APK.

1. In [Firebase Console](https://console.firebase.google.com/), create a project.
2. Add an **Android app** with the package name `app.tedcmanagment.vercel`, download `google-services.json` and
   drop it on step 2 of the settings page. The fields fill automatically.
3. In **Project settings → Service accounts → Generate new private key**, download the JSON key and drop it on
   step 1. It is stored encrypted and never shown again. Then click **Test connection to Google**.
4. Enable sending, choose the categories to push and **Save**.
5. Sign in on the phone and allow notifications; the profile shows "Enabled on this device". Then use
   **Send a test notification** on the settings page.

The pushable categories are registrations, session reminders, tasks, certificates, impact surveys,
announcements and training needs. Each category can be switched on or off. Every in-app notification of an
enabled category is also pushed, in the language of each device. Tapping a notification opens the related
screen. Devices that uninstalled the app are removed automatically, and the delivery log shows every send.

The Android package name is `app.tedcmanagment.vercel` and must stay identical to the app registered in Firebase:
Firebase's auto-generated Android API key is restricted to that package, so any other package cannot register for
push. From a terminal you can also load the file directly:
`php artisan tedc:push-import path/to/google-services.json` (this fills the client options; the service-account
key is still uploaded from the dashboard). If the app has a release signing key, add its SHA-1 to the Firebase app.

Smart nudges: every 5 minutes `tedc:attendance-nudges` tells participants when check-in opens and reminds those who
have not checked in once the session has started (sent once per session, category "session").

## Location-verified attendance

Scanning the trainer's QR code also sends the phone's position. The server compares it with the room's
coordinates (Settings → Rooms → location) and refuses check-in / check-out from farther than the allowed range
(default 150 m, plus a small allowance for GPS accuracy), from an imprecise fix, or from a mock-location app.
Online sessions and rooms without coordinates are not checked. Administrators switch the check on or off and set
the range in **Settings → Attendance**; `TEDC_GEOFENCE`, `TEDC_GEOFENCE_RADIUS` and `TEDC_GEOFENCE_MAX_ACCURACY`
set the defaults. The app asks for location permission when needed and explains how to fix a blocked permission.
Each attendance record keeps the position, its accuracy, the distance and a verification status.

## Store-grade signing (optional)

Without secrets, the APK is signed with the debug key, which is fine for testing. For a release signature,
add these repository secrets:
`ANDROID_KEYSTORE_BASE64` (`base64 -w0 release.jks`), `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`,
`ANDROID_KEY_PASSWORD`. Optional repository variables: `TEDC_API_URL`, `SUPABASE_URL`,
`SUPABASE_PUBLISHABLE_KEY` (live notification badge through Supabase Realtime).

## App icon

The icon, splash screen and notification icon come from the official Ministry emblem
(`brand-source/moe-emblem-transparent.png`, fetched from edu.gov.qa by the **Fetch official brand assets**
workflow). To regenerate them after replacing `mobile/assets/brand/app-icon*.png`, run
`dart run flutter_launcher_icons` in `mobile/`.
