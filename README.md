# MustUse News

> **⚠️ Auto-generated — do not edit.** This repository is projected from the MustUse Apps Publisher on every **Ship It!** click. Manual edits will be overwritten on the next projection. Make changes in the [Publisher admin](https://www.mustuse.com/wp-admin/post.php?post=50&action=edit) instead.

This is a self-contained mobile app shell. It boots Laravel, hydrates from a signed manifest, and renders screens defined in WordPress as native iOS / Android UI via [NativePHP for Mobile v3](https://nativephp.com/).

| Field | Value |
|---|---|
| App name | MustUse News |
| Slug | `mustuse-news` |
| Bundle id | `com.mustuse.mustuse-news` |
| Version | `1.0.0` (build `5`) |
| Projected at | 2026-04-17 17:17 UTC |
| Publisher | https://www.mustuse.com |

## What's in this repo

```
app/                     Laravel application (Livewire components, providers)
config/nativephp.php     iOS / Android shell config (orientation, permissions)
public/icon.png          1024×1024 app icon (synced from the App's featured image)
public/splash.png        1080×1920 splash screen (synced from the App's splash meta)
resources/views/
  components/mustuse/    Block components projected from the publisher's plugin
  livewire/              The catch-all renderer that drives every screen
storage/app/
  mua-manifest.json      The signed manifest the shell renders at runtime
  mua-manifest.sig       HMAC-SHA256 signature over the manifest bytes
.env.example             Reference; Bifrost injects the real values
```

## Stack

[Laravel 12](https://laravel.com/) · [Livewire 3.5](https://livewire.laravel.com/) · [NativePHP Mobile 3.0](https://nativephp.com/) · PHP 8.2+

## Bifrost handoff

Bifrost has no API, so this is one-time setup per app. **All secrets live in Bifrost — they are not in this repo and they are not in WordPress.**

1. Open [bifrost.nativephp.com](https://bifrost.nativephp.com) and create a Project (or open the one already linked to this app).
2. Connect this repository via the Bifrost GitHub App.
3. Upload iOS + Android signing credentials under **Credentials** (one-time per platform):
   - iOS: an Apple Developer account, an iOS Distribution certificate, a Provisioning Profile, and an App Store Connect API key for upload.
   - Android: an upload keystore (`.jks` / `.p12`) and the password.
4. Paste the environment variables below into the Project's **Environment Variables** screen. Rows marked 🔒 are secrets — copy them from the Publisher admin panel and never commit them here.
5. Select the projected build branch and click **Ship It!** in Bifrost.

### Environment variables

| Name | Value |
|---|---|
| `NATIVEPHP_APP_ID` | `com.mustuse.mustuse-news` |
| `NATIVEPHP_APP_VERSION` | `1.0.0` |
| `NATIVEPHP_APP_VERSION_CODE` | `5` |
| `NATIVEPHP_DEEPLINK_SCHEME` | _(empty — set in app editor)_ |
| `NATIVEPHP_DEEPLINK_HOST` | _(empty — set in app editor)_ |
| `NATIVEPHP_START_URL` | `/` |
| `MUA_MANIFEST_URL` | `https://www.mustuse.com/wp-json/mustuse-apps-pub/v1/apps/mustuse-news/manifest` |
| `MUA_PUBLISHER_URL` | `https://www.mustuse.com` |
| `MUA_APPKEY` 🔒 | `(secret — see Bifrost env UI)` |

## Manifest contract

The manifest is the runtime contract between WordPress and the shell. On boot:

1. The Livewire `NativeEdge` component reads `storage/app/mua-manifest.json`.
2. It HMAC-verifies the bytes against `storage/app/mua-manifest.sig` using `MUA_APPKEY` — fail-closed when the key is configured. A signature mismatch refuses to render.
3. It resolves the requested screen by path, walks the screen's block tree, and dispatches each block to a Blade component under `resources/views/components/mustuse/`.

The shell never embeds business logic. New screens, copy changes, branding tweaks, even new top-level navigation tabs — all flow through WordPress and the manifest. You typically only re-Ship when you've shipped a new block type or upgraded the NativePHP version.

## NativePHP assets

NativePHP looks for these in `public/`:

- **`public/icon.png`** — 1024 × 1024 PNG, no transparency. Synced from the App's WordPress featured image.
- **`public/splash.png`** — 1080 × 1920 PNG (light mode). Synced from the App's splash-screen attachment.
- **`public/splash-dark.png`** — 1080 × 1920 PNG (dark mode). Optional; not currently synced.

If `public/icon.png` is missing or empty, drop a 1024 × 1024 PNG into the WordPress App's featured image and re-Ship.

## Local development

```bash
composer install
npm install
npm run build
```

For interactive iOS / Android development (requires Xcode on macOS or Android Studio):

```bash
php artisan native:install      # one-time
php artisan native:run          # boot the simulator/emulator
```

For iOS hot-reload via the Jump app:

```bash
php artisan native:jump
```

Then scan the QR code with the NativePHP Jump iOS app on a physical device.

## How this repo gets regenerated

Every **Ship It!** click in the Publisher:

1. Copies the `mobile-shell` blueprint into a temp directory.
2. Projects every block's `mobile.blade.php` into `resources/views/components/mustuse/`.
3. Writes the signed manifest to `storage/app/mua-manifest.{json,sig}`.
4. Syncs `public/icon.png` + `public/splash.png` from the App's media.
5. Composes `.env.example` + this README.
6. Pushes everything as a new branch via the GitHub Git Data API (no `shell_exec`, no local git).
7. Records change-detection hash so identical projections become no-ops on subsequent ships.

The publisher then opens a PR (or auto-merges, depending on workflow). Bifrost picks up the branch, runs `composer install --no-dev && npm run build`, signs, and ships to the app stores.

---

_Edit this app: https://www.mustuse.com/wp-admin/post.php?post=50&action=edit_
_Generated by [MustUse Apps Publisher](https://github.com/webmultipliers) on 2026-04-17 17:17 UTC._