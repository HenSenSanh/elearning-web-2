<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/ai_service.php';
require_once __DIR__ . '/../src/ai_job_repository.php';

ai_autoload();

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function worker_log(string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function worker_ensure_db(): void {
    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        // Static PDO inside db() may have timed out; exit so Docker restarts cleanly
        worker_log('MySQL connection lost: ' . $e->getMessage());
        exit(1);
    }
}

function worker_process_job(string $jobId): void {
    worker_ensure_db();

    if (!ai_is_valid_uuid($jobId)) {
        worker_log("Ignoring invalid job UUID: {$jobId}");
        return;
    }

    $job = ai_job_find($jobId);
    if (!$job) {
        worker_log("Job not found in database: {$jobId}");
        return;
    }

    if (in_array($job['status'], ['succeeded', 'failed'], true)) {
        worker_log("Job {$jobId} already finished ({$job['status']}), skipping.");
        return;
    }

    if (!ai_job_mark_running($jobId)) {
        worker_log("Job {$jobId} could not transition queued -> running (current: {$job['status']}), skipping.");
        return;
    }

    $payload = $job['payload_data'];
    $requestedModel = trim((string)($payload['model'] ?? '')) ?: null;
    worker_log("Started job {$jobId} (type={$job['job_type']}, model=" . ($requestedModel ?? 'default') . ")");

    try {
        if ($job['job_type'] === 'question') {
            $question = trim((string)($payload['question'] ?? ''));
            $result = ai_ask_question($question, $requestedModel);
            ai_job_mark_succeeded($jobId, $result, null);
            worker_log("Succeeded question job {$jobId} (model={$result['model']}, rows={$result['row_count']})");
        } elseif ($job['job_type'] === 'report') {
            $slug = trim((string)($payload['template'] ?? ''));
            $report = report_generate_files($jobId, $slug, $requestedModel);
            $summary = [
                'template' => $report['slug'],
                'model' => $report['model'],
                'generated_at' => $report['generated_at'],
                'md_file' => $report['md_file'],
                'pdf_file' => $report['pdf_file'],
                'queries' => $report['queries'],
            ];
            ai_job_mark_succeeded($jobId, $summary, $report['markdown']);
            worker_log("Succeeded report job {$jobId} (template={$slug}, model={$report['model']})");
        } else {
            throw new RuntimeException('Loại job không được hỗ trợ: ' . $job['job_type']);
        }
    } catch (Throwable $e) {
        worker_log("Failed job {$jobId}: " . $e->getMessage());
        ai_job_mark_failed($jobId, $e->getMessage());
    }
}

$config = require __DIR__ . '/../src/config.php';
$rmq = $config['rabbitmq'];

while (true) {
    $connection = null;
    try {
        worker_log("Connecting to RabbitMQ {$rmq['host']}:{$rmq['port']} queue={$rmq['queue']}...");
        $connection = new AMQPStreamConnection(
            $rmq['host'],
            (int)$rmq['port'],
            $rmq['user'],
            $rmq['password'],
            '/',
            false,
            'AMQPLAIN',
            null,
            'en_US',
            10.0,
            10.0,
            null,
            false,
            60
        );
        $channel = $connection->channel();
        $channel->queue_declare($rmq['queue'], false, true, false, false);
        $channel->basic_qos(null, 1, null);

        worker_log('Worker ready. Waiting for jobs...');

        $channel->basic_consume(
            $rmq['queue'],
            '',
            false,
            false,
            false,
            false,
            static function (AMQPMessage $msg): void {
                $jobId = trim($msg->getBody());
                try {
                    worker_process_job($jobId);
                } catch (Throwable $e) {
                    worker_log("Unexpected error for job {$jobId}: " . $e->getMessage());
                } finally {
                    // Always ACK after persisting state to MySQL to avoid infinite requeue loops
                    $msg->ack();
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    } catch (Throwable $e) {
        worker_log('Worker connection/loop error: ' . $e->getMessage());
        sleep(3);
    } finally {
        if ($connection !== null) {
            try {
                $connection->close();
            } catch (Throwable $ignored) {
            }
        }
    }
}
