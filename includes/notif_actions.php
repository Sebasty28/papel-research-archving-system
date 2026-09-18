<?php
/**
 * Right-click a notification: mark it read or unread, or delete it.
 *
 * One menu serves both lists — the bell's dropdown (.notif-item) and the
 * notification centre (.nc-item) — because both already tag each row with
 * data-notif-id. Included from site_footer.php, which every page carries.
 *
 * Read/unread is a toggle rather than two entries: the menu offers whichever
 * one the row is not already in, so there is never a choice that does nothing.
 */
?>
<div class="notif-menu" id="notifMenu" role="menu" hidden>
    <button type="button" class="notif-menu-item" data-act="toggle" role="menuitem">
        <span class="material-symbols-outlined mi-18" data-icon></span>
        <span data-label>Mark as read</span>
    </button>
    <button type="button" class="notif-menu-item is-danger" data-act="delete" role="menuitem">
        <span class="material-symbols-outlined mi-18">delete</span>
        <span>Delete</span>
    </button>
</div>

<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.notif-menu {
    position: fixed;
    z-index: 2000;          /* over the dropdown it is opened from */
    min-width: 190px;
    padding: .25rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: 0 8px 24px rgba(51, 0, 0, .18);
    font-family: var(--font-body);
}
.notif-menu-item {
    display: flex;
    align-items: center;
    gap: .5rem;
    width: 100%;
    padding: .5rem .625rem;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: var(--ink);
    font: inherit;
    font-size: .8125rem;
    text-align: left;
    cursor: pointer;
}
.notif-menu-item:hover,
.notif-menu-item:focus-visible { background: var(--cream); }
.notif-menu-item.is-danger { color: var(--maroon); }
.notif-menu-item.is-danger:hover { background: var(--soft-maroon, var(--cream)); }
</style>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var menu = document.getElementById('notifMenu');
    if (!menu) { return; }

    var HANDLER = <?= json_encode(BASE_URL.'/notifications/notifications_handler.php') ?>;
    var TOKEN   = <?= json_encode(csrf_token()) ?>;
    var ROW     = '.notif-item, .nc-item';
    var target  = null;

    /* The two lists spell the unread state differently. */
    function isUnread(row) {
        return row.classList.contains('unread') || row.classList.contains('is-unread');
    }
    function setUnread(row, on) {
        row.classList.toggle(row.classList.contains('nc-item') ? 'is-unread' : 'unread', on);
    }

    function close() { menu.hidden = true; target = null; }

    function open(row, x, y) {
        target = row;
        var unread = isUnread(row);
        menu.querySelector('[data-label]').textContent =
            unread ? 'Mark as read' : 'Mark as unread';
        menu.querySelector('[data-icon]').textContent =
            unread ? 'mark_email_read' : 'mark_email_unread';

        // Placed off-screen first so it can be measured, then clamped inside it.
        menu.hidden = false;
        menu.style.left = '0px';
        menu.style.top = '0px';
        var r = menu.getBoundingClientRect();
        var maxX = window.innerWidth - r.width - 8;
        var maxY = window.innerHeight - r.height - 8;
        menu.style.left = Math.max(8, Math.min(x, maxX)) + 'px';
        menu.style.top  = Math.max(8, Math.min(y, maxY)) + 'px';
    }

    /* Every count on the page, from the figures the server just returned.
       There are four of them and they are easy to miss: the bell's badge, the
       dropdown's Unread tab, and — on the full-screen list — the "N in total,
       M unread" line and its own Unread tab. Deleting moves the total as well
       as the unread tally, which is why both are read back rather than the
       caller subtracting one and hoping. */
    function paintCounts(unread, total) {
        var toggle = document.getElementById('notifToggle');
        if (toggle) {
            var badge = toggle.querySelector('.notif-badge');
            if (!unread) {
                if (badge) { badge.remove(); }
            } else {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notif-badge';
                    toggle.appendChild(badge);
                }
                badge.textContent = unread > 9 ? '9+' : String(unread);
            }
        }

        var ddTab = document.querySelector('.notif-tab[data-filter="unread"]');
        if (ddTab) { ddTab.textContent = unread ? 'Unread (' + unread + ')' : 'Unread'; }

        var ncTab = document.querySelector('.nc-tab[data-filter="unread"]');
        if (ncTab) { ncTab.textContent = unread ? 'Unread (' + unread + ')' : 'Unread'; }

        var counts = document.getElementById('ncCounts');
        if (counts && typeof total === 'number') {
            counts.textContent = total + ' in total'
                + (unread ? ', ' + unread + ' unread' : '');
        }

        /* With nothing unread the only move left is to put it all back, and
           with nothing at all there is no move to offer. */
        var form = document.getElementById('ncBulkForm');
        if (form && typeof total === 'number') {
            form.hidden = (total === 0);
            var input = form.querySelector('[name="action"]');
            var icon  = form.querySelector('[data-mk-icon]');
            var label = form.querySelector('[data-mk-label]');
            var allRead = (unread === 0);
            if (input) { input.value = allRead ? 'mark_all_unread' : 'mark_all_read'; }
            if (icon)  { icon.textContent = allRead ? 'mark_email_unread' : 'done_all'; }
            if (label) { label.textContent = allRead ? 'Mark all as unread' : 'Mark all as read'; }
        }
    }

    function send(action, row) {
        var id = row.getAttribute('data-notif-id');
        if (!id) { return; }
        var body = 'action=' + encodeURIComponent(action)
                 + '&notification_id=' + encodeURIComponent(id)
                 + '&_token=' + encodeURIComponent(TOKEN);
        // Deleting is one of the workflows that shows the logo pill; marking
        // read or unread is a flick of a switch and does not.
        var pill = action === 'delete' && window.papelLoading ? window.papelLoading.work : null;
        if (pill) { pill.start(); }
        fetch(HANDLER, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (!data || !data.success) {
                  if (window.papelNote) {
                      window.papelNote(data && data.error
                          ? data.error : 'That could not be saved.', false);
                  }
                  return;
              }
              if (action === 'delete') {
                  row.remove();
              } else {
                  setUnread(row, action === 'mark_unread');
              }
              paintCounts(data.unread, data.total);
          })
          .catch(function () {
              if (window.papelNote) {
                  window.papelNote('That could not be saved.', false);
              }
          })
          .then(function () { if (pill) { pill.done(); } });
    }

    document.addEventListener('contextmenu', function (e) {
        var row = e.target.closest && e.target.closest(ROW);
        if (!row) { return; }
        e.preventDefault();          // ours instead of the browser's
        open(row, e.clientX, e.clientY);
    });

    menu.addEventListener('click', function (e) {
        var item = e.target.closest('.notif-menu-item');
        if (!item || !target) { return; }
        /* The document-level handler in site_footer.php closes the bell's
           dropdown on any outside click; without this the list would shut
           before the row had visibly changed. */
        e.stopPropagation();
        var row = target;
        send(item.dataset.act === 'delete'
                ? 'delete'
                : (isUnread(row) ? 'mark_read' : 'mark_unread'), row);
        close();
    });

    document.addEventListener('click', function (e) {
        if (!menu.hidden && !e.target.closest('#notifMenu')) { close(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { close(); }
    });
    window.addEventListener('scroll', function () {
        if (!menu.hidden) { close(); }
    }, true);
})();
</script>
