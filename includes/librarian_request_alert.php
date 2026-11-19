<?php
/** Poll only for a signed-in Librarian, on whichever site page is open. */
if (!user_has_role(current_user(), 'librarian') || defined('PAPEL_PAPER_PREVIEW')) return;
?>
<div class="mr-live-backdrop" id="mrLiveBackdrop" hidden>
    <div class="mr-live-card" role="dialog" aria-modal="true" aria-labelledby="mrLiveTitle">
        <div class="mr-live-head">
            <strong id="mrLiveTitle">New manuscript requests</strong>
            <button type="button" id="mrLiveClose" aria-label="Close manuscript request notice">
                <span class="material-symbols-outlined mi-20" aria-hidden="true">close</span>
            </button>
        </div>
        <p id="mrLiveSummary" class="mr-live-summary"></p>
        <div id="mrLiveList" class="mr-live-list" aria-live="polite"></div>
        <a class="mr-live-open" id="mrLiveOpen" href="<?= e(BASE_URL) ?>/app/librarian/manuscript_requests.php">View manuscript requests</a>
    </div>
</div>
<style nonce="<?= csp_nonce() ?>">
.mr-live-backdrop { position: fixed; inset: 0; z-index: 1151; display: flex; align-items: center;
    justify-content: center; padding: 1rem; background: rgba(51,0,0,.4); backdrop-filter: blur(3px); }
.mr-live-backdrop[hidden] { display: none; }
.mr-live-card { width: min(100%, 27rem); max-height: min(32rem, 82dvh); display: flex;
    flex-direction: column; overflow: hidden; border-radius: var(--r-card, 8px);
    background: var(--white); color: var(--ink); box-shadow: var(--shadow-md); }
.mr-live-head { display: flex; align-items: center; justify-content: space-between;
    gap: .75rem; padding: .875rem 1rem; border-bottom: 1px solid var(--border); color: var(--maroon); }
.mr-live-head button { display: grid; place-items: center; flex: none; width: 2rem; height: 2rem;
    border: 0; border-radius: var(--r-control, 4px); background: transparent; color: inherit; cursor: pointer; }
.mr-live-head button:hover { background: var(--cream); }
.mr-live-summary { margin: 0; padding: .75rem 1rem .25rem; font-size: .8125rem; text-align: justify; }
.mr-live-list { overflow-y: auto; min-height: 0; padding: .25rem 1rem .75rem; }
.mr-live-item { display: block; padding: .625rem 0; border-bottom: 1px solid var(--border);
    color: var(--ink); text-decoration: none; font-size: .8125rem; }
.mr-live-item:last-child { border-bottom: 0; }
.mr-live-item:hover .mr-live-paper { text-decoration: underline; }
.mr-live-name { display: block; font-weight: 700; }
.mr-live-paper { display: block; color: var(--maroon); }
.mr-live-open { display: block; flex: none; padding: .8rem 1rem; background: var(--maroon);
    color: #fff; text-align: center; text-decoration: none; font-weight: 600; }
.mr-live-open:hover { background: var(--dark-maroon); color: #fff; }
@media (max-width: 600px) { .mr-live-card { max-height: 75dvh; } }
</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var backdrop = document.getElementById('mrLiveBackdrop');
    var list = document.getElementById('mrLiveList');
    var summary = document.getElementById('mrLiveSummary');
    var title = document.getElementById('mrLiveTitle');
    var closeButton = document.getElementById('mrLiveClose');
    var key = 'papel.manuscript.seen.<?= (int)current_user()['user_id'] ?>';
    var seen = 0;
    try { seen = Math.max(0, Number(sessionStorage.getItem(key)) || 0); } catch (e) {}
    var latestShown = seen;
    var busy = false;
    var signedOut = false;
    var waitingForOther = null;
    var queuedCount = 0;
    var lastRendered = '';
    var endpoint = <?= json_encode(BASE_URL . '/app/librarian/manuscript_requests_live.php') ?>;

    function close() {
        backdrop.hidden = true;
        seen = Math.max(seen, latestShown);
        try { sessionStorage.setItem(key, String(seen)); } catch (e) {}
    }
    closeButton.addEventListener('click', close);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    document.getElementById('mrLiveOpen').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !backdrop.hidden) close();
    });

    function paint(data) {
        if (!data || !Array.isArray(data.requests)) return;
        var count = Number(data.new_count) || 0;
        queuedCount = count;
        if (!count) {
            if (!backdrop.hidden) close();
            return;
        }
        var fingerprint = count + ':' + data.requests.map(function (r) { return r.request_id; }).join(',');
        if (fingerprint === lastRendered) return;
        lastRendered = fingerprint;
        latestShown = Math.max(latestShown, Number(data.latest_id) || 0);
        title.textContent = count + ' new manuscript request' + (count === 1 ? '' : 's');
        summary.textContent = count > data.requests.length
            ? 'Showing the latest ' + data.requests.length + ' of ' + count + ' requests.'
            : 'Students are waiting for manuscript access.';
        list.replaceChildren();
        data.requests.forEach(function (request) {
            var item = document.createElement('a');
            item.className = 'mr-live-item';
            item.href = <?= json_encode(BASE_URL . '/app/librarian/manuscript_requests.php') ?>
                + '?request=' + encodeURIComponent(request.request_id);
            var name = document.createElement('span');
            name.className = 'mr-live-name';
            name.textContent = request.student_name;
            var paper = document.createElement('span');
            paper.className = 'mr-live-paper';
            paper.textContent = request.paper_title;
            item.append(name, paper);
            item.addEventListener('click', close);
            list.appendChild(item);
        });
        /* The sign-in notification dialog may already be open. Show the queue
           once that dialog is dismissed so neither modal covers the other. */
        var other = document.getElementById('notifPopupBackdrop');
        if (other) {
            if (waitingForOther !== other) {
                waitingForOther = other;
                var watch = new MutationObserver(function () {
                    if (!document.body.contains(other)) {
                        watch.disconnect();
                        waitingForOther = null;
                        if (queuedCount > 0) backdrop.hidden = false;
                    }
                });
                watch.observe(document.body, {childList: true});
            }
        } else {
            backdrop.hidden = false;
        }
    }

    function poll() {
        if (busy || signedOut || document.hidden) return;
        busy = true;
        fetch(endpoint + '?since=' + encodeURIComponent(seen), {
            credentials: 'same-origin', cache: 'no-store', papelQuiet: true,
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            if (response.status === 401 || response.status === 403) signedOut = true;
            return response.ok ? response.json() : null;
        })
          .then(paint).catch(function () {}).finally(function () { busy = false; });
    }
    poll();
    setInterval(poll, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>
