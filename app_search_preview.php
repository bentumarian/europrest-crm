<?php

/*
|--------------------------------------------------------------------------
| app_search_preview.php
|--------------------------------------------------------------------------
| render_search_preview_assets() - modul partajat dropdown live pentru
| previzualizarea rezultatelor de căutare (clienți, etc).
| Se include o singură dată per request (idempotent).
|--------------------------------------------------------------------------
*/

if (!function_exists('render_search_preview_assets')) {
    function render_search_preview_assets(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        .pz-search-wrap { position:relative; isolation: isolate; }
        .pz-search-preview {
            position: fixed;
            background: var(--pz-surf, #FFFFFF);
            border: 1px solid var(--pz-line, #E2E8F0);
            border-radius: var(--pz-r, 8px);
            box-shadow: 0 4px 12px rgba(15,23,42,.06);
            padding: 4px;
            max-height: 420px;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            z-index: 9999;
            display: none;
        }
        .pz-search-preview::-webkit-scrollbar { width: 0; height: 0; display: none; }
        .pz-search-preview.open { display: block; }
        .pz-search-preview-empty {
            padding: 14px 12px; text-align: center;
            font-size: 12.5px; color: var(--pz-mu, #64748B); font-weight: 600;
        }
        .pz-search-item {
            display: block;
            padding: 7px 10px;
            border-radius: var(--pz-rs, 4px);
            background: transparent;
            cursor: pointer; text-decoration: none; color: inherit;
            font: inherit; text-align: left; width: 100%;
            border: 0;
        }
        .pz-search-item + .pz-search-item { margin-top: 1px; }
        .pz-search-item:hover,
        .pz-search-item.is-active {
            background: var(--pz-bls, #EFF6FF);
        }
        .pz-search-item-title {
            font-size: 12.5px; font-weight: 600;
            color: var(--pz-title, #0F172A);
            line-height: 1.35;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        </style>
        <script>
        (function () {
            if (window.pzSearchPreview) return;
            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function normalize(v) {
                return String(v == null ? '' : v).trim().toLowerCase();
            }
            function buildItemHtml(item) {
                var tagName = item.url ? 'a' : 'button';
                var hrefAttr = item.url ? ' href="' + esc(item.url) + '"' : ' type="button"';
                return '<' + tagName + ' class="pz-search-item"' + hrefAttr + '>'
                     + '<span class="pz-search-item-title">' + esc(item.title || '') + '</span>'
                     + '</' + tagName + '>';
            }
            function attach(inputId, items, options) {
                var input = typeof inputId === 'string' ? document.getElementById(inputId) : inputId;
                if (!input) return;
                items = Array.isArray(items) ? items : [];
                options = options || {};
                var minChars = options.minChars != null ? options.minChars : 1;
                var maxResults = options.maxResults || 8;
                var emptyText = options.emptyText || 'Niciun rezultat.';
                var wrap = input.closest('.pz-search-wrap');
                if (!wrap) {
                    wrap = document.createElement('div');
                    wrap.className = 'pz-search-wrap';
                    var parent = input.parentNode;
                    parent.insertBefore(wrap, input);
                    wrap.appendChild(input);
                }
                var preview = wrap.querySelector('.pz-search-preview');
                if (!preview) {
                    preview = document.createElement('div');
                    preview.className = 'pz-search-preview';
                }
                // Atasam preview-ul direct in <body> ca sa scapam de orice containing
                // block creat de parinti (ex: backdrop-filter, transform, contain) care
                // sparge `position: fixed`. Asa dropdown-ul e mereu vizibil indiferent
                // de unde e apelat attach().
                if (preview.parentElement !== document.body) {
                    document.body.appendChild(preview);
                }
                items.forEach(function (it) {
                    if (!it._idx) {
                        it._idx = normalize((it.search || '') + ' ' + (it.title || ''));
                    }
                });
                function positionPreview() {
                    var rect = input.getBoundingClientRect();
                    preview.style.top = (rect.bottom + 4) + 'px';
                    preview.style.left = rect.left + 'px';
                    preview.style.width = rect.width + 'px';
                }
                function render() {
                    var q = normalize(input.value);
                    if (q.length < minChars) {
                        preview.classList.remove('open');
                        preview.innerHTML = '';
                        return;
                    }
                    var matches = [];
                    var terms = q.split(/\s+/).filter(Boolean);
                    for (var i = 0; i < items.length; i++) {
                        var ok = true;
                        for (var j = 0; j < terms.length; j++) {
                            if (items[i]._idx.indexOf(terms[j]) === -1) { ok = false; break; }
                        }
                        if (ok) { matches.push(items[i]); if (matches.length >= maxResults) break; }
                    }
                    if (matches.length === 0) {
                        preview.innerHTML = '<div class="pz-search-preview-empty">' + esc(emptyText) + '</div>';
                    } else {
                        preview.innerHTML = matches.map(function (m) { return buildItemHtml(m); }).join('');
                    }
                    positionPreview();
                    preview.classList.add('open');
                }
                input.addEventListener('input', render);
                input.addEventListener('focus', render);
                input.addEventListener('blur', function () {
                    setTimeout(function () { preview.classList.remove('open'); }, 150);
                });
                window.addEventListener('scroll', function () {
                    if (preview.classList.contains('open')) positionPreview();
                }, true);
                window.addEventListener('resize', function () {
                    if (preview.classList.contains('open')) positionPreview();
                });
                // Callback onSelect: cand utilizatorul click-uieste pe un item,
                // apelam options.onSelect(item) in loc de comportamentul implicit
                // (navigare prin <a href>). Util cand vrem sa completam un formular
                // in loc sa navigam pe pagina clientului.
                if (typeof options.onSelect === 'function') {
                    preview.addEventListener('mousedown', function (e) {
                        var item = e.target.closest('.pz-search-item');
                        if (!item || !preview.contains(item)) return;
                        e.preventDefault();
                        var nodes = preview.querySelectorAll('.pz-search-item');
                        var idx = Array.prototype.indexOf.call(nodes, item);
                        // Reconstruim lista de matches cu aceeasi logica de filtru ca render()
                        var q = normalize(input.value);
                        var terms = q.split(/\s+/).filter(Boolean);
                        var matches = [];
                        for (var k = 0; k < items.length && matches.length <= idx; k++) {
                            var ok = true;
                            for (var t = 0; t < terms.length; t++) {
                                if (items[k]._idx.indexOf(terms[t]) === -1) { ok = false; break; }
                            }
                            if (ok) { matches.push(items[k]); if (matches.length > idx) break; }
                        }
                        if (matches[idx]) {
                            try { options.onSelect(matches[idx]); } catch (err) { console.error(err); }
                            preview.classList.remove('open');
                            input.blur();
                        }
                    });
                }
            }
            window.pzSearchPreview = { attach: attach };
            document.addEventListener('click', function (e) {
                document.querySelectorAll('.pz-search-preview.open').forEach(function (pv) {
                    if (!pv.closest('.pz-search-wrap').contains(e.target)) pv.classList.remove('open');
                });
            });
        })();
        </script>
        <?php
    }
}

