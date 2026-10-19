/*
 * YourImageShare uploader for FlatBB composer.
 *
 * - Listens for clicks on [data-yourimageshare-trigger] inside any composer toolbar.
 * - Opens a file picker (multi-select allowed).
 * - For each file, POSTs to /yourimageshare/upload (session + CSRF, server-side proxy).
 * - On success, inserts Markdown at the cursor in the composer textarea and
 *   fires an input event so FlatBB's live preview re-renders.
 */
(function () {
    'use strict';

    if (window.yourimageshare_init) return;
    window.yourimageshare_init = true;

    var ENDPOINT = '/yourimageshare/upload';

    function getCSRF() {
        // FlatBB exposes the CSRF token as window.FB.csrf (see app/views/layout.php).
        // It expects the token back as $_POST['_token'] OR an X-CSRF-Token header.
        // There is no <meta> tag and no input[name="csrf"]; the editor view does
        // not render a hidden field either.
        if (window.FB && typeof window.FB.csrf === 'string' && window.FB.csrf) {
            return window.FB.csrf;
        }
        // Defensive fallbacks (in case a future FlatBB version changes layout):
        var meta = document.querySelector('meta[name="csrf-token"], meta[name="_token"]');
        if (meta && meta.content) return meta.content;
        var input = document.querySelector('input[name="_token"], input[name="csrf"], input[name="csrf_token"]');
        if (input && input.value) return input.value;
        return null;
    }

    function findComposerTextarea(btn) {
        // FlatBB's editor wraps the toolbar + a textarea inside an .editor
        // container. Try several strategies in order of specificity.
        var i, ta, sib, node;

        // 1. Closest .editor ancestor, find textarea inside.
        var editor = btn.closest('.editor')
                   || btn.closest('[class*="editor"]')
                   || btn.closest('[data-editor]')
                   || btn.closest('[data-slot*="composer"]');
        if (editor) {
            ta = editor.querySelector('textarea');
            if (ta) return ta;
        }

        // 2. Closest <form>, find textarea inside.
        var form = btn.closest('form');
        if (form) {
            ta = form.querySelector('textarea');
            if (ta) return ta;
        }

        // 3. Walk up a few ancestors, looking for a textarea in next siblings
        //    or their descendants (toolbar is followed by the textarea).
        node = btn;
        for (i = 0; i < 10 && node; i++) {
            sib = node.nextElementSibling;
            while (sib) {
                if (sib.tagName === 'TEXTAREA') return sib;
                ta = sib.querySelector && sib.querySelector('textarea');
                if (ta) return ta;
                sib = sib.nextElementSibling;
            }
            node = node.parentElement;
        }

        // 4. Last resort: if the page has exactly one textarea, use it.
        var all = document.querySelectorAll('textarea');
        if (all.length === 1) return all[0];

        return null;
    }

    function insertAtCursor(textarea, markdown) {
        if (!textarea) return;
        textarea.focus();
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var before = textarea.value.substring(0, start);
        var after = textarea.value.substring(end);
        var prefix = (before.length > 0 && !before.endsWith('\n')) ? '\n' : '';
        var suffix = (after.length > 0 && !after.startsWith('\n')) ? '\n' : '';
        var insert = prefix + markdown + suffix;
        textarea.value = before + insert + after;
        var cursor = start + insert.length;
        textarea.selectionStart = textarea.selectionEnd = cursor;
        // Trigger FlatBB's live preview.
        try {
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
            // Some older browsers need a manual event.
            var ev = document.createEvent('HTMLEvents');
            ev.initEvent('input', true, true);
            textarea.dispatchEvent(ev);
        }
    }

    function isVideoFile(file) {
        if (file.type && file.type.indexOf('video/') === 0) return true;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        return ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv', 'ogv', '3gp'].indexOf(ext) !== -1;
    }

    function buildMarkdown(results, shape) {
        // results: array of {url, thumb, isVideo}
        return results.map(function (u) {
            // Videos can't be embedded with Markdown image syntax; always
            // insert as a plain link regardless of shape setting.
            if (u.isVideo) {
                return u.url;
            }
            if (shape === 'link') {
                return u.url;
            }
            if (shape === 'inline' || !u.thumb) {
                return '![image](' + u.url + ')';
            }
            // thumbnail (default): clickable thumb -> full image
            return '[![image](' + u.thumb + ')](' + u.url + ')';
        }).join('\n');
    }

    function setStatus(btn, text, isError) {
        var wrap = btn.closest('.yourimageshare-wrap');
        if (!wrap) return;
        var status = wrap.querySelector('[data-yourimageshare-status]');
        if (!status) return;
        if (text === null) {
            status.hidden = true;
            status.textContent = '';
            status.classList.remove('yourimageshare-error');
        } else {
            status.hidden = false;
            status.textContent = text;
            status.classList.toggle('yourimageshare-error', !!isError);
        }
    }

    function uploadOne(file, csrf) {
        return new Promise(function (resolve, reject) {
            var fd = new FormData();
            fd.append('file', file);
            // FlatBB's check_csrf() reads $_POST['_token'] OR the X-CSRF-Token
            // header. Send both for belt-and-suspenders robustness.
            if (csrf) fd.append('_token', csrf);

            // Respect window.FB.base if FlatBB is installed in a subdirectory.
            var base = (window.FB && typeof window.FB.base === 'string') ? window.FB.base : '';
            var url  = base + ENDPOINT;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.responseType = 'json';

            xhr.onload = function () {
                var body = xhr.response;
                if (typeof body === 'string') {
                    try { body = JSON.parse(body); } catch (e) { body = null; }
                }
                if (xhr.status >= 200 && xhr.status < 300 && body && body.ok !== false && body.url) {
                    resolve({
                        url:     body.url,
                        thumb:   body.thumb || null,
                        isVideo: isVideoFile(file),
                    });
                } else {
                    var msg = (body && (body.error || body.message)) || ('HTTP ' + xhr.status);
                    reject(new Error(msg));
                }
            };
            xhr.onerror = function () { reject(new Error('Network error')); };
            xhr.ontimeout = function () { reject(new Error('Request timed out')); };
            xhr.timeout = 60000;
            xhr.send(fd);
        });
    }

    function handleFiles(btn, textarea, files, shape) {
        if (!files || !files.length) return;
        var csrf = getCSRF();
        var list = Array.prototype.slice.call(files);

        btn.disabled = true;
        setStatus(btn, 'Uploading ' + list.length + ' file' + (list.length > 1 ? 's' : '') + '…', false);

        // Sequential uploads - keeps rate limits happy and keeps Markdown in the order the user picked.
        var results = [];
        var chain = Promise.resolve();
        list.forEach(function (file) {
            chain = chain.then(function () {
                return uploadOne(file, csrf).then(function (r) {
                    results.push(r);
                    setStatus(btn, 'Uploaded ' + results.length + '/' + list.length + '…', false);
                });
            });
        });

        chain.then(function () {
            var md = buildMarkdown(results, shape);
            insertAtCursor(textarea, md);
            setStatus(btn, null);
        }).catch(function (err) {
            setStatus(btn, 'Upload failed: ' + err.message, true);
            // Insert whatever we did get, if anything.
            if (results.length) {
                insertAtCursor(textarea, buildMarkdown(results, shape));
            }
        }).finally(function () {
            btn.disabled = false;
            // Clear the status after a few seconds so it doesn't linger.
            setTimeout(function () { setStatus(btn, null); }, 4000);
        });
    }

    function onClick(e) {
        var btn = e.target.closest('[data-yourimageshare-trigger]');
        if (!btn) return;
        if (btn.disabled) return;

        var textarea = findComposerTextarea(btn);
        if (!textarea) {
            setStatus(btn, 'Could not find the composer textarea.', true);
            return;
        }

        // Read the chosen Markdown shape from the button's data attribute,
        // which the plugin sets from the saved setting when rendering the
        // toolbar. Falls back to 'thumbnail' if absent.
        var shape = btn.getAttribute('data-yourimageshare-shape') || 'thumbnail';

        // Read the accept attribute from the button (defaults to images+videos).
        var accept = btn.getAttribute('data-yourimageshare-accept') || 'image/*,video/*';

        var input = document.createElement('input');
        input.type = 'file';
        input.accept = accept;
        input.multiple = true;
        input.style.display = 'none';
        document.body.appendChild(input);

        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                handleFiles(btn, textarea, input.files, shape);
            }
            // Clean up the input next tick.
            setTimeout(function () {
                if (input.parentNode) input.parentNode.removeChild(input);
            }, 0);
        });

        input.click();
    }

    // The toolbar is part of the rendered page, so a single document-level
    // listener covers it - including any composer re-rendered by AJAX.
    document.addEventListener('click', onClick);
})();
