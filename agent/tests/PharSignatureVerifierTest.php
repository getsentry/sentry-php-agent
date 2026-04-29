<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\Agent\PharSignatureVerifier;

class PharSignatureVerifierTest extends TestCase
{
    private const PHAR_SHA512_TRAILER = "\x04\x00\x00\x00GBMB";

    /**
     * @var string[]
     */
    private $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (\is_file($temporaryFile)) {
                \unlink($temporaryFile);
            }
        }

        $this->temporaryFiles = [];
    }

    public function testVerifySucceedsWhenSignatureMatches(): void
    {
        $fixture = $this->createSha512SignedPharFixture();

        PharSignatureVerifier::verify($fixture['phar'], $fixture['signature']);

        $this->addToAssertionCount(1);
    }

    public function testVerifyFailsWhenSignatureDoesNotMatch(): void
    {
        $fixture = $this->createSha512SignedPharFixture();

        $this->writeFile($fixture['signature'], \str_repeat('B2', 64) . "\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHAR signature mismatch.');

        PharSignatureVerifier::verify($fixture['phar'], $fixture['signature']);
    }

    public function testVerifyFailsWhenSignatureFileIsMissing(): void
    {
        $fixture = $this->createSha512SignedPharFixture();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was not found or is not readable');

        PharSignatureVerifier::verify($fixture['phar'], $fixture['signature'] . '.missing');
    }

    public function testVerifyFailsWhenSignatureFileIsMalformed(): void
    {
        $fixture = $this->createSha512SignedPharFixture();

        $this->writeFile($fixture['signature'], 'not-a-signature');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is malformed');

        PharSignatureVerifier::verify($fixture['phar'], $fixture['signature']);
    }

    public function testVerifyFailsWhenPharDoesNotUseSha512Trailer(): void
    {
        $fixture = $this->createPharFixture("\x03\x00\x00\x00GBMB");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not signed with the expected SHA-512 signature trailer');

        PharSignatureVerifier::verify($fixture['phar'], $fixture['signature']);
    }

    public function testVerifyRunningPharSkipsSourceMode(): void
    {
        PharSignatureVerifier::verifyRunningPhar();

        $this->addToAssertionCount(1);
    }

    /**
     * @return array{phar: string, signature: string}
     */
    private function createSha512SignedPharFixture(): array
    {
        return $this->createPharFixture(self::PHAR_SHA512_TRAILER);
    }

    /**
     * @return array{phar: string, signature: string}
     */
    private function createPharFixture(string $trailer): array
    {
        $signature = \str_repeat("\xA1", 64);
        $phar = $this->createTemporaryFile();
        $signatureFile = $this->createTemporaryFile();

        $this->writeFile($phar, 'phar contents' . $signature . $trailer);
        $this->writeFile($signatureFile, \strtoupper(\bin2hex($signature)) . "\n");

        return [
            'phar' => $phar,
            'signature' => $signatureFile,
        ];
    }

    private function createTemporaryFile(): string
    {
        $temporaryFile = \tempnam(\sys_get_temp_dir(), 'sentry-agent-phar-');

        if ($temporaryFile === false) {
            throw new \RuntimeException('Failed to create temporary file.');
        }

        $this->temporaryFiles[] = $temporaryFile;

        return $temporaryFile;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(\sprintf('Failed to write temporary file "%s".', $path));
        }
    }
}
