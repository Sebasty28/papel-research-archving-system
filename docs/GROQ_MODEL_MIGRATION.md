# Groq Model Migration — Llama 3.3 70B → GPT OSS 120B

**Applied on this machine:** 2026-08-15
**Reason:** Groq decommissioned `llama-3.3-70b-versatile` on **2026-08-16**. After that date
requests to it fail, which would break AI metadata extraction, IMRaD analysis, the
similarity/plagiarism check, and both chatbots.
**Replacement:** `openai/gpt-oss-120b`

> This supersedes the original migration note. Section 7 of that version reported the
> analysis step as a clean pass; it is not reliably clean on a free tier, and
> [Section 6](#6-rate-limits--the-part-the-original-doc-got-wrong) explains why and what
> was changed in the code to deal with it.

---

## 1. TL;DR

| # | File | What changed |
|---|------|--------------|
| 1 | `.env` | `GROQ_MODEL=openai/gpt-oss-120b` — **the one that actually takes effect** |
| 2 | `.env.example` | same value |
| 3 | `config/.env.example` | same value |
| 4 | `config/groq_config.php` | default model, JSON mode, rate-limit backoff |
| 5 | `ai/groq_config.php` | default model (unused duplicate, kept in sync) |
| 6 | `ai/help_chatbot.php` | hardcoded model → `GROQ_MODEL` |
| 7 | `app/student/student_chatbot.php` | hardcoded model → `GROQ_MODEL` |
| 8 | `.gitignore` | ignore `.env.bak-*` |

**The trap:** the model was hardcoded in two chatbot files that never read `GROQ_MODEL`, and
the live `.env` overrode the PHP default. Changing only `config/groq_config.php` looks correct
and changes nothing.

---

## 2. Why GPT OSS 120B

The model never sees a PDF — `extract_pdf_text()` parses it locally with `Smalot\PdfParser`
and passes plain text. So the requirement is text-in / JSON-out, not document vision.

The heaviest calls are structured extraction over long inputs:

| Function | Input | Output |
|---|---|---|
| `extract_metadata_with_groq()` | 8,000 chars | JSON: title, authors, year, keywords |
| `generate_statistical_analysis()` | 20,000 chars (~6.3k tokens) | JSON: 6 IMRaD fields, `max_tokens` 2000 |
| `check_similarity_groq()` | N abstracts | JSON: similarity %, index, reason |

That needs a model in the same class as Llama 3.3 70B. GPT OSS 120B is the closest match, and
one model everywhere keeps the config to a single line.

---

## 3. Config + env changes

### 3.1 `.env` — first, because it overrides everything else

```bash
cp .env ".env.bak-$(date +%Y%m%d)"
```

```diff
- GROQ_MODEL=llama-3.3-70b-versatile
+ GROQ_MODEL=openai/gpt-oss-120b
```

### 3.2 `.env.example` and `config/.env.example`

Same one-line change in both.

### 3.3 `config/groq_config.php` — the live config

```diff
- define('GROQ_MODEL', $_ENV['GROQ_MODEL'] ?? 'llama-3.3-70b-versatile');
+ define('GROQ_MODEL', $_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-120b');
```

### 3.4 `ai/groq_config.php` — unused duplicate

Nothing `require`s this file; every page loads `config/groq_config.php`. Kept in sync so a
future reader does not copy the stale default.

### 3.5 `.gitignore`

```gitignore
# Local .env backups (they contain live API keys)
.env.bak-*
```

⚠️ **Check the file ends with a newline before appending.** It did not on this machine either —
appending blind produces `Thumbs.db.env.bak-*` and silently breaks **both** rules. Verify:

```bash
git check-ignore -v .env.bak-20260815    # → .gitignore:33:.env.bak-*
git check-ignore -v Thumbs.db            # → .gitignore:30:Thumbs.db
```

---

## 4. Un-hardcoding the chatbots

Both chatbots build their own payload and never read `GROQ_MODEL`, so they would keep
requesting the dead model after the config change.

`ai/help_chatbot.php`:

```diff
  require_once '../config/core.php';
+ require_once __DIR__ . '/../config/groq_config.php'; // GROQ_MODEL + the dedicated chatbot keys
```

`app/student/student_chatbot.php`:

```diff
  require_once '../../config/core.php';
+ require_once '../../config/groq_config.php'; // GROQ_MODEL + the dedicated chatbot keys
```

Then in both, `'model' => 'llama-3.3-70b-versatile'` → `'model' => GROQ_MODEL`.

### ⚠️ Side effect: the chatbot keys start working

Both files already contained:

```php
$apiKey = defined('GROQ_API_KEY_CHATBOT') ? GROQ_API_KEY_CHATBOT : ($_ENV['GROQ_API_KEY'] ?? '');
```

That constant is defined in `config/groq_config.php`, which was **not loaded** — so `defined()`
always returned `false` and both chatbots silently used the main `GROQ_API_KEY`. After the
require they use the dedicated keys for the first time.

**On this machine `GROQ_API_KEY_CHATBOT` and `GROQ_API_KEY_CHATBOT_2` are distinct keys, not
copies of the main one.** Both were smoke-tested after the change and answered normally. If you
replay this elsewhere, test the chatbots — an invalid or exhausted value in those variables
starts failing only after this step.

---

## 5. JSON mode

Three functions ask for JSON and then regex-clean the reply, which assumes a particular model's
formatting habits. `response_format` removes the assumption.

`call_groq_api()` gained a 6th parameter `$jsonMode = false`; when true it sets
`$payload['response_format'] = ['type' => 'json_object']`. It is forwarded through the
rate-limit retries — easy to miss, and without it a retry silently drops JSON mode.

Passed `true` from `extract_metadata_with_groq()`, `generate_statistical_analysis()` and
`check_similarity_groq()`. **Not** from `generate_analytics_insight()`, which deliberately
returns plain prose.

---

## 6. Rate limits — the part the original doc got wrong

The original Section 7 reported analysis as "OK, 6/6 fields — 3.45s" from a run that happened
to fit inside the tokens-per-minute window. It gives no hint the step is quota-fragile. On a
free tier it is:

```
Rate limit reached for model openai/gpt-oss-120b ... service tier on_demand on
tokens per minute (TPM): Limit 8000, Used 3089, Requested 6278
```

One upload sends metadata (~3.1k tokens) and analysis (~6.3k tokens) back to back — together
~9.4k against an 8,000 TPM ceiling, so **both cannot succeed inside the same minute**.

**The old fallback-key design does not rescue this.** TPM is enforced per *organisation*, not
per key, so `GROQ_API_KEY_UPLOAD_2` hits the identical ceiling and the retry is spent for
nothing.

### What was changed

`call_groq_api()` now reads Groq's own reply, which states exactly how long to wait:

- a wait of up to `GROQ_RETRY_MAX_WAIT` (12s) is honoured, then the call is retried once on the
  **same** key;
- the key swap still happens, but only for limits that are per-key (requests per minute/day),
  since that is where a second key actually helps;
- anything longer is given up on and reported, rather than leaving the caller hanging.

`max_execution_time` is 120s here, so a 12s pause is comfortably inside the budget.

**Measured after the change** (`1786723161_IMRAD-FOR_1_.pdf`, 21,156 chars):

| Step | Result |
|---|---|
| metadata | OK — 1.34s |
| analysis | **429 → waited 11.1s → OK, 6/6 fields** — 14.70s total |
| similarity (self-match) | **429 → waited 9.1s → 95%** — 10.93s total |
| analytics insight (prose) | OK — 0.94s |

Both rate-limited steps would have returned empty under the old code. They now cost the student
about ten seconds of waiting instead.

### If ten seconds is too slow

Two options, in order of effort — both are judgement calls, not required by the migration:

1. **Trim the analysis slice.** `config/groq_config.php` sends `substr($pdfText, 0, 20000)`.
   Halving it to ~10,000 puts the request near 3,100 tokens, which fits alongside metadata with
   no wait at all — at the cost of analysis depth on long papers.
2. **Upgrade to Dev Tier**, which removes the ceiling.

Check <https://console.groq.com/settings/limits> for the current per-model TPM; free-tier limits
differ per model, so the swap may itself have tightened the budget.

---

## 7. Behaviour differences

Title, authors and keywords came out identical between the two models. One difference is worth
knowing about:

**`year` now comes back `null` more often.** GPT OSS honours the prompt's "return null if not
found", where Llama tended to lift a year out of a citation. Students will more often see the
year field blank after AI extract rather than pre-filled with a wrong value. That is the prompt
working, not a regression.

**Pre-existing, unrelated to the swap:** on a PDF with no labelled "Abstract" in the first 8,000
characters, the Introduction is returned as the abstract. That is a document/prompt issue and
predates this migration.

---

## 8. Verification

```bash
# syntax
for f in config/groq_config.php ai/groq_config.php ai/help_chatbot.php app/student/student_chatbot.php; do
  php -l "$f"
done

# no stale references (should match nothing outside .env.bak-*)
grep -rn "llama-3" --include=*.php --include=*.example . | grep -v vendor/

# end-to-end, real API calls
php groq_model_check.php openai/gpt-oss-120b
```

Expected: metadata `OK`; analysis `OK` with all 6 fields (possibly after a logged wait);
self-match similarity **high (85–95%)**. A low similarity number means the JSON did not parse
and *the plagiarism gate is passing everything* — treat that as a failure, not a curiosity.

Then in the browser: student upload → **Extract with AI**; student chatbot (PUPPY); Help Center
chatbot; analytics dashboard AI insight.

> The harness in the original doc pointed at `CommEase_IMRAD.pdf`, which no longer exists in
> this repo. Any PDF under `app/student/uploads/` works; pass it as the second argument.

---

## 9. Rollback

```bash
cp .env.bak-20260815 .env
git checkout -- config/groq_config.php ai/groq_config.php \
                ai/help_chatbot.php app/student/student_chatbot.php
```

⚠️ Rolling back past **2026-08-16** restores a decommissioned model and every AI feature fails.
If GPT OSS 120B misbehaves, set a *different* live model in `.env` instead — thanks to this
migration that is now a one-line change, because every call site reads `GROQ_MODEL`.

---

## 10. Related

- `docs/GROQ_AI_FEATURES.md` — what each AI feature does
- Groq model list: <https://console.groq.com/docs/models> — confirm exact model ids first
