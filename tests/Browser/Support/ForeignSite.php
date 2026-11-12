<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A page that is not ours: a static site served by PHP's own web server on a second loopback
 * port, so that a browser sees a second origin with no network and no DNS. The application
 * the browser plugin serves answers on 127.0.0.1 at one port and, for a tenant, on
 * {slug}.localhost at the same port; 127.0.0.1 at another port is a third origin to a browser,
 * one no route of ours answers on, which is what the embed's cross-origin promise is about.
 * Pages are written per test with the tenant origin they embed from.
 *
 * Started once per file and stopped after it, with a shutdown hook so a failing run leaves
 * no server behind.
 */
final class ForeignSite
{
    private static ?self $current = null;

    private function __construct(
        private readonly Process $server,
        private readonly string $root,
        public readonly string $origin,
    ) {}

    public static function start(): self
    {
        if (self::$current instanceof self) {
            return self::$current;
        }

        $port = self::freePort();
        $root = sys_get_temp_dir().'/kr-foreign-'.bin2hex(random_bytes(6));

        (new Filesystem)->makeDirectory($root, recursive: true);

        $server = new Process([PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), '-t', $root], $root);
        $server->start();

        $site = new self($server, $root, sprintf('http://127.0.0.1:%d', $port));
        $site->awaitListening();

        register_shutdown_function(static fn () => self::stop());

        return self::$current = $site;
    }

    public static function current(): self
    {
        if (! self::$current instanceof self) {
            throw new RuntimeException('The foreign site is not running; start it in beforeAll.');
        }

        return self::$current;
    }

    public static function stop(): void
    {
        if (! self::$current instanceof self) {
            return;
        }

        $site = self::$current;
        self::$current = null;

        $site->server->stop(3);
        (new Filesystem)->deleteDirectory($site->root);
    }

    /**
     * Writes a page under the site and answers the URL a browser opens it at.
     */
    public function page(string $name, string $html): string
    {
        (new Filesystem)->put($this->root.'/'.$name, $html);

        return $this->origin.'/'.$name;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException(sprintf('No free loopback port: %s (%d).', $errstr, $errno));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || preg_match('/:(\d+)\z/', $name, $matches) !== 1) {
            throw new RuntimeException('The loopback socket reported no port.');
        }

        return (int) $matches[1];
    }

    private function awaitListening(): void
    {
        $deadline = microtime(true) + 5.0;
        $port = (int) parse_url($this->origin, PHP_URL_PORT);

        while (microtime(true) < $deadline) {
            if (! $this->server->isRunning()) {
                throw new RuntimeException('The foreign site exited before listening: '.$this->server->getErrorOutput());
            }

            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(20_000);
        }

        $this->server->stop(1);

        throw new RuntimeException(sprintf('The foreign site did not listen on %s within five seconds.', $this->origin));
    }
}
