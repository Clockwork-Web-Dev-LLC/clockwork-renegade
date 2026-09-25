<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\ActionLog\Repository;
use ClockworkRenegade\ActionLog\Schema;
use ClockworkRenegade\Admin\Pages\PerformancePage;
use PHPUnit\Framework\TestCase;

class PerformancePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->table_rows[Schema::tableName()] = [];
    }

    public function testLatestPerformanceScanFindsPressableEngine(): void
    {
        global $wpdb;
        $wpdb->table_rows[Schema::tableName()] = [
            [
                'id' => 1,
                'action_type' => 'performance_scan',
                'ok' => 1,
                'care_plan_enabled' => 1,
                'details' => json_encode([
                    'engine' => 'pressable',
                    'strategy' => 'mobile',
                    'performance_score' => 78,
                    'lcp_ms' => 1800,
                    'cls_x1000' => 5,
                ]),
                'ran_at' => '2026-09-20 12:00:00',
            ],
        ];

        $latest = Repository::latestPerformanceScanFromEngines(['gtmetrix', 'pressable']);
        $this->assertNotNull($latest);
        $this->assertEquals(1, $latest['id']);

        $summary = PerformancePage::summary();
        $this->assertTrue($summary['hasScore']);
        $this->assertEquals(78, $summary['score']);
        $this->assertEquals('B', $summary['grade']);
    }

    public function testLatestPerformanceScanFindsGtmetrixEngine(): void
    {
        global $wpdb;
        $wpdb->table_rows[Schema::tableName()] = [
            [
                'id' => 2,
                'action_type' => 'performance_scan',
                'ok' => 1,
                'care_plan_enabled' => 1,
                'details' => json_encode([
                    'engine' => 'gtmetrix',
                    'strategy' => 'desktop',
                    'performance_score' => 95,
                    'lcp_ms' => 900,
                    'cls_x1000' => 0,
                ]),
                'ran_at' => '2026-09-21 12:00:00',
            ],
        ];

        $latest = Repository::latestPerformanceScanFromEngines(['gtmetrix', 'pressable']);
        $this->assertNotNull($latest);
        $this->assertEquals(2, $latest['id']);

        $summary = PerformancePage::summary();
        $this->assertTrue($summary['hasScore']);
        $this->assertEquals(95, $summary['score']);
        $this->assertEquals('A', $summary['grade']);
    }

    public function testLatestPerformanceScanIgnoresPsiFallbackAndFailedScans(): void
    {
        global $wpdb;
        $wpdb->table_rows[Schema::tableName()] = [
            [
                'id' => 3,
                'action_type' => 'performance_scan',
                'ok' => 1,
                'care_plan_enabled' => 1,
                'details' => json_encode([
                    'engine' => 'psi-fallback',
                    'performance_score' => 45,
                ]),
                'ran_at' => '2026-09-22 12:00:00',
            ],
            [
                'id' => 4,
                'action_type' => 'performance_scan',
                'ok' => 0,
                'care_plan_enabled' => 1,
                'details' => json_encode([
                    'engine' => 'pressable',
                    'performance_score' => null,
                ]),
                'ran_at' => '2026-09-23 12:00:00',
            ],
        ];

        $latest = Repository::latestPerformanceScanFromEngines(['gtmetrix', 'pressable']);
        $this->assertNull($latest);

        $summary = PerformancePage::summary();
        $this->assertFalse($summary['hasScore']);
    }

    public function testPerformancePageRendersBodyWithPressableScan(): void
    {
        global $wpdb;
        $wpdb->table_rows[Schema::tableName()] = [
            [
                'id' => 5,
                'action_type' => 'performance_scan',
                'ok' => 1,
                'care_plan_enabled' => 1,
                'details' => json_encode([
                    'engine' => 'pressable',
                    'strategy' => 'mobile',
                    'performance_score' => 88,
                    'lcp_ms' => 1200,
                    'cls_x1000' => 2,
                    'fcp_ms' => 800,
                    'tbt_ms' => 50,
                    'si_ms' => 1500,
                    'page_weight_bytes' => 1048576,
                ]),
                'ran_at' => '2026-09-24 10:00:00',
            ],
        ];

        ob_start();
        PerformancePage::renderBody();
        $output = ob_get_clean();

        $this->assertStringContainsString('88/100', $output);
        $this->assertStringContainsString('Pressable Lighthouse (simulated mobile phone)', $output);
        $this->assertStringContainsString('Pressable', $output);
        $this->assertStringContainsString('1.0 MB', $output);
    }
}
