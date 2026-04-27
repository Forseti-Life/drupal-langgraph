<?php

namespace Drupal\drupal_langgraph\Service;

use Drupal\Core\File\FileSystemInterface;

final class ControlPlaneArtifactService {

  public function __construct(
    private readonly HqPathManager $paths,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function runtimeControlRequestBaseDir(): string {
    return $this->privateArtifactRoot('control-requests', $this->paths->artifactPaths()['control_requests_dir']);
  }

  public function checkpointReplayRequestBaseDir(): string {
    return $this->privateArtifactRoot('checkpoint-replays', $this->paths->artifactPaths()['control_requests_dir'] . '/checkpoint-replays');
  }

  public function flowVersionBaseDir(): string {
    return $this->privateArtifactRoot('flow-versions', $this->paths->artifactPaths()['control_requests_dir'] . '/versions');
  }

  public function promotionRequestBaseDir(): string {
    return $this->privateArtifactRoot('release-requests', $this->paths->artifactPaths()['control_requests_dir'] . '/release-requests');
  }

  public function promotionStateBaseDir(): string {
    return $this->privateArtifactRoot('promoted-versions', $this->paths->artifactPaths()['control_requests_dir'] . '/promoted-versions');
  }

  public function submitRuntimeRequest(array $flow, string $subsection, string $actor, array $values): array {
    $record = [
      'schema_version' => 1,
      'artifact_type' => 'runtime_request',
      'request_id' => $this->buildArtifactId('runtime'),
      'status' => 'requested',
      'status_message' => 'Awaiting runtime worker pickup.',
      'flow_id' => (string) ($flow['id'] ?? ''),
      'flow_label' => (string) ($flow['label'] ?? ''),
      'action' => $subsection,
      'requested_at' => gmdate('c'),
      'requested_by' => $actor,
      'reason' => (string) ($values['reason'] ?? ''),
    ];

    if (isset($values['requested_mode'])) {
      $record['requested_mode'] = (string) $values['requested_mode'];
    }
    if (isset($values['requested_state'])) {
      $record['requested_state'] = (string) $values['requested_state'];
    }

    $path = $this->writeArtifact(
      $this->runtimeControlRequestBaseDir(),
      $record['flow_id'],
      $record,
      sprintf('%s-%s.json', $this->timestampSlug($record['requested_at']), $subsection)
    );

    return $this->normalizeRuntimeRequestRecord($record, $path);
  }

  public function createVersionSnapshot(array $flow, string $actor, string $version_id, string $summary): array {
    $record = [
      'schema_version' => 1,
      'artifact_type' => 'version_snapshot',
      'request_id' => $this->buildArtifactId('version'),
      'status' => 'completed',
      'status_message' => 'Version snapshot recorded.',
      'flow_id' => (string) ($flow['id'] ?? ''),
      'flow_label' => (string) ($flow['label'] ?? ''),
      'version_id' => $version_id,
      'created_at' => gmdate('c'),
      'created_by' => $actor,
      'summary' => $summary,
      'flow_snapshot' => $flow,
    ];

    $path = $this->writeArtifact(
      $this->flowVersionBaseDir(),
      $record['flow_id'],
      $record,
      $this->sanitizeVersionId($version_id) . '.json'
    );

    return $this->normalizeVersionSnapshotRecord($record, $path);
  }

  public function submitReplayRequest(array $flow, string $actor, string $checkpoint_id, string $checkpoint_path, string $reason): array {
    if ($checkpoint_id === '' || $checkpoint_path === '') {
      throw new \InvalidArgumentException('Replay requests require a checkpoint ID and checkpoint path.');
    }

    $record = [
      'schema_version' => 1,
      'artifact_type' => 'replay_request',
      'request_id' => $this->buildArtifactId('replay'),
      'status' => 'requested',
      'status_message' => 'Awaiting replay worker pickup.',
      'flow_id' => (string) ($flow['id'] ?? ''),
      'flow_label' => (string) ($flow['label'] ?? ''),
      'checkpoint_id' => $checkpoint_id,
      'checkpoint_path' => $checkpoint_path,
      'requested_at' => gmdate('c'),
      'requested_by' => $actor,
      'reason' => $reason,
    ];

    $path = $this->writeArtifact(
      $this->checkpointReplayRequestBaseDir(),
      $record['flow_id'],
      $record,
      sprintf('%s-%s.json', $this->timestampSlug($record['requested_at']), $this->sanitizeVersionId($checkpoint_id))
    );

    return $this->normalizeReplayRequestRecord($record, $path);
  }

  public function submitPromotionRequest(array $flow, string $actor, string $version_id, string $reason): array {
    $record = [
      'schema_version' => 1,
      'artifact_type' => 'promotion_request',
      'request_id' => $this->buildArtifactId('promote'),
      'status' => 'requested',
      'status_message' => 'Awaiting release worker pickup.',
      'flow_id' => (string) ($flow['id'] ?? ''),
      'flow_label' => (string) ($flow['label'] ?? ''),
      'action' => 'promote',
      'version_id' => $version_id,
      'requested_at' => gmdate('c'),
      'requested_by' => $actor,
      'reason' => $reason,
    ];

    $path = $this->writeArtifact(
      $this->promotionRequestBaseDir(),
      $record['flow_id'],
      $record,
      sprintf('%s-promote.json', $this->timestampSlug($record['requested_at']))
    );

    return $this->normalizePromotionRequestRecord($record, $path);
  }

  public function listRuntimeRequests(?string $flow_id = NULL, int $limit = 25): array {
    $records = [];
    foreach ($this->artifactPaths($this->runtimeControlRequestBaseDir(), $flow_id, $limit) as $path) {
      $records[] = $this->normalizeRuntimeRequestRecord($this->readJsonFile($path), $path);
    }

    return $records !== [] ? $records : [$this->emptyRecord('runtime_request', 'No runtime requests recorded yet.')];
  }

  public function listReplayRequests(?string $flow_id = NULL, int $limit = 25): array {
    $records = [];
    foreach ($this->artifactPaths($this->checkpointReplayRequestBaseDir(), $flow_id, $limit) as $path) {
      $records[] = $this->normalizeReplayRequestRecord($this->readJsonFile($path), $path);
    }

    return $records !== [] ? $records : [$this->emptyRecord('replay_request', 'No replay requests recorded yet.')];
  }

  public function listVersionSnapshots(?string $flow_id = NULL, int $limit = 25): array {
    $records = [];
    foreach ($this->artifactPaths($this->flowVersionBaseDir(), $flow_id, $limit) as $path) {
      $records[] = $this->normalizeVersionSnapshotRecord($this->readJsonFile($path), $path);
    }

    return $records !== [] ? $records : [$this->emptyRecord('version_snapshot', 'No version snapshots recorded yet.')];
  }

  public function listPromotionRequests(?string $flow_id = NULL, int $limit = 25): array {
    $records = [];
    foreach ($this->artifactPaths($this->promotionRequestBaseDir(), $flow_id, $limit) as $path) {
      $records[] = $this->normalizePromotionRequestRecord($this->readJsonFile($path), $path);
    }

    return $records !== [] ? $records : [$this->emptyRecord('promotion_request', 'No promotion requests recorded yet.')];
  }

  public function currentPromotionState(string $flow_id): array {
    if ($flow_id === '') {
      return [];
    }

    $path = rtrim($this->promotionStateBaseDir(), '/') . '/' . $flow_id . '/current.json';
    if (!is_file($path)) {
      return [];
    }

    $payload = $this->readJsonFile($path);
    if ($payload === []) {
      return [];
    }

    $payload['path'] = $path;
    return $payload;
  }

  public function versionOptions(array $flow): array {
    $options = [];
    $current_version = (string) ($flow['version'] ?? '');
    if ($current_version !== '') {
      $options[$current_version] = $current_version . ' (current marker)';
    }

    foreach ($this->listVersionSnapshots((string) ($flow['id'] ?? '')) as $record) {
      $version_id = (string) ($record['version_id'] ?? '');
      if ($version_id !== '') {
        $options[$version_id] = $version_id;
      }
    }

    return $options !== [] ? $options : ['draft' => 'draft'];
  }

  private function privateArtifactRoot(string $directory_name, string $fallback_path): string {
    $private_root = $this->fileSystem->realpath('private://');
    if (is_string($private_root) && $private_root !== '') {
      return rtrim($private_root, '/') . '/drupal_langgraph/' . $directory_name;
    }
    return $fallback_path;
  }

  private function writeArtifact(string $base_dir, string $flow_id, array $record, string $filename): string {
    $directory = rtrim($base_dir, '/') . '/' . ($flow_id !== '' ? $flow_id : 'unknown-flow');
    $this->ensureDirectory($directory);
    $path = $directory . '/' . $filename;
    $bytes = file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if ($bytes === FALSE) {
      throw new \RuntimeException('Unable to write control-plane artifact: ' . $path);
    }
    return $path;
  }

  private function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException('Unable to create control-plane artifact directory: ' . $directory);
    }
  }

  private function artifactPaths(string $base_dir, ?string $flow_id, int $limit): array {
    $pattern = ($flow_id !== NULL && $flow_id !== '')
      ? rtrim($base_dir, '/') . '/' . $flow_id . '/*.json'
      : rtrim($base_dir, '/') . '/*/*.json';
    $paths = glob($pattern) ?: [];
    usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_slice($paths, 0, $limit);
  }

  private function normalizeRuntimeRequestRecord(array $payload, string $path): array {
    $detail_parts = [];
    if (($payload['requested_state'] ?? '') !== '') {
      $detail_parts[] = 'state=' . $payload['requested_state'];
    }
    if (($payload['requested_mode'] ?? '') !== '') {
      $detail_parts[] = 'mode=' . $payload['requested_mode'];
    }

    return [
      'artifact_type' => 'runtime_request',
      'request_id' => (string) ($payload['request_id'] ?? basename($path, '.json')),
      'status' => (string) ($payload['status'] ?? 'requested'),
      'status_message' => (string) ($payload['status_message'] ?? ''),
      'flow_id' => (string) ($payload['flow_id'] ?? ''),
      'flow_label' => (string) ($payload['flow_label'] ?? ''),
      'action' => (string) ($payload['action'] ?? basename($path, '.json')),
      'event_at' => (string) ($payload['requested_at'] ?? gmdate('c', (int) filemtime($path))),
      'actor' => (string) ($payload['requested_by'] ?? '-'),
      'detail' => $detail_parts !== [] ? implode(' ', $detail_parts) : (string) ($payload['reason'] ?? '-'),
      'path' => $path,
    ];
  }

  private function normalizeReplayRequestRecord(array $payload, string $path): array {
    return [
      'artifact_type' => 'replay_request',
      'request_id' => (string) ($payload['request_id'] ?? basename($path, '.json')),
      'status' => (string) ($payload['status'] ?? 'requested'),
      'status_message' => (string) ($payload['status_message'] ?? ''),
      'flow_id' => (string) ($payload['flow_id'] ?? ''),
      'flow_label' => (string) ($payload['flow_label'] ?? ''),
      'checkpoint_id' => (string) ($payload['checkpoint_id'] ?? basename($path, '.json')),
      'checkpoint_path' => (string) ($payload['checkpoint_path'] ?? ''),
      'event_at' => (string) ($payload['requested_at'] ?? gmdate('c', (int) filemtime($path))),
      'actor' => (string) ($payload['requested_by'] ?? '-'),
      'detail' => (string) ($payload['reason'] ?? $payload['status_message'] ?? '-'),
      'path' => $path,
    ];
  }

  private function normalizeVersionSnapshotRecord(array $payload, string $path): array {
    return [
      'artifact_type' => 'version_snapshot',
      'request_id' => (string) ($payload['request_id'] ?? basename($path, '.json')),
      'status' => (string) ($payload['status'] ?? 'completed'),
      'status_message' => (string) ($payload['status_message'] ?? ''),
      'flow_id' => (string) ($payload['flow_id'] ?? ''),
      'flow_label' => (string) ($payload['flow_label'] ?? ''),
      'version_id' => (string) ($payload['version_id'] ?? basename($path, '.json')),
      'event_at' => (string) ($payload['created_at'] ?? gmdate('c', (int) filemtime($path))),
      'actor' => (string) ($payload['created_by'] ?? '-'),
      'detail' => (string) ($payload['summary'] ?? '-'),
      'path' => $path,
    ];
  }

  private function normalizePromotionRequestRecord(array $payload, string $path): array {
    return [
      'artifact_type' => 'promotion_request',
      'request_id' => (string) ($payload['request_id'] ?? basename($path, '.json')),
      'status' => (string) ($payload['status'] ?? 'requested'),
      'status_message' => (string) ($payload['status_message'] ?? ''),
      'flow_id' => (string) ($payload['flow_id'] ?? ''),
      'flow_label' => (string) ($payload['flow_label'] ?? ''),
      'version_id' => (string) ($payload['version_id'] ?? '-'),
      'event_at' => (string) ($payload['requested_at'] ?? gmdate('c', (int) filemtime($path))),
      'actor' => (string) ($payload['requested_by'] ?? '-'),
      'detail' => (string) ($payload['reason'] ?? '-'),
      'path' => $path,
    ];
  }

  private function emptyRecord(string $artifact_type, string $detail): array {
    return [
      'artifact_type' => $artifact_type,
      'request_id' => '-',
      'status' => '-',
      'status_message' => '',
      'flow_id' => '',
      'flow_label' => '',
      'action' => '-',
      'version_id' => '-',
      'checkpoint_id' => '-',
      'event_at' => '-',
      'actor' => '-',
      'detail' => $detail,
      'path' => '-',
    ];
  }

  private function buildArtifactId(string $prefix): string {
    return sprintf('%s-%s-%s', $prefix, gmdate('YmdHis'), substr(sha1(microtime(TRUE) . $prefix), 0, 8));
  }

  private function timestampSlug(string $timestamp): string {
    return gmdate('Ymd-His', strtotime($timestamp) ?: time());
  }

  private function sanitizeVersionId(string $version_id): string {
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $version_id) ?: 'version';
    return trim($sanitized, '-');
  }

  private function readJsonFile(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
