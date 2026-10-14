<?php

namespace TypechoPlugin\ShygoHomeCache;

use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Cache.php';

/**
 * Cache anonymous homepage HTML in APCu or on disk so concurrent visits skip theme rendering.
 *
 * Fresh hits return the stored page before the theme runs. After the TTL, one request rebuilds
 * the snapshot and other requests keep the previous HTML until that rebuild finishes.
 *
 * @package ShygoHomeCache
 * @author hy
 * @version 1.1.1
 * @since 1.2.0
 */
class Plugin implements PluginInterface
{
    /** @var array<string, mixed>|null */
    private static $settings = null;

    /** @var Cache|null */
    private static $store = null;

    /** @var bool */
    private static $started = false;

    /** @var bool */
    private static $capture = false;

    /** @var bool */
    private static $confirmed = false;

    /** @var bool */
    private static $buffering = false;

    /** @var bool */
    private static $locked = false;

    /** @var bool */
    private static $finished = false;

    /** @var bool */
    private static $loggedIn = false;

    /** @var int */
    private static $bufferLevel = 0;

    /** @var bool */
    private static $shutdownRegistered = false;

    /**
     * false until loaded, then the DogeUpload signing policy or null.
     *
     * @var array{cdn_url:string,auth_key:string,thumb:string,token_expire:int}|null|false
     */
    private static $dogePolicy = false;

    /**
     * Register hooks only. Typecho merges the resulting handle map into the
     * existing `plugins` option after this returns. This method does not read
     * or replace that row, so enabling the plugin leaves every other plugin in place.
     *
     * @return string
     * @throws PluginException
     */
    public static function activate()
    {
        self::bind();

        $disk = Cache::openDisk(self::cacheDirectory());
        if (!$disk->ensureDir() && !Cache::apcuAvailable()) {
            throw new PluginException(_t(
                'The cache directory is not writable and APCu is unavailable: %s',
                self::cacheDirectory()
            ));
        }

        return _t('Homepage cache is on. Open plugin settings to set the TTL. The default is 120 seconds.');
    }

    public static function deactivate()
    {
        try {
            self::purgeAll();
        } catch (\Throwable $e) {
            error_log('ShygoHomeCache: ' . $e->getMessage());
        }
    }

    public static function config(Form $form)
    {
        if (isset($_GET['shygo_purge']) && (string) $_GET['shygo_purge'] === '1') {
            try {
                self::purgeAll();
                if (class_exists('\\Widget\\Notice')) {
                    \Widget\Notice::alloc()->set(_t('Homepage cache cleared.'), 'success');
                }
            } catch (\Throwable $e) {
                error_log('ShygoHomeCache: ' . $e->getMessage());
                if (class_exists('\\Widget\\Notice')) {
                    \Widget\Notice::alloc()->set(_t('Could not clear the homepage cache.'), 'error');
                }
            }
        }

        $enabled = new Radio(
            'enabled',
            ['1' => _t('Enabled'), '0' => _t('Disabled')],
            '1',
            _t('Homepage cache'),
            _t('Only the anonymous front page (index, page 1) is stored.')
        );
        $form->addInput($enabled);

        $driver = new Radio(
            'driver',
            [
                'auto' => _t('Auto (APCu, then disk)'),
                'apcu' => 'APCu',
                'disk' => _t('Disk'),
            ],
            'auto',
            _t('Storage'),
            _t('Auto uses APCu when the extension is enabled. Disk is the fallback and needs a writable cache directory.')
        );
        $form->addInput($driver);

        $ttl = new Text(
            'ttl',
            null,
            '120',
            _t('TTL (seconds)'),
            _t('How long a snapshot stays fresh. 60–300 is the useful range. Values are clamped to 30–86400. DogeUpload auth_key values are not part of the snapshot; each response gets a new signature.')
        );
        $ttl->addRule('isInteger', _t('TTL must be an integer.'));
        $form->addInput($ttl);

        $grace = new Text(
            'grace',
            null,
            '600',
            _t('Stale window (seconds)'),
            _t('After the TTL, visitors still receive the previous HTML while one request rebuilds it. 0 turns that off.')
        );
        $grace->addRule('isInteger', _t('Stale window must be an integer.'));
        $form->addInput($grace);

        $browserTtl = new Text(
            'browser_ttl',
            null,
            '0',
            _t('Browser cache (seconds)'),
            _t('0 sends Cache-Control: no-cache. PHP still answers from APCu or disk. Raise it only if browsers may keep the public homepage.')
        );
        $browserTtl->addRule('isInteger', _t('Browser cache must be an integer.'));
        $form->addInput($browserTtl);

        $skipLogin = new Radio(
            'skip_login',
            ['1' => _t('Skip logged-in users'), '0' => _t('Serve the public snapshot to logged-in users too')],
            '1',
            _t('Logged-in visitors'),
            _t('Logged-in HTML is never stored, so an admin toolbar cannot leak into the public snapshot.')
        );
        $form->addInput($skipLogin);

        $secret = new Text(
            'secret',
            null,
            '',
            _t('Purge token'),
            self::secretDescription()
        );
        $form->addInput($secret);
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function onBegin(): void
    {
        try {
            self::handleBegin();
        } catch (\Throwable $e) {
            self::$capture = false;
            self::$buffering = false;
            self::unlock();
            error_log('ShygoHomeCache: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed $archive
     */
    public static function onBeforeRender($archive = null): void
    {
        if (!self::$capture) {
            return;
        }

        try {
            if (!self::archiveIsHome($archive)) {
                self::$capture = false;
                self::$confirmed = false;
                self::unlock();

                return;
            }

            self::$confirmed = true;
            if (class_exists('\\Typecho\\Response')) {
                \Typecho\Response::getInstance()->setHeader('X-Shygo-Home-Cache', 'MISS');
            }
        } catch (\Throwable $e) {
            self::$capture = false;
            self::$confirmed = false;
            self::unlock();
            error_log('ShygoHomeCache: ' . $e->getMessage());
        }
    }

    public static function onEnd(): void
    {
        if (!self::$buffering && !self::$locked) {
            return;
        }

        try {
            if (self::$buffering) {
                $html = self::takeBuffer();
                self::$buffering = false;
                if (is_string($html)) {
                    self::finishOutput($html);
                }
            }
        } catch (\Throwable $e) {
            error_log('ShygoHomeCache: ' . $e->getMessage());
        } finally {
            self::$finished = true;
            self::unlock();
        }
    }

    public static function onShutdown(): void
    {
        if (!self::$finished) {
            self::unlock();
        }
    }

    /**
     * Publish, edit, delete, and comment hooks pass different argument counts.
     *
     * @param mixed ...$args
     */
    public static function onContentChanged(...$args): void
    {
        try {
            if (self::settings()['enabled'] !== '1') {
                return;
            }

            self::purgeAll();
        } catch (\Throwable $e) {
            error_log('ShygoHomeCache: ' . $e->getMessage());
        }
    }

    private static function bind(): void
    {
        \Typecho\Plugin::factory('index.php')->begin_1 = [__CLASS__, 'onBegin'];
        \Typecho\Plugin::factory('index.php')->end_100 = [__CLASS__, 'onEnd'];
        \Typecho\Plugin::factory('Widget\\Archive')->beforeRender = [__CLASS__, 'onBeforeRender'];

        foreach (['Widget\\Contents\\Post\\Edit', 'Widget\\Contents\\Page\\Edit'] as $handle) {
            $factory = \Typecho\Plugin::factory($handle);
            $factory->finishPublish = [__CLASS__, 'onContentChanged'];
            $factory->finishDelete = [__CLASS__, 'onContentChanged'];
            $factory->finishMark = [__CLASS__, 'onContentChanged'];
        }

        \Typecho\Plugin::factory('Widget\\Feedback')->finishComment = [__CLASS__, 'onContentChanged'];

        $comments = \Typecho\Plugin::factory('Widget\\Comments\\Edit');
        $comments->finishComment = [__CLASS__, 'onContentChanged'];
        $comments->finishDelete = [__CLASS__, 'onContentChanged'];
        $comments->finishEdit = [__CLASS__, 'onContentChanged'];
        $comments->mark = [__CLASS__, 'onContentChanged'];
    }

    private static function handleBegin(): void
    {
        if (self::$started) {
            return;
        }

        self::$started = true;
        self::$settings = self::settings();
        if (self::$settings['enabled'] !== '1') {
            return;
        }

        if (self::wantsPurge((string) self::$settings['secret'])) {
            self::purgeAll();
            self::redirectLocal(self::currentPath());

            return;
        }

        if (!self::isCacheableRequest()) {
            return;
        }

        self::$loggedIn = self::viewerIsLoggedIn();
        if (self::$loggedIn && self::$settings['skip_login'] === '1') {
            return;
        }

        $store = self::openStore(self::$settings);
        self::$store = $store;
        $ttl = (int) self::$settings['ttl'];
        $grace = (int) self::$settings['grace'];
        $read = $store->read($ttl, $grace);

        if (self::$loggedIn) {
            if (is_string($read['html']) && ($read['state'] === 'hit' || $read['state'] === 'stale')) {
                self::deliverCached($read['html'], $read['state'] === 'hit' ? 'HIT' : 'STALE', $read['age']);
            }

            return;
        }

        if ($read['state'] === 'hit' && is_string($read['html']) && self::deliverCached($read['html'], 'HIT', $read['age'])) {
            return;
        }

        if ($store->backendName() === 'disk' && !$store->ensureDir()) {
            return;
        }

        $locked = $store->acquireLock(30);
        self::$locked = $locked;

        if (!$locked && $read['state'] === 'stale' && is_string($read['html']) && self::deliverCached($read['html'], 'STALE', $read['age'])) {
            return;
        }

        if (!$locked) {
            $waited = $store->waitForFresh($ttl, $grace, 1500);
            if ($waited['state'] === 'hit' && is_string($waited['html']) && self::deliverCached($waited['html'], 'HIT', $waited['age'])) {
                return;
            }

            if ($read['state'] === 'stale' && is_string($read['html']) && self::deliverCached($read['html'], 'STALE', $read['age'])) {
                return;
            }
        } else {
            ignore_user_abort(true);
        }

        self::$capture = true;
        self::$bufferLevel = ob_get_level();
        if (!ob_start()) {
            self::$capture = false;
            self::unlock();

            return;
        }

        self::$buffering = true;
        self::watchShutdown();
    }

    private static function finishOutput(string $html): void
    {
        if (
            !self::$confirmed
            || !self::$store instanceof Cache
            || self::$settings === null
            || headers_sent()
            || !self::isCacheableOutput($html)
        ) {
            echo $html;

            return;
        }

        $policy = self::dogePolicy();
        if ($policy === null && Cache::containsDogeAuthKey($html)) {
            echo $html;

            return;
        }

        $clean = $html;
        $served = $html;
        if ($policy !== null) {
            $cdn = (string) $policy['cdn_url'];
            $thumb = (string) $policy['thumb'];
            $expire = (int) $policy['token_expire'];
            $clean = Cache::rewriteCdnUrls($html, $cdn, $thumb, '', time(), $expire, 0);
            if (Cache::containsDogeAuthKey($clean)) {
                echo $html;

                return;
            }

            $served = (string) $policy['auth_key'] === ''
                ? $clean
                : Cache::rewriteCdnUrls($clean, $cdn, $thumb, (string) $policy['auth_key'], time(), $expire, self::visitorUid());
        }

        $stored = self::stamp($clean);
        echo self::stamp($served);

        $keep = (int) self::$settings['ttl'] + (int) self::$settings['grace'];
        if ($keep < 1) {
            $keep = 1;
        }

        if (!self::$store->write($stored, $keep)) {
            error_log('ShygoHomeCache: failed to store the homepage snapshot');
        }
    }

    private static function isCacheableOutput(string $html): bool
    {
        if (!Cache::looksLikeHtmlPage($html)) {
            return false;
        }

        if (self::responseStatus() !== 200) {
            return false;
        }

        if (function_exists('headers_list')) {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Location:') === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function responseStatus(): int
    {
        $code = http_response_code();
        $phpCode = is_int($code) ? $code : 200;
        if ($phpCode >= 400) {
            return $phpCode;
        }

        if (!class_exists('\\Typecho\\Response')) {
            return $phpCode;
        }

        try {
            $response = \Typecho\Response::getInstance();
            $property = new \ReflectionProperty($response, 'status');
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }

            $status = $property->getValue($response);
            if (is_int($status)) {
                return $status;
            }
        } catch (\Throwable $e) {
            return $phpCode;
        }

        return $phpCode;
    }

    private static function stamp(string $html): string
    {
        $note = '<!-- ShygoHomeCache ' . gmdate('Y-m-d\\TH:i:s\\Z') . " -->\n";
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $note;
        }

        return substr($html, 0, $pos) . $note . substr($html, $pos);
    }

    private static function takeBuffer(): ?string
    {
        return Cache::captureNestedBuffers(self::$bufferLevel);
    }

    /**
     * @return array{cdn_url:string,auth_key:string,thumb:string,token_expire:int}|null
     */
    private static function dogePolicy(): ?array
    {
        if (self::$dogePolicy !== false) {
            return self::$dogePolicy;
        }

        self::$dogePolicy = null;
        if (!class_exists('\\Typecho\\Plugin') || !\Typecho\Plugin::exists('DogeUpload')) {
            return null;
        }

        try {
            $config = Options::alloc()->plugin('DogeUpload');
        } catch (\Throwable $e) {
            return null;
        }

        $cdn = isset($config->cdn_url) ? trim((string) $config->cdn_url) : '';
        $auth = isset($config->auth_key) ? (string) $config->auth_key : '';
        $thumb = isset($config->thumb) ? trim((string) $config->thumb) : '';
        $expire = isset($config->token_expire) ? (int) $config->token_expire : 300;
        if ($cdn === '' || ($auth === '' && $thumb === '')) {
            return null;
        }

        if ($expire < 1) {
            $expire = 300;
        } elseif ($expire > 86400) {
            $expire = 86400;
        }

        self::$dogePolicy = [
            'cdn_url' => $cdn,
            'auth_key' => $auth,
            'thumb' => $thumb,
            'token_expire' => $expire,
        ];

        return self::$dogePolicy;
    }

    /**
     * Same expression as DogeUpload Model::getFileUrl: ip2long(Request::getIp()) ?: 0.
     * A negative ip2long (32-bit, address >= 128.0.0.0) stays negative so the uid
     * string matches sprintf('%s', $iplong).
     */
    private static function visitorUid(): int
    {
        $ip = '';
        if (class_exists('\\Typecho\\Request')) {
            try {
                $ip = (string) \Typecho\Request::getInstance()->getIp();
            } catch (\Throwable $e) {
                $ip = '';
            }
        }

        $long = $ip !== '' ? ip2long($ip) : false;

        return $long ? (int) $long : 0;
    }

    /**
     * Fresh signatures for this visitor. Null means the snapshot still contains
     * an expiring auth_key and this process cannot mint a new one.
     */
    private static function prepareCachedHtml(string $html): ?string
    {
        $policy = self::dogePolicy();
        if ($policy !== null && (string) $policy['auth_key'] !== '') {
            $ready = Cache::rewriteCdnUrls(
                $html,
                (string) $policy['cdn_url'],
                (string) $policy['thumb'],
                (string) $policy['auth_key'],
                time(),
                (int) $policy['token_expire'],
                self::visitorUid()
            );
            if (!Cache::authKeysRewritten($html, $ready)) {
                return null;
            }

            return $ready;
        }

        if (Cache::containsDogeAuthKey($html)) {
            return null;
        }

        if ($policy !== null && (string) $policy['thumb'] !== '') {
            return Cache::rewriteCdnUrls(
                $html,
                (string) $policy['cdn_url'],
                (string) $policy['thumb'],
                '',
                time(),
                (int) $policy['token_expire'],
                0
            );
        }

        return $html;
    }

    /**
     * @param int|null $age
     */
    private static function deliverCached(string $html, string $status, ?int $age): bool
    {
        $ready = self::prepareCachedHtml($html);
        if ($ready === null) {
            return false;
        }

        self::sendCached($ready, $status, $age);

        return true;
    }

    /**
     * @param mixed $archive
     */
    private static function archiveIsHome($archive): bool
    {
        if (!is_object($archive)) {
            return false;
        }

        if (self::archivePage($archive) > 1) {
            return false;
        }

        $type = '';
        if (isset($archive->parameter) && is_object($archive->parameter) && isset($archive->parameter->type)) {
            $type = (string) $archive->parameter->type;
        }

        if ($type === 'index' || $type === 'index_page') {
            return true;
        }

        try {
            return method_exists($archive, 'is') && $archive->is('index');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A custom file set as the front page returns before currentPage is assigned.
     * Reading it then violates the int return type, so treat that as page 1.
     *
     * @param object $archive
     */
    private static function archivePage($archive): int
    {
        if (method_exists($archive, 'getCurrentPage')) {
            try {
                $page = $archive->getCurrentPage();
                if (is_int($page) || (is_string($page) && is_numeric($page))) {
                    return max(1, (int) $page);
                }
            } catch (\Throwable $e) {
                return 1;
            }
        }

        return 1;
    }

    private static function isCacheableRequest(): bool
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return false;
        }

        if (defined('__TYPECHO_DEBUG__') && __TYPECHO_DEBUG__) {
            return false;
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0
        ) {
            return false;
        }

        if (Cache::hasMeaningfulQuery($_GET)) {
            return false;
        }

        return Cache::isHomePath(self::currentPath(), self::basePath());
    }

    private static function currentPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $pathInfo = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';

        return Cache::requestPathFrom($uri, $pathInfo);
    }

    private static function basePath(): string
    {
        try {
            $root = Options::alloc()->rootUrl;
            $parsed = parse_url((string) $root, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                return $parsed;
            }
        } catch (\Throwable $e) {
            return '/';
        }

        return '/';
    }

    private static function viewerIsLoggedIn(): bool
    {
        if (!class_exists('\\Typecho\\Cookie')) {
            return false;
        }

        $uid = \Typecho\Cookie::get('__typecho_uid');

        return is_string($uid) && $uid !== '' && ctype_digit($uid);
    }

    private static function wantsPurge(string $secret): bool
    {
        if ($secret === '' || !isset($_GET['purge']) || !is_string($_GET['purge'])) {
            return false;
        }

        $given = $_GET['purge'];
        if (strlen($given) > 128 || strlen($secret) > 128) {
            return false;
        }

        return hash_equals($secret, $given);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function openStore(array $settings): Cache
    {
        if (self::driverName($settings) === 'apcu') {
            return Cache::openApcu(self::apcuKey());
        }

        return Cache::openDisk(self::cacheDirectory());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function driverName(array $settings): string
    {
        $driver = (string) $settings['driver'];
        if ($driver === 'disk') {
            return 'disk';
        }

        if ($driver === 'apcu' || $driver === 'auto') {
            return Cache::apcuAvailable() ? 'apcu' : 'disk';
        }

        return Cache::apcuAvailable() ? 'apcu' : 'disk';
    }

    private static function sendCached(string $html, string $status, ?int $age): void
    {
        if (!class_exists('\\Typecho\\Response')) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
                header('X-Shygo-Home-Cache: ' . $status);
                header('Cache-Control: no-cache');
            }

            echo $html;
            exit;
        }

        $response = \Typecho\Response::getInstance();
        $response->setContentType('text/html');
        $response->setHeader('X-Shygo-Home-Cache', $status);
        if ($age !== null) {
            $response->setHeader('X-Shygo-Home-Cache-Age', (string) max(0, $age));
        }

        $browser = self::$settings !== null ? (int) self::$settings['browser_ttl'] : 0;
        if ($browser > 0) {
            $response->setHeader('Cache-Control', 'public, max-age=' . $browser);
        } else {
            $response->setHeader('Cache-Control', 'no-cache');
        }

        $response->addResponder(static function () use ($html): void {
            echo $html;
        });
        $response->respond();
    }

    private static function redirectLocal(string $path): void
    {
        $path = Cache::normalizePath($path);
        if ($path === '' || $path[0] !== '/' || strpbrk($path, "\\\r\n") !== false) {
            $path = '/';
        }

        if (class_exists('\\Typecho\\Common')) {
            $safe = \Typecho\Common::safeUrl($path);
            if (is_string($safe) && $safe !== '') {
                $path = $safe;
            }
        }

        if (!class_exists('\\Typecho\\Response')) {
            if (!headers_sent()) {
                header('Location: ' . $path, true, 302);
                header('Cache-Control: no-store');
            }

            exit;
        }

        $response = \Typecho\Response::getInstance();
        $response->setStatus(302);
        $response->setHeader('Location', $path);
        $response->setHeader('Cache-Control', 'no-store');
        $response->respond();
    }

    public static function purgeAll(): void
    {
        Cache::openDisk(self::cacheDirectory())->purge();
        if (Cache::apcuAvailable()) {
            Cache::openApcu(self::apcuKey())->purge();
        }
    }

    private static function watchShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function([__CLASS__, 'onShutdown']);
    }

    private static function unlock(): void
    {
        if (self::$locked && self::$store instanceof Cache) {
            self::$store->releaseLock();
        }

        self::$locked = false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        $defaults = [
            'enabled' => '1',
            'ttl' => 120,
            'grace' => 600,
            'driver' => 'auto',
            'secret' => '',
            'skip_login' => '1',
            'browser_ttl' => 0,
        ];

        try {
            $config = Options::alloc()->plugin('ShygoHomeCache');
        } catch (\Throwable $e) {
            return $defaults;
        }

        $ttl = isset($config->ttl) ? (int) $config->ttl : 120;
        if ($ttl < 30) {
            $ttl = 30;
        } elseif ($ttl > 86400) {
            $ttl = 86400;
        }

        $grace = isset($config->grace) ? (int) $config->grace : 600;
        if ($grace < 0) {
            $grace = 0;
        } elseif ($grace > 86400) {
            $grace = 86400;
        }

        $browser = isset($config->browser_ttl) ? (int) $config->browser_ttl : 0;
        if ($browser < 0) {
            $browser = 0;
        } elseif ($browser > 86400) {
            $browser = 86400;
        }

        $driver = isset($config->driver) ? (string) $config->driver : 'auto';
        if (!in_array($driver, ['auto', 'apcu', 'disk'], true)) {
            $driver = 'auto';
        }

        $secret = isset($config->secret) ? (string) $config->secret : '';
        if (strlen($secret) > 128) {
            $secret = substr($secret, 0, 128);
        }

        return [
            'enabled' => (isset($config->enabled) && (string) $config->enabled === '0') ? '0' : '1',
            'ttl' => $ttl,
            'grace' => $grace,
            'driver' => $driver,
            'secret' => $secret,
            'skip_login' => (isset($config->skip_login) && (string) $config->skip_login === '0') ? '0' : '1',
            'browser_ttl' => $browser,
        ];
    }

    private static function cacheDirectory(): string
    {
        return __DIR__ . '/cache';
    }

    private static function apcuKey(): string
    {
        $root = defined('__TYPECHO_ROOT_DIR__') ? (string) __TYPECHO_ROOT_DIR__ : __DIR__;
        $site = '';

        try {
            $rootUrl = Options::alloc()->rootUrl;
            if (is_string($rootUrl)) {
                $site = $rootUrl;
            }
        } catch (\Throwable $e) {
            $site = '';
        }

        return 'shygo_home_' . substr(md5($root . '|' . $site), 0, 16);
    }

    private static function secretDescription(): string
    {
        $status = htmlspecialchars(self::statusText(), ENT_QUOTES, 'UTF-8');
        $link = '';

        try {
            $purgeUrl = Options::alloc()->adminUrl(
                'options-plugin.php?config=ShygoHomeCache&shygo_purge=1',
                true
            );
            if (is_string($purgeUrl) && $purgeUrl !== '') {
                $link = '<br><a href="'
                    . htmlspecialchars($purgeUrl, ENT_QUOTES, 'UTF-8')
                    . '">'
                    . htmlspecialchars(_t('Clear homepage cache'), ENT_QUOTES, 'UTF-8')
                    . '</a>';
            }
        } catch (\Throwable $e) {
            $link = '';
        }

        return _t('Leave empty to disable URL purge. When set, GET /?purge=this-token deletes the snapshot. The token is written to access logs, so prefer the button below for routine clears.')
            . $link
            . '<br>'
            . $status;
    }

    private static function statusText(): string
    {
        $settings = self::settings();
        $ttl = (int) $settings['ttl'];
        $grace = (int) $settings['grace'];
        $disk = Cache::openDisk(self::cacheDirectory());
        $diskLabel = $disk->ensureDir()
            ? $disk->describe($ttl, $grace)
            : 'directory is not writable';
        $apcuLabel = Cache::apcuAvailable()
            ? Cache::openApcu(self::apcuKey())->describe($ttl, $grace)
            : 'extension not available';

        return 'Active storage: ' . self::driverName($settings)
            . '. Disk: ' . $diskLabel
            . '. APCu: ' . $apcuLabel
            . '.';
    }
}