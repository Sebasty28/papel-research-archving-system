<button id="backBtn" class="btn btn-outline-secondary btn-sm" style="position:fixed;bottom:20px;right:20px;z-index:1000;box-shadow:0 2px 8px rgba(0,0,0,0.2)">← Back</button>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.getElementById('backBtn').addEventListener('click', function() { window.history.back(); });
</script>
