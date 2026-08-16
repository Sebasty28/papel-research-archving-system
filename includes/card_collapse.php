<?php
/**
 * Collapsible cards.
 *
 * Gives any card with a heading a chevron that folds its contents away, the
 * same idea as the sidebar cards on the dashboards. A long record page is
 * easier to move around when the parts you are not reading can be shut.
 *
 * The wrapping is done here rather than in each page's markup, so a card only
 * has to be a card — it needs no extra div and no extra class.
 *
 * Usage, before the include:
 *   $CARD_COLLAPSE_SELECTOR = '.pd-card, .set-card';
 *   require ROOT_PATH.'/includes/card_collapse.php';
 */
$CARD_COLLAPSE_SELECTOR = $CARD_COLLAPSE_SELECTOR ?? '.pd-card';
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
[data-collapse-host] > h2 {
    display: flex;
    align-items: center;
    gap: .4rem;
}
.card-collapse-btn {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    padding: .15rem;
    border: none;
    border-radius: 6px;
    background: none;
    color: var(--maroon);
    cursor: pointer;
    transition: background .15s;
}
.card-collapse-btn:hover { background: var(--cream); }
.card-collapse-btn:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.card-collapse-btn .material-symbols-outlined {
    font-size: 20px;
    transition: transform .18s ease;
}
/* Collapsed shows ">", the way a closed section reads everywhere else. */
[data-collapsed="1"] .card-collapse-btn .material-symbols-outlined { transform: rotate(-90deg); }
[data-collapsed="1"] .card-collapse-body { display: none; }
[data-collapsed="1"] > h2 { margin-bottom: 0 !important; }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var SELECTOR = <?= json_encode($CARD_COLLAPSE_SELECTOR) ?>;

    document.querySelectorAll(SELECTOR).forEach(function (card) {
        var head = card.querySelector(':scope > h2');
        if (!head) return;                       // nothing to hang a control on

        // Everything after the heading becomes the part that folds away.
        var body = document.createElement('div');
        body.className = 'card-collapse-body';
        var node = head.nextSibling;
        while (node) {
            var next = node.nextSibling;
            body.appendChild(node);
            node = next;
        }
        card.appendChild(body);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'card-collapse-btn';
        btn.setAttribute('aria-expanded', 'true');
        btn.title = 'Hide this section';
        btn.setAttribute('aria-label', 'Hide ' + head.textContent.trim());
        btn.innerHTML = '<span class="material-symbols-outlined">expand_more</span>';
        head.appendChild(btn);

        card.setAttribute('data-collapse-host', '');
        card.setAttribute('data-collapsed', '0');

        btn.addEventListener('click', function () {
            var closed = card.getAttribute('data-collapsed') === '1';
            card.setAttribute('data-collapsed', closed ? '0' : '1');
            btn.setAttribute('aria-expanded', closed ? 'true' : 'false');
            btn.title = closed ? 'Hide this section' : 'Show this section';
        });
    });
});
</script>
