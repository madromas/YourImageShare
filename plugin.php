<?php
if (!defined('FLATBB')) exit;

/**
 * YourImageShare uploads for FlatBB.
 *
 * Adds an image-upload button to the post composer. The button opens a file
 * picker; the chosen file is POSTed to this plugin's /yourimageshare/upload
 * route, which proxies the request to https://yourimageshare.com/api using the
 * upload-only API key stored in plugin settings (so the key never appears in
 * page source). The returned URL is inserted into the composer textarea as
 * Markdown at the cursor position.
 */

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

function yourimageshare_js(): string
{
    return file_get_contents(__DIR__ . '/assets/uploader.js');
}

function yourimageshare_css(): string
{
    return file_get_contents(__DIR__ . '/assets/style.css');
}

// ---------------------------------------------------------------------------
// Server-side log (FlatBB has no core logger; per spec convention we write
// to DATA_DIR/yourimageshare_errors.log which matches the myid_* filename
// rule. Also mirrored to PHP's SAPI error_log so it shows up in hosting
// panel logs. The browser NEVER sees the raw error details.)
// ---------------------------------------------------------------------------

function yourimageshare_log(string $level, string $msg, array $ctx = []): void
{
    $line = date('c') . ' [' . strtoupper($level) . '] ' . $msg;
    if ($ctx) {
        // Strip anything that could break a single-line log entry.
        $ctx_str = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ctx_str = preg_replace('/[\r\n]+/', ' ', $ctx_str);
        $line .= ' ' . $ctx_str;
    }
    $line .= "\n";

    // Path follows the plugin sandbox rule: DATA_DIR . '/myid_*'
    $path = DATA_DIR . '/yourimageshare_errors.log';

    // Cap at 1 MB (mirror core's CSP log pattern) — truncate from the top
    // before appending so the file can't grow without bound.
    if (is_file($path) && filesize($path) > 1048576) {
        $tail = @file_get_contents($path, false, null, 524288);
        if ($tail !== false) {
            @file_put_contents($path, $tail);
        }
    }

    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    @error_log('[yourimageshare] ' . rtrim($line));
}

// ---------------------------------------------------------------------------
// Toolbar button
// ---------------------------------------------------------------------------

function yourimageshare_toolbar(array $items, array $ctx): array
{
    // Upload icon: upward arrow + tray below. Self-contained inline SVG so we
    // don't depend on FlatBB's icon set. Matches the icon the user requested.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"'
         . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
         . ' stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
         . '<polyline points="17 8 12 3 7 8"/>'
         . '<line x1="12" y1="3" x2="12" y2="15"/>'
         . '</svg>';

    // Read the saved "Insert as" setting so the JS knows what Markdown shape
    // to emit. Attached to the button itself so we don't need a body attribute.
    $shape = plugin_setting('yourimageshare', 'markdown_shape', 'thumbnail');
    if (!in_array($shape, ['thumbnail', 'inline', 'link'], true)) {
        $shape = 'thumbnail';
    }

    // List region: insert one item keyed by the plugin id. Icon-only button
    // (no text label). The core filters by `visible`, so anonymous visitors
    // never see the button (and the route also calls need_login(), so it's
    // double-gated). Accepts images AND videos.
    $items['yourimageshare'] = [
        'html'    => '<span class="yourimageshare-wrap">'
                   . '<button type="button" class="yourimageshare-btn" '
                   . 'data-yourimageshare-trigger="1" '
                   . 'data-yourimageshare-accept="image/*,video/*" '
                   . 'data-yourimageshare-shape="' . h($shape) . '" '
                   . 'title="Upload to YourImageShare" '
                   . 'aria-label="Upload to YourImageShare">'
                   . $svg
                   . '</button>'
                   . '<span class="yourimageshare-status" data-yourimageshare-status="1" hidden></span>'
                   . '</span>',
        'weight'  => 50,
        'visible' => 'members',
    ];

    return $items;
}

// ---------------------------------------------------------------------------
// Upload route (server-side proxy -> keeps API key off the wire)
// ---------------------------------------------------------------------------

function yourimageshare_upload(): never
{
    need_login();
    require_post(); // POST + CSRF

    // Double-gate: members must also have the forum-wide upload permission.
    // Admins always pass; other groups follow the upload_types setting +
    // group permissions set in Admin → Users → Groups.
    if (!can('upload')) {
        json_error(t('You are not allowed to upload files.'), 403);
    }

    $key = plugin_setting('yourimageshare', 'api_key', '');
    if ($key === '') {
        json_error('YourImageShare plugin is not configured. Set an upload-only API key in Admin → Plugins → YourImageShare.', 400);
    }

    $vis = plugin_setting('yourimageshare', 'visibility', 'private');
    if (!in_array($vis, ['private', 'unlisted', 'public'], true)) {
        $vis = 'private';
    }

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        json_error('No file received.', 400);
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        // Surface a friendly message for the two common PHP-level size errors so
        // users know to ask the admin to raise php's upload_max_filesize / post_max_size.
        if ((int)$f['error'] === UPLOAD_ERR_INI_SIZE) {
            $lim = ini_get('upload_max_filesize') ?: '(unknown)';
            json_error(t('The file exceeds the server\'s PHP upload_max_filesize limit (%s). Ask an administrator to raise it.', $lim), 400);
        }
        if ((int)$f['error'] === UPLOAD_ERR_FORM_SIZE) {
            json_error(t('The file exceeds the form\'s MAX_FILE_SIZE limit.'), 400);
        }
        json_error('Upload error code ' . (int)$f['error'], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        json_error('Invalid upload.', 400);
    }

    if (!function_exists('curl_init')) {
        json_error('Server is missing the cURL extension.', 500);
    }

    // ---- Server-side validation (mirrors core upload_store() pattern) ----

    // 1. Size limit: plugin-specific max_mb setting. This is SEPARATE from the
    //    forum's upload_max_mb setting — the forum setting governs native
    //    uploads (which write to local disk), this setting governs only
    //    YourImageShare proxy uploads (which forward to a remote service and
    //    never touch local disk). Capped at 100 MB because single-request
    //    uploads above that need the chunked path (not yet supported).
    $max_mb = (int)plugin_setting('yourimageshare', 'max_mb', 50);
    if ($max_mb < 1) $max_mb = 1;
    if ($max_mb > 100) $max_mb = 100;
    $max_bytes = $max_mb * 1048576;
    $size = (int)@filesize($f['tmp_name']);
    if ($size <= 0) {
        json_error(t('The uploaded file is empty.'), 400);
    }
    if ($size > $max_bytes) {
        json_error(t('File must be smaller than %s.', human_size($max_bytes)), 400);
    }

    // 2. Extension allowlist: union of the forum's upload_types setting
    //    (admin-configurable, default jpg,jpeg,png,gif,webp,pdf,zip,txt) AND
    //    a video extension list (YourImageShare supports videos). Site admins
    //    can restrict by removing items from upload_types — they cannot add
    //    anything not in this combined list.
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    $video_exts = ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', 'wmv', 'flv', 'ogv', '3gp'];
    $allowed = array_unique(array_merge(upload_allowed_types(), $video_exts));
    // Always reject these regardless of allowlist (defense in depth, matches core).
    $blocked = ['php', 'phtml', 'phar', 'htaccess', 'html', 'htm', 'svg', 'js', 'exe', 'bat', 'sh', 'py', 'pl', 'rb'];
    if ($ext === '' || !in_array($ext, $allowed, true) || in_array($ext, $blocked, true)) {
        json_error(t('File type .%s is not allowed.', $ext), 400);
    }

    // 3. Derive MIME from real file bytes (never trust client-supplied $f['type']).
    $mime = upload_mime($f['tmp_name']);
    if ($mime === '') $mime = 'application/octet-stream';

    // 4. For image extensions, sanity-check with getimagesize() so a renamed
    //    executable can't sneak through as e.g. cat.jpg.
    $is_image = in_array($ext, upload_image_exts(), true);
    if ($is_image && @getimagesize($f['tmp_name']) === false) {
        json_error(t('The file is not a valid image.'), 400);
    }

    // YourImageShare API expects the file under the field name "uploads" (plural).
    // There is no "type" request field; that's a response-only field. Visibility
    // is one of: private | unlisted | public.
    $postfields = [
        'uploads'    => new CURLFile($f['tmp_name'], $mime, $f['name']),
        'visibility' => $vis,
    ];

    $ch = curl_init('https://yourimageshare.com/api');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $key,
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => $postfields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        // cURL-level failure (DNS, TLS, timeout, connection refused, etc.).
        // Log the raw error server-side so admin can diagnose; return a generic
        // message to the browser so internal host/network details are not leaked.
        yourimageshare_log('error', 'cURL request to YourImageShare failed', [
            'curl_error' => $err,
            'http_code'  => $code,
            'uid'        => uid(),
            'size'       => $size,
        ]);
        json_error(t('Upload failed. Please try again later.'), 502);
    }

    $j = json_decode($resp, true);
    if ($code !== 200 || !is_array($j)) {
        // YourImageShare returned non-success. Log the raw response server-side
        // (it may contain diagnostic info from YIS); surface only the YIS-
        // supplied user-facing message (e.g. "unsupported file type") to the
        // browser, since that's actionable for the user.
        yourimageshare_log('warning', 'YourImageShare returned non-success', [
            'http_code' => $code,
            'body'      => is_string($resp) ? substr($resp, 0, 500) : null,
            'uid'       => uid(),
            'size'      => $size,
        ]);
        // YourImageShare error body uses "errors" (plural) for the message.
        $msg = is_array($j) && !empty($j['errors'])
            ? (string)$j['errors']
            : (is_array($j) && !empty($j['error'])
                ? (string)$j['error']
                : (is_array($j) && !empty($j['message'])
                    ? (string)$j['message']
                    : t('Upload failed. Please try again later.')));
        json_error($msg, 502);
    }

    // Successful response is wrapped: {type:"success", msg, data:{...}}.
    // Inside data:
    //   src    = permanent direct file URL (image or video) - use for <img>/<video>
    //   thumb  = 280px WebP thumbnail (video: first frame), or null
    //   direct = the YourImageShare viewer page URL
    // Per the API docs, store src (stable), not path (mutates after conversion).
    if (($j['type'] ?? null) !== 'success' || !isset($j['data']['src'])) {
        json_error('Unexpected response shape from YourImageShare.', 502);
    }

    $data = $j['data'];
    $url  = isset($data['src'])    && is_string($data['src'])    ? $data['src']    : null;
    $thumb = isset($data['thumb']) && is_string($data['thumb']) && $data['thumb'] !== '' ? $data['thumb'] : null;

    if (!$url) {
        json_error('No file URL in YourImageShare response.', 502);
    }

    json_ok([
        'url'   => $url,
        'thumb' => $thumb,
    ]);
}

// ---------------------------------------------------------------------------
// Manifest (data only - no calls, no variables, no expressions)
// ---------------------------------------------------------------------------

return [
    'id'          => 'yourimageshare',
    'name'        => 'YourImageShare Uploads',
    'version'     => '1.0.2',
    'description' => 'Upload images and videos to YourImageShare from the post composer and insert them as Markdown at the cursor.',
    'author'      => 'Arruba',
    'url'         => 'https://glonks.com',
    'requires'    => ['flatbb' => '0.1.0'],
    'hooks'       => [
        'region.composer.toolbar' => 'yourimageshare_toolbar',
    ],
    'routes'      => [
        '/yourimageshare/upload' => 'yourimageshare_upload',
    ],
    'settings'    => [
        'api_key'     => [
            'type'    => 'text',
            'label'   => 'Upload-only API key',
            'default' => '',
            'max'     => 80,
            'help'    => 'Create an Upload-only key in your YourImageShare dashboard and paste it here. Never paste your main API key — anything in this field is used by the server to sign uploads to YourImageShare.',
        ],
        'visibility'  => [
            'type'    => 'select',
            'label'   => 'Default visibility',
            'options' => [
                'private'  => 'Private (image only, no public page)',
                'unlisted' => 'Unlisted (page exists but is not listed)',
                'public'   => 'Public (listed on YourImageShare)',
            ],
            'default' => 'private',
            'help'    => 'Private is recommended: only the image file is served, no YourImageShare-hosted page.',
        ],
        'max_mb'       => [
            'type'    => 'number',
            'label'   => 'Max file size (MB)',
            'default' => 50,
            'min'     => 1,
            'max'     => 100,
            'help'    => 'Per-file size limit for YourImageShare uploads. This is SEPARATE from the forum\'s global upload_max_mb setting — bumping this does NOT allow larger native uploads to your forum\'s disk. YourImageShare accepts files up to 200 MB; the plugin caps at 100 MB because single-request uploads above that need chunked upload (not yet supported).',
        ],
        'markdown_shape' => [
            'type'    => 'select',
            'label'   => 'Insert as',
            'options' => [
                'thumbnail' => 'Clickable thumbnail linking to full image',
                'inline'    => 'Inline full-size image',
                'link'      => 'Plain link on its own line',
            ],
            'default' => 'thumbnail',
            'help'    => 'How the uploaded image should appear in the post. Thumbnail is best for screenshots; inline is best for small images.',
        ],
    ],
    'assets'      => [
        'js'  => ['yourimageshare_js'],
        'css' => ['yourimageshare_css'],
    ],
];
