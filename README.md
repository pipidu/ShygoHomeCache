# ShygoHomeCache

Typecho 1.2 plugin that stores the anonymous homepage HTML in APCu or on disk. Concurrent visitors reuse that snapshot instead of rendering the theme again, which is the usual CPU spike on a heavy front page.

A fresh hit returns before the theme runs. After the TTL, one request rebuilds the page. Until that rebuild finishes, everyone else still gets the previous HTML.

## Install

1. Copy this folder to `usr/plugins/ShygoHomeCache` (the directory name must stay `ShygoHomeCache`).
2. In the admin panel, open **Console → Plugins** and enable **ShygoHomeCache**.
3. Open the plugin settings. The default TTL is **120 seconds**. **60–300** is the range to use.
4. For disk storage, the web user must be able to write `usr/plugins/ShygoHomeCache/cache/`. APCu does not need that directory.

Enabling the plugin saves the default settings immediately. Caching starts on the next anonymous homepage view.

Activation only registers this plugin's hooks. It does not read or write the `plugins` option. Typecho merges those hooks into the existing plugin map after `activate()` returns, so DogeUpload and the other enabled plugins stay enabled.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Homepage cache | Enabled | Turns storage on or off without disabling the plugin. |
| Storage | Auto | APCu when `apcu_enabled()` is true, otherwise disk. |
| TTL (seconds) | 120 | Fresh lifetime. Clamped to 30–86400. |
| Stale window (seconds) | 600 | How long the previous HTML may be served while one request rebuilds it. `0` disables that. |
| Browser cache (seconds) | 0 | `0` sends `Cache-Control: no-cache`. PHP still answers from APCu or disk. |
| Logged-in visitors | Skip | A `__typecho_uid` cookie bypasses the cache. Logged-in HTML is never stored. |
| Purge token | empty | Enables `/?purge=token`. Empty disables URL purge. |

The settings page shows whether the snapshot is empty, fresh, or stale, and which storage is active.

## Clear the cache

- **Settings button:** on the plugin settings page, use **Clear homepage cache**.
- **URL:** set a purge token, then request `https://example.com/?purge=YOUR_TOKEN`. The plugin deletes the snapshot and redirects to the same path without the query. The token shows up in access logs, so use the settings button for routine clears.

The snapshot is also deleted when:

- a post or page is published, deleted, or marked (public, hidden, private, waiting)
- a comment is submitted, edited, approved, or deleted

Draft autosave does not clear it. Publishing an existing post does. Theme edits, plugin edits, and backup imports do not; use the purge button after those.

## What is stored

Stored:

- `GET` `/`, `/index.php`, `/page/1/`, and the same paths under a subdirectory install
- anonymous visitors only (no `__typecho_uid` cookie)
- HTTP 200 HTML that looks like a full page

Not stored:

- page 2 and later
- posts, pages, feeds, search, admin
- `POST`, `HEAD`, and `XMLHttpRequest`
- any query string other than common campaign parameters (`utm_*`, `fbclid`, `gclid`, and similar)
- requests while `__TYPECHO_DEBUG__` is true
- responses that are not HTTP 200

`/` and `/page/1/` share one snapshot. Both are the front page.

Logged-in HTML is never written. With **Skip logged-in users**, those visits render normally and do not read the snapshot. The other option still serves the public snapshot to logged-in users, but a miss while logged in is rendered and discarded.

Response headers on a served snapshot:

- `X-Shygo-Home-Cache: HIT` or `STALE`
- `X-Shygo-Home-Cache-Age: <seconds>`
- a rebuild adds `X-Shygo-Home-Cache: MISS` and an HTML comment `<!-- ShygoHomeCache ... -->`

`begin` is registered at priority `1`, so a plugin on `begin_0` still runs first. The cache returns before later `index.php` begin hooks.

## DogeUpload and signed image URLs

DogeUpload 1.0.1 signs files while rendering, in `Model::replaceContent` on `Widget\Archive::beforeRender`. That hook starts an output buffer. For every `http(s)` URL whose text contains the configured `cdn_url`, `getFileUrl(parse_url(url, PHP_URL_PATH), true)` rebuilds the link:

1. If `thumb` is set, the rule name is appended to the path. On shygo.cn that rule is `720x1080`, so `/typecho/uploads/2026/10/abc.jpg` becomes `/typecho/uploads/2026/10/abc.jpg/720x1080`.
2. If `auth_key` is empty, `Typecho\Common::url` returns that path on `cdn_url` and drops any older query string.
3. If `auth_key` is set, the query is `auth_key=expire-rand-uid-md5`. `expire` is `time() + (token_expire ?: 300)`, `rand` is `bin2hex(random_bytes(16))`, and `uid` is `ip2long(Request::getIp()) ?: 0`. The hash is `md5(path-expire-rand-uid-secret)` and `path` includes the thumb suffix.

The editor stores the raw CDN URL. The buffer callback is what adds the rule and the token. `attachmentHandle` calls `getFileUrl` with `$view = false`, so uploads themselves stay unsigned. There is no image cookie. A URL with no `auth_key` on the signed host returns 403; the same URL with a fresh token returns 200. Referer is not part of this check.

shygo.cn publishes two buckets. `cdn_url` points at `doges3v2.img.shygo.cn`, and only that host is rewritten. `doges3.img.shygo.cn` stays as written: older photos, including ones that already end in `/720x1080`, and `SnapicPlus_v5/translate.min.js`. A tag slug that merely contains the letters `doges3v2` is a shygo.cn URL and is left alone.

`getFileUrl` appends `/{thumb}` on every call. The homepage cache runs after that callback, so the HTML already contains `/720x1080?auth_key=...`. Calling `getFileUrl` again would request `/720x1080/720x1080` and the image would fail. The snapshot therefore stores each matching path with the rule applied once and with `auth_key` removed. `HIT`, `STALE`, and the miss response each mint a new token for the current visitor and `token_expire`.

That is one MD5 per matching URL. The theme is not rendered again. Each image URL stays valid for `token_expire` seconds after that HTML is sent, so the page TTL can stay at 120 seconds. A token left in the snapshot would die inside the stale window (600 seconds), which is longer than the usual 300-second token.

If stripping still leaves an `auth_key`, that response is sent as just rendered and is not stored. A stored page whose previous tokens cannot be replaced is rendered again. The server-side OSS credential `DogeUpload:TokenCache` never appears in the HTML.

## Requirements

- Typecho 1.2.0 or newer (1.2 and 1.3 hook names)
- PHP 7.4 through 8.4
- Optional: APCu (`apcu_store`, `apcu_fetch`, `apcu_add`)

Disk files live in `cache/homepage.cache`. `.htaccess` and `web.config` deny web access to that directory. Nginx ignores `.htaccess`; deny the directory in the server config:

```nginx
location ^~ /usr/plugins/ShygoHomeCache/cache/ {
    deny all;
}
```

The file is the public homepage, not a credential store. Denying the path just keeps it from becoming a second URL.

## Disable

Disable the plugin in **Console → Plugins**. That deletes the snapshot. You can also remove `cache/homepage.cache` by hand. Leave `cache/.htaccess` in place.