<?php

namespace KHQRPay\Tests\Support;

/**
 * Starts `php -S 127.0.0.1:<port>` with tests/fixtures/router.php, so the
 * HTTP tests go through real curl and a real socket.
 */
final class FakeServer
{
    /** @var resource */
    private $process;

    /** @var int */
    private $port;

    /** @var string */
    private $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/khqrpay-php-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0700) && !is_dir($this->dir)) {
            throw new \RuntimeException('Could not create ' . $this->dir);
        }

        $lastError = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = self::freePort();
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/router.php'],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $this->dir . '/server.log', 'a'],
                    2 => ['file', $this->dir . '/server.log', 'a'],
                ],
                $pipes,
                null,
                array_merge(getenv(), ['KHQRPAY_FAKE_DIR' => $this->dir])
            );
            if (!is_resource($process)) {
                $lastError = 'proc_open failed';
                continue;
            }
            if (self::waitUntilListening($process, $port)) {
                $this->process = $process;
                $this->port = $port;

                return;
            }
            proc_terminate($process);
            proc_close($process);
            $lastError = (string) @file_get_contents($this->dir . '/server.log');
        }

        throw new \RuntimeException('The fake server did not start: ' . $lastError);
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Sets the next reply.
     *
     * @param array<string,string> $headers
     */
    public function respond(int $status, string $body, array $headers = ['Content-Type' => 'application/json'], int $delayMs = 0): void
    {
        file_put_contents($this->dir . '/response.json', json_encode([
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'delay_ms' => $delayMs,
        ]));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function respondJson(int $status, array $data, array $headers = []): void
    {
        $this->respond(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            array_merge(['Content-Type' => 'application/json'], $headers)
        );
    }

    /**
     * The last request the server saw: method, uri (raw), headers (lowercase names), body.
     *
     * @return array<string,mixed>
     */
    public function lastRequest(): array
    {
        $file = $this->dir . '/request.json';
        if (!is_file($file)) {
            throw new \RuntimeException('The fake server saw no request.');
        }

        return json_decode((string) file_get_contents($file), true);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * A port nothing is listening on right now.
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('Could not find a free port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * @param resource $process
     */
    private static function waitUntilListening($process, int $port): bool
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return false;
            }
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }
            usleep(20000);
        }

        return false;
    }
}
