<?php

namespace ClockworkRenegade\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class EntryFileDoubleLoadTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSecondCopyInTheSameRequestReturnsEarly(): void
    {
        if (! function_exists('register_activation_hook')) {
            function register_activation_hook(string $file, callable $callback): void
            {
            }
        }
        $entry = dirname(__DIR__).'/clockwork-renegade.php';

        // The test bootstrap may already define some constants; loading the
        // entry file twice must not raise "constant already defined".
        $errors = [];
        set_error_handler(static function (int $no, string $msg) use (&$errors): bool {
            $errors[] = $msg;

            return true;
        });
        try {
            include $entry;
            include $entry;
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], array_values(array_filter($errors, static fn ($m) => str_contains($m, 'already defined'))));
        $this->assertTrue(defined('CLOCKWORK_RENEGADE_VERSION'));
    }
}
