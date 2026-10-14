<?php

declare(strict_types=1);

namespace TypechoPlugin\ShygoHomeCache;

/**
 * Homepage snapshot storage. Disk writes are atomic renames. APCu entries keep
 * the same created-time metadata so TTL changes apply on the next read.
 */
class Cache
{
    public const IGNORED_QUERY_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'fbclid',
        'gclid',
        'gbraid',
        'wbraid',
        'msclkid',
        'mc_cid',
        'mc_eid',
        '_ga',
        'spm',
        'from',
        'ref',
        'referrer',
    ];

    private const DOGE_AUTH_KEY = '/(?:\?|&|&amp;)auth_key=\d{9,12}-[A-Za-z0-9]{1,100}--?\d+-[a-fA-F0-9]{32}/';

    private const HTACCESS = <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

    private const WEB_CONFIG = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
XML;

    /** @var string */
    private $dir = '';

    /** @var string */
    private $apcuKey = '';

    /** @var string */
    private $mode = 'disk';

    /** @var resource|null */
    private $lockHandle = null;

    /** @var string */
    private $lockToken = '';

    private function __construct(string $mode, string $dir, string $apcuKey)
    {
        $this->mode = $mode;
        $this->dir = $dir;
        $this->apcuKey = $apcuKey;
    }

    public static function openDisk(string $dir): self
    {
        return new self('disk', rtrim($dir, '/'), '');
    }

    public static function openApcu(string $key): self
    {
        return new self('apcu', '', $key);
    }

    public static function apcuAvailable(): bool
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_fetch') || !function_exists('apcu_add') || !function_exists('apcu_delete')) {
            return false;
        }

        if (function_exists('apcu_enabled')) {
            return apcu_enabled();
        }

        $enabled = ini_get('apc.enabled');

        return $enabled === '1' || $enabled === 'On' || $enabled === 'true';
    }

    public function backendName(): string
    {
        return $this->mode === 'apcu' ? 'apcu' : 'disk';
    }

    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        $collapsed = preg_replace('#/+#', '/', $path);
        if (!is_string($collapsed) || $collapsed === '') {
            return '/';
        }

        $path = $collapsed;
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/' && substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }

        return $path;
    }

    public static function requestPathFrom(string $requestUri, string $pathInfo = ''): string
    {
        $rawPath = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($rawPath) || $rawPath === '') {
            $rawPath = '/';
        }

        $path = self::normalizePath($rawPath);
        if ($pathInfo === '') {
            return $path;
        }

        $suffix = self::normalizePath($pathInfo);
        if ($suffix === '/') {
            return $path;
        }

        if (strlen($path) < strlen($suffix) || substr($path, -strlen($suffix)) !== $suffix) {
            $path = self::normalizePath(rtrim($path, '/') . $suffix);
        }

        return $path;
    }

    /**
     * True for the site front page and its page-1 aliases, including
     * subdirectory installs and rewrite-off /index.php URLs.
     */
    public static function isHomePath(string $requestPath, string $basePath): bool
    {
        $request = self::normalizePath($requestPath);
        $base = self::normalizePath($basePath === '' ? '/' : $basePath);

        if ($base === '/') {
            $rest = $request;
        } elseif ($request === $base) {
            $rest = '/';
        } elseif (strpos($request, $base . '/') === 0) {
            $rest = substr($request, strlen($base));
            $rest = self::normalizePath($rest === '' ? '/' : $rest);
        } else {
            return false;
        }

        $rest = strtolower($rest);

        return $rest === '/'
            || $rest === '/index.php'
            || $rest === '/page/1'
            || $rest === '/index.php/page/1';
    }

    /**
     * Tracking parameters are ignored so they do not bypass the snapshot.
     * Any other query, including a wrong purge token, skips the cache.
     */
    public static function hasMeaningfulQuery(array $query): bool
    {
        foreach ($query as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, self::IGNORED_QUERY_KEYS, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function looksLikeHtmlPage(string $html): bool
    {
        if (strlen($html) < 64) {
            return false;
        }

        return stripos($html, '<html') !== false
            || stripos($html, '<!doctype') !== false
            || stripos($html, '<body') !== false;
    }

    /**
     * DogeCloud / DogeUpload Type-A token: timestamp-rand-uid-md5(path-timestamp-rand-uid-key).
     */
    public static function dogeAuthKey(string $path, int $expire, string $rand, int $uid, string $secret): string
    {
        $hash = md5($path . '-' . $expire . '-' . $rand . '-' . $uid . '-' . $secret);

        return $expire . '-' . $rand . '-' . $uid . '-' . $hash;
    }

    public static function containsDogeAuthKey(string $html): bool
    {
        return preg_match(self::DOGE_AUTH_KEY, $html) === 1;
    }

    /**
     * True when every auth_key from $before is gone in $after.
     * A snapshot with no token yet counts as already refreshed.
     */
    public static function authKeysRewritten(string $before, string $after): bool
    {
        if (!preg_match_all(self::DOGE_AUTH_KEY, $before, $found)) {
            return true;
        }

        foreach ($found[0] as $token) {
            if (strpos($after, $token) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rewrite CDN URLs the way DogeUpload 1.0.1 Model::getFileUrl($path, true) does.
     *
     * replaceContent() keeps a URL when strpos($url, $cdn_url) fails, then signs
     * parse_url($url, PHP_URL_PATH). getFileUrl appends "/{thumb}" on every call,
     * drops any previous query, and when auth_key is set emits
     * Common::url("{path}?auth_key=expire-rand-uid-md5", $cdn_url).
     * The hash covers that path, including the thumb suffix.
     *
     * A homepage snapshot is taken after that output callback, so the path may
     * already end in "/{thumb}". Appending again would request
     * "/720x1080/720x1080". This method collapses a repeated thumb suffix to one
     * before signing. An empty $authKey stores that stable path with no query.
     */
    public static function rewriteCdnUrls(
        string $html,
        string $cdnUrl,
        string $thumb,
        string $authKey,
        int $now,
        int $expireSeconds,
        int $uid,
        ?string $rand = null
    ): string {
        $origin = rtrim(trim($cdnUrl), '/');
        $host = self::cdnHost($origin);
        if ($host === '' || ($authKey === '' && $thumb === '' && !self::containsDogeAuthKey($html))) {
            return $html;
        }

        $thumb = trim($thumb, '/');
        $expireSeconds = $expireSeconds > 0 ? $expireSeconds : 300;
        $replaced = preg_replace_callback(
            '#https?://[^\s"\'<>\\\\]+#i',
            static function (array $match) use ($origin, $host, $thumb, $authKey, $now, $expireSeconds, $uid, $rand): string {
                $raw = $match[0];
                $suffix = '';
                while ($raw !== '' && strpos(').,;]', substr($raw, -1)) !== false) {
                    $suffix = substr($raw, -1) . $suffix;
                    $raw = substr($raw, 0, -1);
                }

                if (strpos($raw, $origin) === false) {
                    return $match[0];
                }

                $parts = parse_url($raw);
                if (!is_array($parts) || !isset($parts['host']) || strtolower((string) $parts['host']) !== $host) {
                    return $match[0];
                }

                $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
                $path = self::applyThumb($path, $thumb);
                $public = self::dogePublicUrl($origin, $path);
                $signPath = parse_url($public, PHP_URL_PATH);
                if (!is_string($signPath) || $signPath === '') {
                    $signPath = $path;
                }

                if ($authKey !== '') {
                    $tokenRand = $rand !== null ? $rand : bin2hex(random_bytes(16));
                    $public .= '?auth_key=' . self::dogeAuthKey($signPath, $now + $expireSeconds, $tokenRand, $uid, $authKey);
                }

                return $public . $suffix;
            },
            $html
        );

        return is_string($replaced) ? $replaced : $html;
    }

    /**
     * Flush output handlers nested inside the cache buffer (DogeUpload signs in one)
     * and return the cache buffer. ob_get_clean() alone discards those handlers.
     */
    public static function captureNestedBuffers(int $baseLevel): ?string
    {
        if (ob_get_level() <= $baseLevel) {
            return null;
        }

        while (ob_get_level() > $baseLevel + 1) {
            if (!ob_end_flush()) {
                break;
            }
        }

        $chunks = [];
        while (ob_get_level() > $baseLevel) {
            $chunk = ob_get_clean();
            if (is_string($chunk)) {
                $chunks[] = $chunk;
            }
        }

        if ($chunks === []) {
            return '';
        }

        return implode('', array_reverse($chunks));
    }

    public static function cdnHost(string $cdnUrl): string
    {
        $cdnUrl = trim($cdnUrl);
        if ($cdnUrl === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $cdnUrl)) {
            $cdnUrl = 'https://' . ltrim($cdnUrl, '/');
        }

        $host = parse_url($cdnUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Typecho\Common::url: rtrim(prefix, '/') . '/' . str_replace('//', '/', ltrim(path, '/')).
     */
    private static function dogePublicUrl(string $cdnUrl, string $path): string
    {
        if (strpos($path, './') === 0) {
            $path = substr($path, 2);
        }

        return rtrim($cdnUrl, '/') . '/' . str_replace('//', '/', ltrim($path, '/'));
    }

    /**
     * getFileUrl appends "/{thumb}" unconditionally. Collapse repeats first so a
     * path that beforeRender already rewrote stays a single suffix.
     */
    private static function applyThumb(string $path, string $thumb): string
    {
        $path = '/' . str_replace('//', '/', ltrim($path, '/'));
        if ($thumb === '') {
            return $path === '/' ? '/' : $path;
        }

        $suffix = '/' . $thumb;
        while (substr($path, -strlen($suffix)) === $suffix) {
            $next = substr($path, 0, -strlen($suffix));
            if ($next === '' || $next === '/') {
                break;
            }

            $path = $next;
        }

        return rtrim($path, '/') . $suffix;
    }

    /**
     * @return array{state:string,html:?string,age:?int}
     */
    public function read(int $ttl, int $grace, ?int $now = null): array
    {
        $payload = $this->mode === 'apcu' ? $this->readApcu() : $this->readDisk();
        if ($payload === null) {
            return ['state' => 'miss', 'html' => null, 'age' => null];
        }

        $now = $now ?? time();
        $age = $now - $payload['created'];
        if ($age < 0) {
            $age = 0;
        }

        if ($age < $ttl) {
            $state = 'hit';
        } elseif ($grace > 0 && $age < ($ttl + $grace)) {
            $state = 'stale';
        } else {
            return ['state' => 'miss', 'html' => null, 'age' => $age];
        }

        return ['state' => $state, 'html' => $payload['html'], 'age' => $age];
    }

    public function write(string $html, int $keepSeconds, ?int $created = null): bool
    {
        $created = $created ?? time();
        if ($keepSeconds < 1) {
            $keepSeconds = 1;
        }

        if ($this->mode === 'apcu') {
            if (!self::apcuAvailable()) {
                return false;
            }

            return apcu_store($this->apcuKey, [
                'created' => $created,
                'html' => $html,
            ], $keepSeconds);
        }

        if (!$this->ensureDir()) {
            return false;
        }

        $body = "SHYGO1\n"
            . 'created:' . $created . "\n"
            . 'bytes:' . strlen($html) . "\n"
            . "\n"
            . $html;
        $tmp = $this->dir . '/homepage.cache.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            return false;
        }

        $target = $this->dir . '/homepage.cache';
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }

        @chmod($target, 0644);

        return true;
    }

    public function purge(): void
    {
        if ($this->mode === 'apcu') {
            if (!self::apcuAvailable()) {
                return;
            }

            apcu_delete($this->apcuKey);
            apcu_delete($this->lockKey());

            return;
        }

        if (!is_dir($this->dir)) {
            return;
        }

        $paths = glob($this->dir . '/homepage.cache*');
        if (!is_array($paths)) {
            return;
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function ensureDir(): bool
    {
        if ($this->mode !== 'disk') {
            return true;
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return false;
        }

        $htaccess = $this->dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::HTACCESS);
        }

        $index = $this->dir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\nexit;\n");
        }

        $webConfig = $this->dir . '/web.config';
        if (!is_file($webConfig)) {
            @file_put_contents($webConfig, self::WEB_CONFIG);
        }

        return is_writable($this->dir);
    }

    public function acquireLock(int $ttlSeconds = 30): bool
    {
        if ($ttlSeconds < 1) {
            $ttlSeconds = 1;
        }

        if ($this->mode === 'apcu') {
            if (!self::apcuAvailable()) {
                return false;
            }

            if ($this->lockToken !== '') {
                return true;
            }

            $token = bin2hex(random_bytes(8));
            if (!apcu_add($this->lockKey(), $token, $ttlSeconds)) {
                return false;
            }

            $this->lockToken = $token;

            return true;
        }

        if (is_resource($this->lockHandle)) {
            return true;
        }

        if (!$this->ensureDir()) {
            return false;
        }

        $handle = @fopen($this->dir . '/homepage.lock', 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    public function releaseLock(): void
    {
        if ($this->mode === 'apcu') {
            if ($this->lockToken !== '' && self::apcuAvailable()) {
                $current = apcu_fetch($this->lockKey(), $ok);
                if ($ok && is_string($current) && hash_equals($this->lockToken, $current)) {
                    apcu_delete($this->lockKey());
                }
            }

            $this->lockToken = '';

            return;
        }

        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * @return array{state:string,html:?string,age:?int}
     */
    public function waitForFresh(int $ttl, int $grace, int $timeoutMs, ?int $now = null): array
    {
        $timeoutMs = max(0, $timeoutMs);
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $read = $this->read($ttl, $grace, $now);

        while ($read['state'] !== 'hit' && microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            usleep((int) min(50000, (int) ($remaining * 1000000)));
            $read = $this->read($ttl, $grace, $now);
        }

        return $read;
    }

    public function describe(int $ttl, int $grace, ?int $now = null): string
    {
        $read = $this->read($ttl, $grace, $now);
        if ($read['state'] === 'miss' || !is_string($read['html'])) {
            return 'empty';
        }

        return $read['state'] . ', age ' . (int) $read['age'] . 's, ' . strlen($read['html']) . ' bytes';
    }

    /**
     * @return array{created:int,html:string}|null
     */
    private function readDisk(): ?array
    {
        $path = $this->dir . '/homepage.cache';
        if (!is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strncmp($raw, "SHYGO1\n", 7) !== 0) {
            return null;
        }

        $parts = explode("\n\n", $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $created = null;
        $bytes = null;
        $lines = explode("\n", $parts[0]);
        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            if (strncmp($lines[$i], 'created:', 8) === 0) {
                $created = (int) substr($lines[$i], 8);
            } elseif (strncmp($lines[$i], 'bytes:', 6) === 0) {
                $bytes = (int) substr($lines[$i], 6);
            }
        }

        if ($created === null || $bytes === null || $bytes < 0 || strlen($parts[1]) !== $bytes) {
            return null;
        }

        return ['created' => $created, 'html' => $parts[1]];
    }

    /**
     * @return array{created:int,html:string}|null
     */
    private function readApcu(): ?array
    {
        if (!self::apcuAvailable()) {
            return null;
        }

        $payload = apcu_fetch($this->apcuKey, $ok);
        if (!$ok || !is_array($payload) || !isset($payload['created'], $payload['html']) || !is_string($payload['html'])) {
            return null;
        }

        return ['created' => (int) $payload['created'], 'html' => $payload['html']];
    }

    private function lockKey(): string
    {
        return $this->apcuKey . '_lock';
    }
}