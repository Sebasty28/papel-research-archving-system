/**
 * PAPEL metadata proxy.
 *
 * Why this exists: free hosting (InfinityFree) serves the site but cannot make
 * outbound calls to a third-party API, so the server cannot reach Groq. The
 * student's browser can. This Worker sits between them and holds the API key,
 * so the key is never in a page that the public can view the source of.
 *
 * It deliberately does not accept a prompt. The browser sends only the text of
 * a paper, and the one prompt below is the only thing this Worker will ever
 * ask the model, so a stranger who finds the address cannot use it as a free
 * general-purpose model. It is the same prompt as the PHP path in
 * config/groq_config.php, and the two must be changed together.
 *
 * Bindings expected (set in the Cloudflare dashboard, or wrangler secret put):
 *   GROQ_API_KEY     secret, the key itself
 *   ALLOWED_ORIGINS  plain text, comma separated, e.g.
 *                    "https://papel.rf.gd,http://localhost"
 *   GROQ_MODEL       plain text, optional, defaults below
 */

const DEFAULT_MODEL = 'openai/gpt-oss-120b';
const MAX_TEXT = 20000;          // the PHP path sends the same first 20k
const MAX_BODY = 256 * 1024;     // a sanity ceiling on the request itself

const SYSTEM_PROMPT =
  'You are an expert research paper metadata extractor. Extract ONLY the title, ' +
  'authors, year, and keywords from the paper. Do NOT extract or generate an ' +
  'abstract. Return ONLY valid JSON.';

function userPrompt(text) {
  return 'Extract metadata from this research paper text.\n\n'
    + 'Required fields (return null if not found):\n'
    + '- title: The main research paper title (usually on first page, often in larger font or bold)\n'
    + "- authors: List of author names (look for 'by', 'authors:', or names under title). Return as array.\n"
    + "- year: Publication or completion year (look for dates, copyright, or 'Year:')\n"
    + "- keywords: Research keywords (look for 'Keywords:', 'Key terms:', 'Key Words:', or similar). Return as array.\n\n"
    + 'Return ONLY this JSON structure:\n'
    + '{\n'
    + '  "title": "exact title here",\n'
    + '  "authors": ["Author 1", "Author 2"],\n'
    + '  "year": 2024,\n'
    + '  "keywords": ["keyword1", "keyword2"]\n'
    + '}\n\n'
    + 'Research Paper Text (first 8000 characters):\n' + text.slice(0, 8000);
}

function corsHeaders(origin, allowed) {
  // Echoed rather than '*', so a browser will only accept the answer for a
  // page this Worker was actually set up for.
  const headers = {
    'Content-Type': 'application/json',
    'Vary': 'Origin',
    'Cache-Control': 'no-store',
  };
  if (origin && allowed.includes(origin)) {
    headers['Access-Control-Allow-Origin'] = origin;
    headers['Access-Control-Allow-Methods'] = 'POST, OPTIONS';
    headers['Access-Control-Allow-Headers'] = 'Content-Type';
    headers['Access-Control-Max-Age'] = '86400';
  }
  return headers;
}

function reply(body, status, headers) {
  return new Response(JSON.stringify(body), { status, headers });
}

export default {
  async fetch(request, env) {
    const allowed = String(env.ALLOWED_ORIGINS || '')
      .split(',').map(s => s.trim()).filter(Boolean);
    const origin = request.headers.get('Origin') || '';
    const headers = corsHeaders(origin, allowed);

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers });
    }
    if (request.method !== 'POST') {
      return reply({ success: false, error: 'POST only' }, 405, headers);
    }
    /* An Origin this Worker was not set up for is refused outright. A browser
       would discard the answer anyway for want of the header above; saying so
       here means a misconfigured deploy fails with a message instead of a
       silent CORS error that looks like the Worker is down. */
    if (!origin || !allowed.includes(origin)) {
      return reply({ success: false, error: 'Origin not allowed: ' + (origin || '(none)') }, 403, headers);
    }
    if (!env.GROQ_API_KEY) {
      return reply({ success: false, error: 'Proxy has no API key configured' }, 500, headers);
    }

    let text = '';
    try {
      const raw = await request.text();
      if (raw.length > MAX_BODY) {
        return reply({ success: false, error: 'Request too large' }, 413, headers);
      }
      text = String((JSON.parse(raw) || {}).text || '').slice(0, MAX_TEXT);
    } catch (err) {
      return reply({ success: false, error: 'Body must be JSON: {"text":"..."}' }, 400, headers);
    }
    if (text.trim().length < 50) {
      return reply({ success: false, error: 'Not enough text to read' }, 422, headers);
    }

    let upstream;
    try {
      upstream = await fetch('https://api.groq.com/openai/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + env.GROQ_API_KEY,
        },
        body: JSON.stringify({
          model: env.GROQ_MODEL || DEFAULT_MODEL,
          messages: [
            { role: 'system', content: SYSTEM_PROMPT },
            { role: 'user', content: userPrompt(text) },
          ],
          temperature: 0,
          max_tokens: 1200,
          top_p: 1,
          stream: false,
          response_format: { type: 'json_object' },
        }),
      });
    } catch (err) {
      return reply({ success: false, error: 'Could not reach the model: ' + err.message }, 502, headers);
    }

    const bodyText = await upstream.text();
    if (!upstream.ok) {
      /* The status is passed through so the page can tell a rate limit from a
         real fault, which is the same distinction the PHP path makes. */
      return reply({
        success: false,
        error: 'Model returned HTTP ' + upstream.status,
        http_code: upstream.status,
      }, upstream.status === 429 ? 429 : 502, headers);
    }

    let content;
    try {
      content = JSON.parse(bodyText).choices[0].message.content;
    } catch (err) {
      return reply({ success: false, error: 'Unreadable answer from the model' }, 502, headers);
    }

    let data;
    try {
      data = JSON.parse(content);
    } catch (err) {
      return reply({ success: false, error: 'The model did not return JSON' }, 502, headers);
    }

    return reply({ success: true, data: data }, 200, headers);
  },
};
