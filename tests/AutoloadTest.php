<?php

namespace KHQRPay\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Proves src/autoload.php works on its own, as it would inside a WordPress
 * plugin: a fresh PHP process with no Composer autoloader.
 */
final class AutoloadTest extends TestCase
{
    public function testTheStandaloneAutoloaderLoadsEveryClass(): void
    {
        $autoload = dirname(__DIR__) . '/src/autoload.php';
        $vectors = dirname(__DIR__) . '/tests/test-vectors.json';
        $script = <<<'PHP'
require $argv[1];
if (class_exists('Composer\Autoload\ClassLoader', false)) {
    fwrite(STDERR, "Composer is loaded\n");
    exit(1);
}
$client = new \KHQRPay\Client('kp_test_autoload');
$v = json_decode(file_get_contents($argv[2]), true);
$case = $v['cases'][0];
$ok = \KHQRPay\Webhook::verify($case['body'], $case['header'], $v['secret'], 300, $case['now']);
$error = new \KHQRPay\KHQRPayError('network_error', 'x', 0);
echo json_encode([
    get_class($client),
    get_class($client->payments),
    get_class($client->usage),
    $ok,
    $error instanceof \RuntimeException,
]);
PHP;

        $output = [];
        $exitCode = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg($autoload) . ' ' . escapeshellarg($vectors) . ' 2>&1',
            $output,
            $exitCode
        );

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame(
            '["KHQRPay\\\\Client","KHQRPay\\\\Service\\\\Payments","KHQRPay\\\\Service\\\\Usage",true,true]',
            implode("\n", $output)
        );
    }
}
