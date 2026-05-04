# Venice AI Reverse Proxy (WordPress Plugin)

This plugin adds a WordPress REST proxy endpoint for Venice AI under:

- `/wp-json/venice-proxy/v1/*`

It forwards requests to Venice API base:

- `https://api.venice.ai/api/v1`

## Why this exists

Some clients (including Agnai/Agnaistic) may send a `think` field in JSON payloads. Venice may reject that field. This proxy removes every `think` key (including nested ones) before forwarding JSON upstream.

## Install (DreamHost / standard WordPress)

1. Put `venice-ai-reverse-proxy.php` in:
   - `wp-content/plugins/venice-ai-reverse-proxy/venice-ai-reverse-proxy.php`
2. In `wp-config.php`, set required constants:

```php
define( 'VENICE_API_KEY', 'real-venice-api-key-here' );
define( 'VENICE_PROXY_SHARED_SECRET', 'long-random-proxy-secret-here' );
```

3. Optional constants:

```php
define( 'VENICE_PROXY_TARGET_BASE', 'https://api.venice.ai/api/v1' );
define( 'VENICE_PROXY_TIMEOUT', 120 );
```

4. Activate **Venice AI Reverse Proxy** from WordPress Admin → Plugins.

## Agnai/Agnaistic setup

- **Base URL**: `https://your-site.com/wp-json/venice-proxy/v1`
- **API key**: your proxy shared secret (`VENICE_PROXY_SHARED_SECRET`), not your Venice key.

## cURL tests

### Models

```bash
curl -i \
  -H "Authorization: Bearer YOUR_PROXY_SHARED_SECRET" \
  "https://your-site.com/wp-json/venice-proxy/v1/models"
```

### Chat completions with top-level `think`

```bash
curl -i \
  -H "Authorization: Bearer YOUR_PROXY_SHARED_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"model":"venice-large","messages":[{"role":"user","content":"hi"}],"think":true}' \
  "https://your-site.com/wp-json/venice-proxy/v1/chat/completions"
```

### Nested `think` removal example

```bash
curl -i \
  -H "Authorization: Bearer YOUR_PROXY_SHARED_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"stream":false,"payload":{"nested":{"think":"remove-me"}},"messages":[{"role":"user","content":"hello"}]}' \
  "https://your-site.com/wp-json/venice-proxy/v1/chat/completions"
```

## Streaming note

If request JSON includes `"stream": true`, plugin attempts cURL-based streaming as **best-effort**.
On shared hosting (including DreamHost), buffering may prevent token-by-token output even when upstream streams correctly.

## Troubleshooting

- **403 Invalid or missing proxy shared secret**
  - Ensure `Authorization: Bearer <VENICE_PROXY_SHARED_SECRET>` or `X-Venice-Proxy-Secret` matches exactly.
- **500 Missing VENICE_API_KEY**
  - Add `VENICE_API_KEY` constant in `wp-config.php`.
- **404 REST route not found**
  - Confirm plugin is activated and URL is `/wp-json/venice-proxy/v1/...`.
- **Upstream Venice errors (4xx/5xx)**
  - Proxy returns Venice status/body; inspect response body for model/input issues.
- **Streaming not token-by-token**
  - Expected on some shared hosts due to output buffering/proxy layers.
