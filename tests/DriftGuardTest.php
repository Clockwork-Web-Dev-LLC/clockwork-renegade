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
