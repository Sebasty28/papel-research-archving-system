<?php
/* Upload Paper has Corpus in its own editor pane. Other pages share this
   floating version and the same assistant endpoint. */
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'student_upload_ai.php' || defined('PAPEL_PAPER_PREVIEW')) return;
require_once __DIR__ . '/corpus_knowledge.php';
require_once ROOT_PATH . '/ai/corpus_guard.php';
$corpusViewer = current_user();
$corpusStorageKey = corpus_history_key($corpusViewer);
/* The ready questions for this page and reader, answers included, so a press
   answers at once with no round trip. They are the same for every reader of
   the page (corpus_faq.php), which is what makes them safe to print. */
$corpusChips = corpus_faq_chips((string)($_SERVER['REQUEST_URI'] ?? ''), $corpusViewer);
?>
<style nonce="<?= e(csp_nonce()) ?>">
#corpusGlobal { position: fixed; right: 1rem; bottom: 1rem; z-index: 901; display: flex; flex-direction: column; align-items: flex-end; font-family: var(--font-body); }
#corpusGlobal * { box-sizing: border-box; }
#corpusGlobal .corpus-launcher { width: 3.5rem; height: 3.5rem; padding: 0; overflow: hidden; border: 1px solid var(--border); border-radius: 50%; background: var(--white); box-shadow: var(--shadow-md); cursor: pointer; }
#corpusGlobal .corpus-launcher img { display: block; width: 100%; height: 100%; object-fit: cover; }
#corpusGlobal .corpus-launcher:focus-visible, #corpusGlobal button:focus-visible, #corpusGlobal input:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
#corpusGlobal.is-open .corpus-launcher { display: none; }
#corpusGlobalPanel { width: min(24rem, calc(100vw - 1rem)); height: min(33rem, var(--corpus-room, 33rem)); min-height: 10rem; display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border); border-radius: 8px; background: var(--white); color: var(--ink); box-shadow: var(--shadow-md); }
#corpusGlobalPanel[hidden] { display: none; }
.corpus-global-head { display: flex; align-items: center; gap: .625rem; padding: .75rem; border-bottom: 1px solid var(--border); background: var(--cream); }
.corpus-global-head img { width: 2rem; height: 2rem; object-fit: cover; border-radius: 50%; }
.corpus-global-title { min-width: 0; flex: 1; }
.corpus-global-title strong { display: block; font: 600 .875rem/1.2 var(--font-head); color: var(--maroon); }
.corpus-global-title small { display: block; margin-top: .125rem; font-size: .6875rem; color: var(--grey); }
.corpus-global-close { width: 2rem; height: 2rem; display: grid; place-items: center; padding: 0; border: 0; border-radius: 5px; background: transparent; color: var(--ink); cursor: pointer; }
.corpus-global-close:hover { background: var(--border); }
/* The ready questions a new conversation opens with. Lettered in --maroon on
   --white, which holds in both palettes (white on the dark surface). */
.corpus-faq { display: flex; flex-direction: column; align-items: flex-start; gap: .375rem; }
.corpus-faq-label { font-size: .6875rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--grey); }
.corpus-faq-chip { max-width: 100%; padding: .4375rem .75rem; border: 1px solid var(--border); border-radius: 999px; background: var(--white); color: var(--maroon); font: 500 .8125rem/1.3 var(--font-body); text-align: left; cursor: pointer; }
.corpus-faq-chip:hover { border-color: var(--maroon); }
#corpusGlobal .corpus-faq-chip:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.corpus-global-messages { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: .625rem; overflow: auto; padding: .875rem; overscroll-behavior: contain; }
.corpus-global-message { max-width: 90%; padding: .625rem .75rem; border-radius: 8px; font-size: .8125rem; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.corpus-global-message.is-assistant { align-self: flex-start; background: var(--cream); color: var(--ink); }
/* Corpus's replies are justified, as the site's pop-up messages are (the owner's
   request). Hyphenated, because a bubble this narrow holds six or seven words a
   line and justifying that without breaking a long word opens rivers. A line that
   ends at a newline (a list item, a short paragraph) is a last line and is left
   ranged left, so lists do not stretch. The reader's own messages are untouched. */
.corpus-global-message.is-assistant { text-align: justify; hyphens: auto; -webkit-hyphens: auto; }
.corpus-global-message.is-user { align-self: flex-end; background: var(--maroon-surface, var(--maroon)); color: #fff; }
.corpus-global-message.is-waiting { color: var(--grey); }
.corpus-global-form { display: flex; align-items: center; gap: .5rem; padding: .75rem; border-top: 1px solid var(--border); }
.corpus-global-form input { flex: 1; min-width: 0; height: 2.5rem; padding: .5rem .625rem; border: 1px solid var(--border); border-radius: 5px; background: var(--white); color: var(--ink); font: inherit; font-size: .8125rem; }
.corpus-global-form button { width: 2.5rem; height: 2.5rem; flex: none; display: grid; place-items: center; padding: 0; border: 0; border-radius: 5px; background: var(--maroon-surface, var(--maroon)); color: #fff; cursor: pointer; }
.corpus-global-form button:disabled { opacity: .55; cursor: wait; }
@media (max-width: 600px) { #corpusGlobal { right: .5rem; } #corpusGlobalPanel { width: calc(100vw - 1rem); } }
</style>
<aside id="corpusGlobal" aria-label="Corpus assistant">
    <section id="corpusGlobalPanel" role="dialog" aria-modal="false" aria-labelledby="corpusGlobalTitle" hidden>
        <div class="corpus-global-head">
            <img src="<?= e(BASE_URL) ?>/assests/images/papel_red_welcome.gif" alt="">
            <div class="corpus-global-title"><strong id="corpusGlobalTitle">Corpus</strong><small>Ask about PAPEL and this page</small></div>
            <button type="button" class="corpus-global-close" id="corpusGlobalNew" aria-label="New chat" title="New chat"><span class="material-symbols-outlined">edit_square</span></button>
            <button type="button" class="corpus-global-close" id="corpusGlobalClose" aria-label="Close Corpus"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="corpus-global-messages" id="corpusGlobalMessages" role="log" aria-live="polite" aria-relevant="additions text"></div>
        <form class="corpus-global-form" id="corpusGlobalForm">
            <input id="corpusGlobalInput" type="text" maxlength="<?= CorpusGuard::MAX_INPUT ?>" autocomplete="off" aria-label="Ask Corpus" placeholder="Ask about this page..." required>
            <button type="submit" id="corpusGlobalSend" aria-label="Send message"><span class="material-symbols-outlined">send</span></button>
        </form>
    </section>
    <button type="button" class="corpus-launcher" id="corpusGlobalLaunch" aria-label="Ask Corpus" aria-controls="corpusGlobalPanel" aria-expanded="false" title="Ask Corpus">
        <img src="<?= e(BASE_URL) ?>/assests/images/papel_red_welcome.gif" alt="">
    </button>
</aside>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var widget = document.getElementById('corpusGlobal');
    var panel = document.getElementById('corpusGlobalPanel');
    var launch = document.getElementById('corpusGlobalLaunch');
    var close = document.getElementById('corpusGlobalClose');
    var form = document.getElementById('corpusGlobalForm');
    var input = document.getElementById('corpusGlobalInput');
    var send = document.getElementById('corpusGlobalSend');
    var messages = document.getElementById('corpusGlobalMessages');
    var endpoint = <?= json_encode(BASE_URL . '/app/student/student_assistant.php') ?>;
    var token = <?= json_encode(csrf_token()) ?>;
    var storageKey = <?= json_encode($corpusStorageKey) ?>;
    var chips = <?= json_encode($corpusChips, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var newChat = document.getElementById('corpusGlobalNew');
    /* The page's own copy of CorpusGuard's credential check (ai/corpus_guard.php,
       printed from the same list, so the two cannot drift). A message that looks
       like a password, a key or a code is refused here, before it leaves the
       device: never sent, never shown in a bubble, never saved in the browser's
       copy of the conversation. The server checks again; this is only the first. */
    var secretShapes = <?= json_encode(CorpusGuard::credentialPatternsForJs(), JSON_HEX_TAG | JSON_HEX_AMP) ?>.map(function (p) {
        try { return new RegExp(p.s, p.f); } catch (e) { return null; }
    }).filter(Boolean);
    function looksSecret(text) { return secretShapes.some(function (re) { return re.test(text); }); }
    var SECRET_NOTE = 'For your safety, Corpus never accepts passwords, codes or keys, so that message was **not sent or saved**.\n\nIf it was a real password, change it now in **Settings > Security**.';
    var history = [];
    var busy = false;
    try {
        var saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
        if (Array.isArray(saved)) history = saved.filter(function (line) {
            return line && (line.role === 'user' || line.role === 'assistant') && typeof line.content === 'string' && line.content.length <= 2000;
        }).slice(-12);
    } catch (error) {}

    function add(content, role, save) {
        var bubble = document.createElement('div');
        bubble.className = 'corpus-global-message is-' + role;
        /* Model emphasis stays readable without treating a reply as HTML. */
        String(content).split(/(\*\*[^*]+\*\*)/g).forEach(function (part) {
            if (part.slice(0, 2) === '**' && part.slice(-2) === '**') {
                var strong = document.createElement('strong');
                strong.textContent = part.slice(2, -2);
                bubble.appendChild(strong);
            } else bubble.appendChild(document.createTextNode(part));
        });
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;
        if (save) {
            history.push({ role: role, content: String(content) });
            history = history.slice(-12);
            try { sessionStorage.setItem(storageKey, JSON.stringify(history)); } catch (error) {}
        }
        return bubble;
    }
    /* Tells the server about a turn the page has already shown, so the AI has
       it as context next time. Nothing waits on it and a failure loses nothing. */
    function tell(body) {
        body._token = token;
        body.page = location.pathname + location.search;
        fetch(endpoint, { method: 'POST', credentials: 'same-origin', papelQuiet: true,
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).catch(function () {});
    }
    function dropChips() {
        var box = document.getElementById('corpusGlobalFaq');
        if (box) box.remove();
    }
    /* Only on a new conversation: once anything has been asked they are in the
       way, and New chat brings them back. Answered from the page at once. */
    function showChips() {
        if (!chips.length) return;
        var box = document.createElement('div');
        box.className = 'corpus-faq';
        box.id = 'corpusGlobalFaq';
        var label = document.createElement('span');
        label.className = 'corpus-faq-label';
        label.textContent = 'Common questions';
        box.appendChild(label);
        chips.forEach(function (faq) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'corpus-faq-chip';
            chip.textContent = faq.q;
            chip.addEventListener('click', function () {
                if (busy) return;
                dropChips();
                add(faq.q, 'user', true);
                add(faq.a, 'assistant', true);
                tell({ faq: faq.id });
                input.focus();
            });
            box.appendChild(chip);
        });
        messages.appendChild(box);
    }
    function greet() {
        add('Hi! I’m Corpus. Ask me about PAPEL or the page you’re viewing.', 'assistant', false);
        showChips();
    }
    if (history.length) history.forEach(function (line) { add(line.content, line.role, false); });
    else greet();
    newChat.addEventListener('click', function () {
        if (busy) return;
        history = [];
        try { sessionStorage.removeItem(storageKey); } catch (error) {}
        messages.textContent = '';
        greet();
        tell({ reset: true });
        input.focus();
    });

    function position() {
        var footer = document.querySelector('.site-footer, footer.footer');
        var footerBox = footer && footer.getClientRects().length ? footer.getBoundingClientRect() : null;
        var footerOverlap = footerBox && footerBox.bottom > 0 && footerBox.top < innerHeight
            ? Math.min(footerBox.height, innerHeight - Math.max(0, footerBox.top)) : 0;
        var lift = footerOverlap + 12;
        var header = document.getElementById('siteHeader');
        var top = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
        widget.style.bottom = lift + 'px';
        panel.style.setProperty('--corpus-room', Math.max(160, innerHeight - lift - top - 12) + 'px');
    }
    function setOpen(open) {
        panel.hidden = !open;
        widget.classList.toggle('is-open', open);
        launch.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { position(); input.focus(); messages.scrollTop = messages.scrollHeight; }
        else launch.focus();
    }
    launch.addEventListener('click', function () { setOpen(true); });
    close.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); setOpen(false); }
    });
    window.addEventListener('scroll', position, { passive: true });
    window.addEventListener('resize', position, { passive: true });
    position();

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var message = input.value.trim();
        if (!message || busy) return;
        if (looksSecret(message)) {
            input.value = '';
            add(SECRET_NOTE, 'assistant', false);
            return;
        }
        dropChips();
        /* Kept in the browser's copy only once the server has accepted it, so a
           message it refuses (a credential the check above missed) is never
           written to sessionStorage. */
        var mine = add(message, 'user', false);
        input.value = '';
        busy = true;
        send.disabled = true;
        var waiting = add('Corpus is thinking…', 'assistant', false);
        waiting.classList.add('is-waiting');
        /* The address only. The server reads the page's meaning from it and,
           for a published paper, its public record; nothing on screen is sent,
           since a desk or console shows other people's records. */
        fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', papelQuiet: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message, _token: token, page: location.pathname + location.search })
        }).then(function (response) { return response.json().then(function (result) { return { ok: response.ok, result: result }; }); })
          .then(function (answer) {
              if (answer.result.blocked === 'credential') {
                  mine.textContent = 'Message removed for your safety.';
                  mine.classList.add('is-waiting');
              } else if (answer.ok) {
                  history.push({ role: 'user', content: message });
              }
              add(answer.result.reply || 'Corpus could not answer right now.', 'assistant', answer.ok);
          })
          .catch(function () { add('Corpus could not connect. Please try again.', 'assistant', false); })
          .finally(function () { waiting.remove(); busy = false; send.disabled = false; input.focus(); });
    });
})();
</script>
