<?php /** Quiet validation for account forms. The final POST still validates before writing. */ ?>
<style nonce="<?= csp_nonce() ?>">
.account-preflight-error { display:block; margin:.4rem 0; padding:.45rem .6rem; border:1px solid var(--bad-border, #b33); border-radius:4px; background:var(--bad-bg, #fff1f1); color:var(--bad-text, #8b1717); font-size:.9rem; }
form[data-account-preflight] [aria-invalid="true"] { border-color:var(--bad-border, #b33) !important; }
</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
    document.querySelectorAll('form[data-account-preflight]').forEach(function (form) {
        var pending = false;
        var currentNote = null;
        var currentField = null;
        var button = form.querySelector('[type="submit"]');
        var buttonText = button ? button.textContent : '';
        function busy(label) {
            if (!button || !form.hasAttribute('data-profile-save-json')) return;
            button.disabled = !!label;
            button.textContent = label || buttonText;
        }
        function clear() {
            if (currentNote) { currentNote.remove(); currentNote = null; }
            if (currentField) {
                currentField.removeEventListener('input', clear);
                currentField.removeAttribute('aria-invalid');
                currentField = null;
            }
        }
        function report(message, name) {
            clear();
            var field = name ? form.elements.namedItem(name) : null;
            if (!field || !field.tagName) field = null;
            var note = document.createElement('span');
            note.className = 'account-preflight-error';
            note.setAttribute('role', 'alert');
            note.textContent = message || 'Please check these details and try again.';
            currentNote = note;
            if (field) {
                currentField = field;
                field.setAttribute('aria-invalid', 'true');
                (field.closest('.mgmt-field, .profile-field, .page-field') || field).insertAdjacentElement('afterend', note);
                field.addEventListener('input', clear);
                field.focus();
            } else {
                var button = form.querySelector('[type="submit"]');
                (button || form).insertAdjacentElement('beforebegin', note);
            }
        }
        form.addEventListener('input', function (e) {
            if (e.target && e.target.hasAttribute('aria-invalid')) clear();
        });
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            e.preventDefault();
            if (pending) return;
            clear();
            var data = new FormData(form);
            data.set('_preflight_kind', form.dataset.accountPreflight);
            pending = true;
            var stage = 'check';
            busy('Checking details...');
            fetch(<?= json_encode(BASE_URL . '/app/account_preflight.php') ?>, {
                method: 'POST', credentials: 'same-origin', body: data,
                headers: { 'Accept': 'application/json' }, papelQuiet: true
            }).then(function (response) { return response.json(); })
              .then(function (result) {
                  if (!result.ok) { report(result.message, result.field); return; }
                  if (form.hasAttribute('data-profile-save-json')) {
                      stage = 'save';
                      busy('Saving changes...');
                      data.set('_async_profile', '1');
                      return fetch(form.action, {
                          method: 'POST', credentials: 'same-origin', body: data,
                          headers: { 'Accept': 'application/json' }, papelQuiet: true
                      }).then(function (response) { return response.json(); })
                        .then(function (saved) {
                            if (!saved.ok) { report(saved.message, saved.field); return; }
                            /* The next page reads the saved row and its success flash.
                               Only a completed save starts navigation and its loader. */
                            location.hash = 'personal';
                            location.reload();
                        });
                  }
                  /* Other account forms retain their normal POST after validation. */
                  form.submit();
              }).catch(function () {
                  report(stage === 'check' ? 'Could not check these details. Please try again.'
                                           : 'Could not save these details. Please try again.');
              }).finally(function () { pending = false; busy(''); });
        });
    });
})();
</script>
