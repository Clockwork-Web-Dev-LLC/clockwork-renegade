<?php

namespace ClockworkRenegade\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DriftGuardTest extends TestCase
{
    public function testNoLegacyCompanionStringsInSrc(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            $this->assertStringNotContainsString(
                'ClockworkCompanion',
                $content,
                "File {$file->getFilename()} contains legacy ClockworkCompanion namespace."
            );

            $this->assertStringNotContainsString(
                'clockwork_companion_',
                $content,
                "File {$file->getFilename()} contains legacy clockwork_companion_ option prefix."
            );

            $this->assertStringNotContainsString(
                'CLOCKWORK_COMPANION_',
                $content,
                "File {$file->getFilename()} contains legacy CLOCKWORK_COMPANION_ constant."
            );
        }
    }

    public function testAllGettextCallsUseRenegadeTextDomain(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        // Every gettext call must pass the plugin's own text domain as its
        // final string argument. A wrong or missing domain silently breaks
        // translation loading — invisible in English testing, so guard it.
        $pattern = '/\b(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(?:(?:[^()]|\([^()]*\))*?)[\'"]([a-z0-9-]+)[\'"]\s*\)/s';

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            if (! preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $domain) {
                $this->assertSame(
                    'clockwork-renegade',
                    $domain,
                    "File {$file->getFilename()} has a gettext call with text domain '{$domain}' instead of 'clockwork-renegade'."
                );
            }
        }
    }

    public function testNoRawEvalExecutionInSrc(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Exclude signature pattern strings inside MalwareScanner
            if ($file->getFilename() === 'MalwareScanner.php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/\beval\s*\(/i',
                $content,
                "File {$file->getFilename()} must not invoke eval()."
            );
        }
    }
}
