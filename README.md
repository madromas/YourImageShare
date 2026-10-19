# YourImageShare Uploads

Upload images and videos to [YourImageShare](https://yourimageshare.com) straight
from the FlatBB post composer. One icon-only button in the toolbar, multi-file
picker, Markdown inserted at the cursor — FlatBB's live preview re-renders
immediately.

## What is YourImageShare?

[YourImageShare](https://yourimageshare.com) is a free image and video hosting
service with a REST API. Upload a file, get back a permanent direct URL plus
a 280-px WebP thumbnail. Files can be `private` (only the file is served, no
public page), `unlisted` (page exists but isn't listed), or `public`.

This plugin uses YourImageShare as a remote image/video host so your forum
server never stores the bytes — no disk usage, no backup bloat, no bandwidth
bills on hot threads.

## Why this plugin (vs. just pasting a link)

- **One-click upload from the composer.** No alt-tab to yourimageshare.com, no
  copy-paste of URLs. Pick a file → Markdown appears at the cursor.
- **API key never reaches the browser.** Uploads go through a server-side proxy
  route on your forum (`/yourimageshare/upload`), which forwards the file to
  YourImageShare with your API key. The key is never in any HTML, JS, or AJAX
  response visible to visitors.
- **Works alongside FlatBB's native uploader.** Doesn't touch the built-in
  drag/paste upload. The button is purely additive.
- **No local disk usage.** The plugin never writes uploaded files to your
  forum's `uploads/` directory — bytes flow straight from the browser through
  PHP's temp dir to YourImageShare, then the temp file is auto-deleted.
- **Multi-file uploads.** Pick several files at once; they're uploaded
  sequentially (to respect YourImageShare's rate limits) and inserted together.
- **Video support.** MP4, WebM, MOV, AVI, MKV, and more. Videos insert as a
  plain link (Markdown's image syntax doesn't render videos).

## Install

1. Get an **upload-only API key** from your YourImageShare dashboard (free
   account). Don't use your main key — upload-only keys can't delete files or
   read your account, so they're safe to put on a server.
2. In your forum: **Admin → Plugins → Upload plugin**, pick `yourimageshare.zip`.
3. The plugin enables immediately. Click **Settings** on its row.
4. Paste the upload-only API key. Pick a default visibility and Markdown shape.
5. Open any topic composer. You'll see a new icon-only upload button in the
   toolbar — click it, pick a file, done.

## Settings

| Field | What it does | Default |
| --- | --- | --- |
| Upload-only API key | The key the server uses to sign uploads to YourImageShare. Use an **upload-only** key, not your main key. | (empty) |
| Default visibility | `private` serves only the image file (no public page); `unlisted` creates a YourImageShare page that isn't listed publicly; `public` lists it. | `private` |
| Max file size (MB) | Per-file size cap for YourImageShare uploads. **Separate** from the forum's `upload_max_mb` — see note below. | `50` |
| Insert as | How the upload appears in the post: clickable thumbnail, inline full-size image, or plain link. | `thumbnail` (clickable) |

### Why a separate size limit?

FlatBB's built-in `upload_max_mb` setting governs **native** uploads — the ones that get written to your forum's `uploads/` directory. If you bump that to 50 MB so members can upload videos through this plugin, every member can also drop 50 MB files into your local disk via the native drag/paste uploader. For most admins that's a non-starter.

This plugin therefore has its own `Max file size (MB)` setting. It only applies to files uploaded through the YourImageShare button — those bytes go straight through PHP's temp dir to YourImageShare via cURL and **never touch your forum's `uploads/` directory**. You can safely set this to 50 or 100 MB without changing what the native uploader allows.

The plugin caps this setting at 100 MB because YourImageShare's single-request endpoint returns 413 above that — larger files need the chunked upload path, which isn't implemented yet.

#### One thing you may need to ask your host about

PHP itself has two `php.ini` limits that cap **any** upload regardless of what the plugin allows: `upload_max_filesize` and `post_max_size`. Many shared hosts default these to 2 MB or 8 MB. If a member uploads a 30 MB video and the upload fails with *"The file exceeds the server's PHP upload_max_filesize limit"*, ask your host to raise both to at least the plugin's `Max file size (MB)` value (e.g. `upload_max_filesize=64M` and `post_max_size=64M` for a 50 MB plugin limit). The plugin will surface this exact message when it happens.

### "Insert as" examples

| Setting | Markdown inserted | Rendered HTML |
| --- | --- | --- |
| Clickable thumbnail (default) | `[![image](THUMB)](SRC)` | `<a href="SRC"><img src="THUMB"></a>` |
| Inline full-size image | `![image](SRC)` | `<img src="SRC">` |
| Plain link | `SRC` | `<a href="SRC">SRC</a>` |

Videos always insert as a plain link, regardless of this setting, because
Markdown's image syntax doesn't render `<video>` elements.

## Security model

- The upload route is gated by `need_login()` (members only) + `require_post()`
  (POST + valid CSRF token) + `can('upload')` (forum-wide upload permission
  per group). Anonymous visitors and groups without upload permission can't
  hit it.
- CSRF protection is handled by FlatBB core (`check_csrf()` runs on every POST
  at dispatch time). The plugin does **not** mark the route `csrf_exempt`.
- The API key lives only in `plugin_settings` in your forum's database and is
  never sent to the browser in any form.
- File uploads use PHP's standard `$_FILES` handling and `is_uploaded_file()`
  check. The file is forwarded to YourImageShare with cURL and never written
  to your forum's local disk.
- **Server-side validation** mirrors FlatBB's native `upload_store()`:
  - File size is capped at the plugin's own `Max file size (MB)` setting
    (default 50 MB, max 100 MB). **This is separate from the forum's
    `upload_max_mb`** — bumping it does NOT allow larger native uploads to
    your forum's disk. See [Why a separate size limit?](#why-a-separate-size-limit)
    for details.
  - File extension must be in the forum's `upload_types` allowlist OR the
    plugin's video extension list (mp4, webm, mov, m4v, avi, mkv, mpeg, wmv,
    flv, ogv, 3gp). Executable/script extensions (php, html, js, exe, etc.)
    are always rejected.
  - MIME type is derived from real file bytes via `upload_mime()` (uses
    `finfo` / `mime_content_type` / `getimagesize`), never trusted from the
    client-supplied `$_FILES['type']`.
  - Image files are sanity-checked with `getimagesize()` so a renamed
    executable can't slip through as e.g. `cat.jpg`.
  - PHP-level size errors (`UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_FORM_SIZE`)
    surface a friendly message naming the actual `upload_max_filesize` limit
    so members know it's a server config issue, not a plugin bug.
- Visibility defaults to `private`, so uploads don't create a publicly-listed
  YourImageShare page.

## Compatibility

- FlatBB 0.1.0 or newer.
- PHP 8.1+ with the cURL extension (required for the server-side proxy).
- No database tables are created — all state lives in `plugin_settings`.
- No core file edits, no theme overrides, no Composer.

## Uninstall

Disabling the plugin removes the toolbar button. Uninstalling (via
**Admin → Plugins → Uninstall**) removes the plugin's settings. No user content
is touched because the plugin stores nothing per-user.

## Changelog

### 1.0.2

- **New setting: `Max file size (MB)`.** Plugin-specific size cap, separate
  from the forum's `upload_max_mb`. Bumping this does NOT allow larger native
  uploads to your forum's disk — it only governs files that flow through the
  YourImageShare proxy. Default 50 MB, range 1–100 MB.
- **Better PHP-limit error messages.** When PHP itself rejects an upload
  because of `upload_max_filesize` or `post_max_size` in `php.ini`, the error
  now names the actual limit so members know it's a host config issue, not a
  plugin bug.
- **Visibility:** Added `public` to the valid options (was silently rejected
  before; the API supports it).
- **Security: cURL errors no longer leak to the browser.** When the forum
  server can't reach YourImageShare (DNS failure, TLS error, timeout,
  connection refused, etc.), the raw cURL error string is now logged
  server-side at `data/yourimageshare_errors.log` and the browser gets a
  generic *"Upload failed. Please try again later."* message. Internal
  host/network details are no longer exposed to members. The same applies to
  non-200 responses from YourImageShare — raw response body is logged
  server-side, only the YIS-supplied user-facing message (e.g. "unsupported
  file type") reaches the browser.
- **Operational:** Added `yourimageshare_log()` helper that writes structured
  entries (timestamp, level, message, JSON context) to
  `data/yourimageshare_errors.log` with a 1 MB rotation cap, and mirrors to
  PHP's SAPI `error_log` so entries appear in hosting panel logs too.

### 1.0.1

- **Security:** Server-side validation mirroring FlatBB's native `upload_store()`.
  - File size capped at the forum's `upload_max_mb` setting (default 5 MB).
    *Replaced in 1.0.2 with a plugin-specific setting.*
  - File extension allowlist: forum's `upload_types` setting ∪ video extensions
    (mp4/webm/mov/m4v/avi/mkv/mpeg/wmv/flv/ogv/3gp).
  - Hard-blocked extensions (php, html, js, exe, etc.) always rejected.
  - MIME type derived from real file bytes via `upload_mime()` instead of
    trusting client-supplied `$_FILES['type']`.
  - Image files sanity-checked with `getimagesize()`.
- **Permission:** Added `can('upload')` check — members without the forum-wide
  upload permission can no longer hit the route.

### 1.0.0

- Initial release.
- Icon-only upload button in the composer toolbar.
- Server-side proxy keeps the API key off the wire.
- Multi-file picker with sequential uploads.
- Image and video support.
- Three Markdown insertion shapes: thumbnail, inline, link.
- Settings: API key, default visibility, default Markdown shape.

## License

MIT — same as the rest of the YourImageShare forum integrations. See
<https://github.com/yourimageshare/yourimageshare>.

## Links

- **YourImageShare:** <https://yourimageshare.com>
- **API docs:** <https://yourimageshare.com/docs/api>
- **Source & other forum integrations (phpBB / SMF / MyBB / fluxbb / punbb / zetaboards):** <https://github.com/yourimageshare/yourimageshare>
- **FlatBB:** <https://www.flatbb.com>
