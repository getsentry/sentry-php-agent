<?php

declare(strict_types=1);

namespace Sentry\Agent;

/**
 * Verifies the packaged PHAR against its adjacent Box-generated signature file.
 */
final class PharSignatureVerifier
{
    private const PHAR_SHA512_SIGNATURE_LENGTH = 64;
    private const PHAR_SHA512_SIGNATURE_HEX_LENGTH = 128;
    private const PHAR_SHA512_TRAILER = "\x04\x00\x00\x00GBMB";

    public static function verifyRunningPhar(): void
    {
        if (!class_exists('Phar') || \Phar::running(false) === '') {
            return;
        }

        $pharPath = \Phar::running(false);

        self::verify($pharPath, $pharPath . '.sig');
    }

    public static function verify(string $pharPath, string $signaturePath): void
    {
        $expectedSignature = self::readExpectedSignature($signaturePath);
        $actualSignature = self::readEmbeddedSha512Signature($pharPath);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new \RuntimeException('PHAR signature mismatch.');
        }
    }

    private static function readExpectedSignature(string $signaturePath): string
    {
        if (!is_file($signaturePath) || !is_readable($signaturePath)) {
            throw new \RuntimeException(\sprintf('PHAR signature file "%s" was not found or is not readable.', $signaturePath));
        }

        $contents = @file_get_contents($signaturePath);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Failed to read PHAR signature file "%s".', $signaturePath));
        }

        $signature = strtoupper(trim($contents));

        if (preg_match('/\A[0-9A-F]+\z/', $signature) !== 1 || \strlen($signature) !== self::PHAR_SHA512_SIGNATURE_HEX_LENGTH) {
            throw new \RuntimeException(\sprintf('PHAR signature file "%s" is malformed.', $signaturePath));
        }

        return $signature;
    }

    private static function readEmbeddedSha512Signature(string $pharPath): string
    {
        if (!is_file($pharPath) || !is_readable($pharPath)) {
            throw new \RuntimeException(\sprintf('PHAR "%s" was not found or is not readable.', $pharPath));
        }

        $contents = @file_get_contents($pharPath);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Failed to read PHAR "%s".', $pharPath));
        }

        $signatureTrailerLength = self::PHAR_SHA512_SIGNATURE_LENGTH + \strlen(self::PHAR_SHA512_TRAILER);

        if (\strlen($contents) < $signatureTrailerLength) {
            throw new \RuntimeException(\sprintf('Failed to read PHAR signature from "%s".', $pharPath));
        }

        $trailer = substr($contents, -\strlen(self::PHAR_SHA512_TRAILER));

        if ($trailer !== self::PHAR_SHA512_TRAILER) {
            throw new \RuntimeException(\sprintf('PHAR "%s" is not signed with the expected SHA-512 signature trailer.', $pharPath));
        }

        $signature = substr($contents, -$signatureTrailerLength, self::PHAR_SHA512_SIGNATURE_LENGTH);

        return strtoupper(bin2hex($signature));
    }
}
