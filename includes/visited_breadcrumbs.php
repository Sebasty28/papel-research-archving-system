<?php
/** The maroon strip follows the pages visited in this browser tab. */
if (defined('PAPEL_PAPER_PREVIEW')) return;
?>
<style nonce="<?= csp_nonce() ?>">
.crumb-trail { display: flex; align-items: center; min-width: 0; max-width: 100%;
    overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
.crumb-trail::-webkit-scrollbar { display: none; }
.crumb-trail a { flex: none; color: #fff; text-decoration: none; }
.crumb-trail a:hover { text-decoration: underline; }
.crumb-trail a[aria-current="page"] { font-weight: 700; }
.crumb-trail .crumb-arrow { flex: none; }
</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
    if (window.top !== window) return;
    var inner = document.querySelector('.crumb-bar .crumb-inner');
    if (!inner) return;
    var root = new URL(<?= json_encode(BASE_URL . '/archive/index.php') ?>, location.href).pathname;
    var key = <?= json_encode('papel.visitedBreadcrumbs.' . (int)(current_user()['user_id'] ?? 0)) ?>;
    var current = inner.querySelector('.crumb-current');
    var label = current ? current.textContent.trim() : document.title.split('·')[0].trim();
    var href = location.pathname + location.search;
    var identity = location.pathname === root && location.search ? root + '?search' : location.pathname;
    var trail = [];
    try {
        var saved = JSON.parse(sessionStorage.getItem(key) || '[]');
        if (Array.isArray(saved)) {
            trail = saved.filter(function (entry) {
                return entry && typeof entry.label === 'string' && typeof entry.href === 'string'
                    && entry.href.charAt(0) === '/' && entry.href.indexOf('//') !== 0;
            });
            if (trail.length > 20) trail = [trail[0]].concat(trail.slice(-19));
        }
    } catch (e) {}
    if (!trail.length || trail[0].href !== root) trail = [{label: 'Public Repository', href: root}];
    if (href === root) {
        trail = [{label: 'Public Repository', href: root}];
    } else {
        var position = trail.findIndex(function (entry) {
            return (entry.key || entry.href.split('?')[0]) === identity;
        });
        if (position >= 0) {
            trail = trail.slice(0, position + 1);
            trail[position] = {label: label || 'Page', href: href, key: identity};
        } else {
            trail.push({label: label || 'Page', href: href, key: identity});
        }
        if (trail.length > 20) trail.splice(1, trail.length - 20);
    }
    try { sessionStorage.setItem(key, JSON.stringify(trail)); } catch (e) {}

    var nav = document.createElement('nav');
    nav.className = 'crumb-trail';
    nav.setAttribute('aria-label', 'Visited pages');
    trail.forEach(function (entry, i) {
        if (i) {
            var arrow = document.createElement('span');
            arrow.className = 'material-symbols-outlined crumb-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            arrow.textContent = 'chevron_right';
            nav.appendChild(arrow);
        }
        var link = document.createElement('a');
        link.href = entry.href;
        link.textContent = entry.label;
        if (i === trail.length - 1) link.setAttribute('aria-current', 'page');
        nav.appendChild(link);
    });
    Array.from(inner.children).forEach(function (child) {
        if (child.matches('a, .crumb-arrow, .crumb-current')) child.remove();
    });
    inner.prepend(nav);
    nav.scrollLeft = nav.scrollWidth;
})();
</script>
