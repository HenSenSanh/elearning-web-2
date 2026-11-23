<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_service.php';

function ai_uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function ai_is_valid_uuid(string $id): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

function ai_job_create(int $userId, string $jobType, array $payload): string {
    if (!in_array($jobType, ['question', 'report'], true)) {
        throw new InvalidArgumentException('Loại tác vụ AI không hợp lệ.');
    }
    $id = ai_uuid_v4();
    $stmt = db()->prepare(
        'INSERT INTO ai_jobs (id, user_id, job_type, status, payload) VALUES (?, ?, ?, "queued", ?)'
    );
    $stmt->execute([$id, $userId, $jobType, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return $id;
}

function ai_job_find(string $id): ?array {
    if (!ai_is_valid_uuid($id)) return null;
    $stmt = db()->prepare('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? ai_job_hydrate($row) : null;
}

function ai_job_find_for_user(string $id, int $userId): ?array {
    if (!ai_is_valid_uuid($id)) return null;
    $stmt = db()->prepare('SELECT * FROM ai_jobs WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    return $row ? ai_job_hydrate($row) : null;
}

function ai_job_list_for_user(int $userId, ?string $jobType = null, int $limit = 30): array {
    $limit = max(1, min(100, $limit));
    if ($jobType !== null && in_array($jobType, ['question', 'report'], true)) {
        $stmt = db()->prepare(
            "SELECT * FROM ai_jobs WHERE user_id = ? AND job_type = ? ORDER BY created_at DESC LIMIT $limit"
        );
        $stmt->execute([$userId, $jobType]);
    } else {
        $stmt = db()->prepare(
            "SELECT * FROM ai_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit"
        );
        $stmt->execute([$userId]);
    }
    return array_map('ai_job_hydrate', $stmt->fetchAll());
}

function ai_job_hydrate(array $row): array {
    $row['payload_data'] = is_string($row['payload'] ?? null) ? (json_decode($row['payload'], true) ?: []) : [];
    $row['result_data'] = is_string($row['result_json'] ?? null) ? (json_decode($row['result_json'], true) ?: null) : null;
    return $row;
}

function ai_job_mark_running(string $id): bool {
    $stmt = db()->prepare(
        'UPDATE ai_jobs SET status = "running", started_at = NOW(), error_message = NULL WHERE id = ? AND status = "queued"'
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function ai_job_mark_succeeded(string $id, ?array $resultJson, ?string $resultMarkdown = null): void {
    $stmt = db()->prepare(
        'UPDATE ai_jobs SET status = "succeeded", result_json = ?, result_markdown = ?, error_message = NULL, finished_at = NOW() WHERE id = ?'
    );
    $stmt->execute([
        $resultJson !== null ? json_encode($resultJson, JSON_UNESCAPED_UNICODE) : null,
        $resultMarkdown,
        $id,
    ]);
}

function ai_job_mark_failed(string $id, string $errorMessage): void {
    $msg = mb_substr(trim($errorMessage), 0, 500, 'UTF-8');
    $stmt = db()->prepare(
        'UPDATE ai_jobs SET status = "failed", error_message = ?, finished_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$msg, $id]);
}

/**
 * Publish a persistent job UUID message to durable RabbitMQ queue `ai_jobs`.
 */
function ai_queue_publish(string $jobId): void {
    ai_autoload();
    if (!class_exists('PhpAmqpLib\\Connection\\AMQPStreamConnection')) {
        throw new RuntimeException('Thiếu thư viện php-amqplib. Hãy build lại container PHP.');
    }
    $config = require __DIR__ . '/config.php';
    $rmq = $config['rabbitmq'];

    $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
        $rmq['host'],
        (int)$rmq['port'],
        $rmq['user'],
        $rmq['password'],
        '/',
        false,
        'AMQPLAIN',
        null,
        'en_US',
        5.0,
        5.0
    );
    try {
        $channel = $connection->channel();
        $channel->queue_declare($rmq['queue'], false, true, false, false);
        $message = new \PhpAmqpLib\Message\AMQPMessage($jobId, [
            'delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'text/plain',
        ]);
        $channel->basic_publish($message, '', $rmq['queue']);
        $channel->close();
    } finally {
        $connection->close();
    }
}

function ai_job_status_label(string $status): string {
    return match ($status) {
        'queued' => 'Đang chờ xử lý',
        'running' => 'Đang chạy AI',
        'succeeded' => 'Hoàn tất',
        'failed' => 'Thất bại',
        default => $status,
    };
}
