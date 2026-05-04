# Venice AI Reverse Proxy (WordPress Plugin)

This plugin adds a WordPress REST proxy endpoint for Venice AI under:

- `/wp-json/venice-proxy/v1/*`

It forwards requests to Venice API base:

- `https://api.venice.ai/api/v1`

## Why this exists

Some clients (including Agnai/Agnaistic) may send a `think` field in JSON payloads. Venice may reject that field. This proxy removes every `think` key (including nested ones) before forwarding JSON upstream.

The proxy preserves Venice-compatible `reasoning` payloads and only strips disallowed `think` keys.

## Install (DreamHost / standard WordPress)

1. Put `venice-ai-reverse-proxy.php` in:
   - `wp-content/plugins/venice-ai-reverse-proxy/venice-ai-reverse-proxy.php`
2. Activate **Venice AI Reverse Proxy** from WordPress Admin → Plugins.

### Option A: Admin settings page (no `wp-config.php` edits required)

1. Go to **Settings → Venice AI Proxy**.
2. Add:
   - **Venice API Key**: your real Venice API key.
   - **Proxy Shared Secret**: a long random secret.
   - **Target Base URL**: leave default unless you have a specific Venice-compatible base.
   - **Timeout Seconds**: default `120`.
3. Save settings.
4. In Agnai/Agnaistic, use the **Proxy Shared Secret** as the API key for this WordPress proxy.

Use a long random proxy secret. This is the API key you put into Agnai/Agnaistic. It is not your Venice API key.

### Option B: `wp-config.php` constants (advanced / preferred for immutable config)

Set required constants:

```php
define( 'VENICE_API_KEY', 'YOUR_VENICE_API_KEY' );
define( 'VENICE_PROXY_SHARED_SECRET', 'YOUR_PROXY_SHARED_SECRET' );
```

Optional constants:

```php
define( 'VENICE_PROXY_TARGET_BASE', 'https://api.venice.ai/api/v1' );
define( 'VENICE_PROXY_TIMEOUT', 120 );
```

Constants override saved admin settings when both are present.

## Agnai/Agnaistic setup

- **Base URL**: `https://your-site.com/wp-json/venice-proxy/v1`
- **API key**: your proxy shared secret (`VENICE_PROXY_SHARED_SECRET`), not your Venice key.

## cURL examples (placeholders only)

### Direct Venice request

```bash
curl https://api.venice.ai/api/v1/chat/completions \
  -H "Authorization: Bearer YOUR_VENICE_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "deepseek-v3.2",
    "messages": [
      {
        "role": "user",
        "content": "Your question here"
      }
    ],
    "reasoning": {
      "max_tokens": 4096,
      "strategy": "chain_of_thought"
    },
    "stream": true,
    "temperature": 0.7,
    "max_tokens": 2048,
    "top_p": 0.9,
    "frequency_penalty": 0,
    "presence_penalty": 0
  }'
```

### WordPress proxy request

```bash
curl https://your-site.com/wp-json/venice-proxy/v1/chat/completions \
  -H "Authorization: Bearer YOUR_PROXY_SHARED_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "deepseek-v3.2",
    "messages": [
      {
        "role": "user",
        "content": "Your question here"
      }
    ],
    "reasoning": {
      "max_tokens": 4096,
      "strategy": "chain_of_thought"
    },
    "stream": true,
    "temperature": 0.7,
    "max_tokens": 2048,
    "top_p": 0.9,
    "frequency_penalty": 0,
    "presence_penalty": 0,
    "think": true
  }'
```

In the proxy example above, the proxy removes `think` before Venice sees the request, while preserving the `reasoning` object.

## Streaming note

If request JSON includes `"stream": true`, plugin attempts cURL-based streaming as **best-effort**.
On shared hosting (including DreamHost), buffering may prevent token-by-token output even when upstream streams correctly.

## Troubleshooting

- **403 Invalid or missing proxy shared secret**
  - Ensure `Authorization: Bearer <VENICE_PROXY_SHARED_SECRET>` or `X-Venice-Proxy-Secret` matches exactly.
- **500 Missing VENICE_API_KEY**
  - Add Venice API key via **Settings → Venice AI Proxy** or define `VENICE_API_KEY` in `wp-config.php`.
- **404 REST route not found**
  - Confirm plugin is activated and URL is `/wp-json/venice-proxy/v1/...`.
- **Upstream Venice errors (4xx/5xx)**
  - Proxy returns Venice status/body; inspect response body for model/input issues.
- **Streaming not token-by-token**
  - Expected on some shared hosts due to output buffering/proxy layers.
