<?php
/**
 * The three boxes a person changes their own password in, and the eye that
 * shows one.
 *
 * Two pages ask for it: pages/settings.php, where it is one section of an
 * account's own page, and pages/change_password.php, the gate a first sign-in
 * is held at (see "The first sign-in, and the password gate" in
 * config/core.php). It is one include rather than a twin of the markup in each
 * because the twins on this site are the things that drift: the generated
 * password has one (includes/password_generator.php and
 * account_import_password()) and it is written down twice over as a warning.
 *
 * The rules behind it are shared too: password_change_apply() in
 * config/core.php does the checking, the update, the roll and the notice, so a
 * page here only collects and reports.
 *
 * Include inside <head>, after includes/page_theme.php, which supplies
 * .page-field and the rest of the field styling. Then, inside the page's own
 * <form>, call:
 *
 *     password_form_fields();
 *
 * and give the form its own hidden action, its own button and whatever it
 * wants to say about who will be told.
 */

if (!function_exists('password_form_fields')) {
    /**
     * @param string $hint the line under the new-password box.
     */
    function password_form_fields(string $hint = 'At least 8 characters.'): void
    {
        $boxes = [
            ['current_password', 'Current password', 'current-password', ''],
            ['new_password',     'New password',     'new-password',     $hint],
            ['confirm_password', 'Confirm new password', 'new-password', ''],
        ];
        foreach ($boxes as [$name, $label, $auto, $note]) {
            $min = $name === 'current_password' ? '' : ' minlength="8"';
            echo '<div class="page-field">'
               . '<label for="' . e($name) . '">' . e($label) . '</label>'
               . '<div class="pw-wrap">'
               . '<input type="password" name="' . e($name) . '" id="' . e($name) . '" required'
               . $min . ' autocomplete="' . e($auto) . '">'
               . '<button type="button" class="pw-eye" data-eye="' . e($name) . '"'
               . ' aria-label="Show password" title="Show password" aria-pressed="false">'
               . '<span class="material-symbols-outlined">visibility</span>'
               . '</button>'
               . '</div>';
            if ($note !== '') { echo '<p class="pw-hint">' . e($note) . '</p>'; }
            echo '</div>';
        }
    }
}

/* The stylesheet and the eye go out once a page. */
if (defined('PAPEL_PASSWORD_FORM_PRINTED')) { return; }
define('PAPEL_PASSWORD_FORM_PRINTED', true);
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The boxes themselves come from the shared .page-field styles
   (includes/page_theme.php). The width is held on the boxes rather than on the
   form: a password box as wide as the card looks like a search bar, but the
   note underneath is a sentence, and at 420px it broke onto a second line with
   most of the card still empty beside it. It now runs to the card's padding
   like any text. */
.pw-form .page-field { max-width: 420px; }
/* A password you cannot see is a password you cannot check, and these are
   typed three times over. The eye sits inside the field rather than beside it
   so the row keeps its shape. */
.pw-wrap { position: relative; display: block; }
.pw-wrap input { width: 100%; padding-right: 2.6rem; }
.pw-eye {
    position: absolute;
    top: 50%;
    right: .5rem;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: var(--grey);
    cursor: pointer;
    transition: color .15s, background .15s;
}
.pw-eye:hover { color: var(--maroon); background: var(--cream); }
.pw-eye:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.pw-eye .material-symbols-outlined { font-size: 19px; }
.pw-eye[aria-pressed="true"] { color: var(--maroon); }

/* Who will be told, said on the form as well as in the dialog, so it is not a
   surprise that only appears at the moment of committing. */
.pw-notify { display: flex; align-items: flex-start; gap: .375rem; margin-top: .75rem; }
.pw-notify .material-symbols-outlined { font-size: 16px; color: var(--maroon); flex: 0 0 auto; }

.pw-hint { font-size: .75rem; color: var(--grey); margin-top: .3rem; }
@media (prefers-reduced-motion: reduce) { .pw-eye { transition: none; } }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Show or hide one password field. Each eye names its own field, so the three
   on a form never get in each other's way, and the state is announced through
   aria-pressed rather than by the icon alone. Delegated, so a form drawn after
   this script has run is wired too. */
document.addEventListener('click', function (e) {
    var eye = e.target.closest ? e.target.closest('.pw-eye') : null;
    if (!eye) { return; }
    var field = document.getElementById(eye.getAttribute('data-eye'));
    if (!field) { return; }
    var showing = field.type === 'text';
    field.type = showing ? 'password' : 'text';
    eye.setAttribute('aria-pressed', showing ? 'false' : 'true');
    eye.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    eye.title = showing ? 'Show password' : 'Hide password';
    var glyph = eye.querySelector('.material-symbols-outlined');
    if (glyph) { glyph.textContent = showing ? 'visibility' : 'visibility_off'; }
    /* The caret goes back where it was; toggling the type moves it to the end
       otherwise, which is maddening halfway through a password. */
    var at = field.value.length;
    field.focus();
    try { field.setSelectionRange(at, at); } catch (err) {}
});
</script>
