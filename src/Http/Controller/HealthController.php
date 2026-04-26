<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Core\Database;
use OwnPay\Http\JsonResponse;
use OwnPay\Service\AlertService;

/**
 * HealthController — system health and monitoring endpoints.
 *
 * Endpoints:
 *   GET /v1/health                — overall system health
 *   GET /v1/health/reconciliation — latest reconciliation report
 */
final class HealthController
{
    /**
     * GET /v1/health — system health check.
     */
    public function index(): void
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'reconciliation' => $this->getReconciliationSummary(),
            'alerts' => $this->getAlertSummary(),
            'cron' => $this->getLastCronRuns(),
        ];

        $healthy = $checks['database']['status'] === 'ok';

        JsonResponse::success([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $healthy ? 200 : 503);
    }

    /**
     * GET /v1/health/reconciliation — latest reconciliation details.
     */
    public function reconciliation(): void
    {
        $db = Database::getInstance();

        try {
            $reports = $db->fetchAll("
                SELECT report_type, status, report_data, created_at
                FROM op_reconciliation_reports
                ORDER BY created_at DESC
                LIMIT 10
            ");

            foreach ($reports as &$r) {
                $r['report_data'] = json_decode($r['report_data'], true);
            }

            JsonResponse::success([
                'reports' => $reports,
                'total' => count($reports),
            ]);
        } catch (\PDOException $e) {
            JsonResponse::success([
                'reports' => [],
                'note' => 'Reconciliation table not available.',
            ]);
        }
    }

    /**
     * Check database connectivity.
     */
    private function checkDatabase(): array
    {
        try {
            Database::getInstance()->fetchColumn('SELECT 1');
            return ['status' => 'ok', 'latency_ms' => 0];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Database unreachable.'];
        }
    }

    /**
     * Get latest reconciliation status summary.
     */
    private function getReconciliationSummary(): array
    {
        try {
            $rows = Database::getInstance()->fetchAll("
                SELECT report_type, status, created_at
                FROM op_reconciliation_reports
                ORDER BY created_at DESC
                LIMIT 3
            ");

            return [
                'last_run' => $rows[0]['created_at'] ?? 'never',
                'status' => $rows[0]['status'] ?? 'unknown',
                'recent' => $rows,
            ];
        } catch (\PDOException $e) {
            return ['last_run' => 'never', 'status' => 'unavailable'];
        }
    }

    /**
     * Get open alert counts by severity.
     */
    private function getAlertSummary(): array
    {
        try {
            $alerts = new AlertService();
            return $alerts->countBySeverity();
        } catch (\Throwable $e) {
            return ['critical' => 0, 'warning' => 0, 'info' => 0];
        }
    }

    /**
     * Get last cron job execution times.
     */
    private function getLastCronRuns(): array
    {
        try {
            return Database::getInstance()->fetchAll("
                SELECT job_name, status, duration_ms, created_at
                FROM op_cron_logs
                WHERE id IN (
                    SELECT MAX(id) FROM op_cron_logs GROUP BY job_name
                )
                ORDER BY created_at DESC
            ");
        } catch (\PDOException $e) {
            return [];
        }
    }
}
