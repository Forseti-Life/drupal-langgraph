<?php

namespace Drupal\drupal_langgraph\Service;

use Drupal\Component\Utility\Html;

final class ProjectRegistryService {

  public function __construct(
    private readonly HqPathManager $paths,
  ) {}

  public function loadRegistry(): array {
    $markdown = $this->loadMarkdown();
    if ($markdown === '') {
      return [];
    }

    return $this->parseProjectsRegistry($markdown);
  }

  public function loadProject(string $project_id): ?array {
    $markdown = $this->loadMarkdown();
    if ($markdown === '') {
      return NULL;
    }

    $project_id = strtoupper(trim($project_id));
    $registry = $this->parseProjectsRegistry($markdown);
    $details = $this->parseProjectSections($markdown);

    foreach ($registry as $row) {
      if (($row['id'] ?? '') !== $project_id) {
        continue;
      }

      $detail = $details[$project_id] ?? [];
      return [
        'project_id' => $project_id,
        'name' => $row['name'] ?? $project_id,
        'type' => $row['type'] ?? '',
        'product' => $row['product'] ?? '',
        'status' => $row['status'] ?? '',
        'priority' => $row['priority'] ?? '',
        'lead' => $row['lead'] ?? '',
        'started' => $row['started'] ?? '',
        'project_manager' => $this->resolveRoadmapProjectManager($row),
        'roadmap_reference' => $detail['roadmap_reference'] ?? '',
        'scope' => $detail['scope'] ?? '',
        'problem' => $detail['problem'] ?? '',
        'current_state' => $detail['current_state'] ?? '',
        'last_scoped_release' => $detail['last_scoped_release'] ?? '',
        'progress_sla' => $detail['progress_sla'] ?? '',
        'next_step' => $detail['next_step'] ?? '',
        'queue_status' => $detail['queue_status'] ?? '',
        'goals' => $detail['goals'] ?? [],
      ];
    }

    return NULL;
  }

  public function roadmapCards(): array {
    $markdown = $this->loadMarkdown();
    if ($markdown === '') {
      return [];
    }

    $registry = $this->parseProjectsRegistry($markdown);
    $details = $this->parseProjectSections($markdown);
    $cards = [];

    foreach ($registry as $row) {
      $project_id = $row['id'];
      $detail = $details[$project_id] ?? [];
      $summary = $detail['scope'] ?: ($detail['problem'] ?: 'Portfolio initiative tracked in the HQ project registry.');
      $roadmap = [];

      foreach (['current_state' => 'Current state', 'next_step' => 'Next step', 'queue_status' => 'Queue status'] as $key => $label) {
        if (!empty($detail[$key])) {
          $roadmap[] = $label . ': ' . $detail[$key];
        }
      }

      if (!empty($detail['last_scoped_release'])) {
        array_splice($roadmap, min(1, count($roadmap)), 0, ['Last scoped release: ' . $detail['last_scoped_release']]);
      }

      if ($roadmap === [] && !empty($detail['goals'])) {
        $roadmap = $detail['goals'];
      }

      if ($roadmap === []) {
        $roadmap[] = 'See dashboards/PROJECTS.md for the current execution notes.';
      }

      $cards[] = [
        'project_id' => $project_id,
        'name' => $row['name'] ?? $project_id,
        'type' => $row['type'] ?? '',
        'product' => $row['product'] ?? '',
        'status' => $row['status'] ?? '',
        'priority' => $row['priority'] ?? '',
        'lead' => $row['lead'] ?? '',
        'started' => $row['started'] ?? '',
        'project_manager' => $this->resolveRoadmapProjectManager($row),
        'last_scoped_release' => $detail['last_scoped_release'] ?? '',
        'progress_sla' => $detail['progress_sla'] ?? '',
        'queue_status' => $detail['queue_status'] ?? '',
        'summary' => $summary,
        'roadmap' => $roadmap,
      ];
    }

    return $cards;
  }

  public function summarizeProgressStatus(string $last_scoped_release, string $progress_sla, string $queue_status = ''): string {
    if ($progress_sla === '') {
      return '';
    }

    if (!preg_match('/(\d+)\s*days?/i', $progress_sla, $sla_matches)) {
      return 'SLA defined';
    }

    $sla_days = (int) $sla_matches[1];
    if ($sla_days <= 0) {
      return 'SLA defined';
    }

    if (preg_match('/(20\d{6})-/', $last_scoped_release, $release_matches)) {
      $release_date = \DateTimeImmutable::createFromFormat('!Ymd', $release_matches[1], new \DateTimeZone('UTC'));
      if ($release_date instanceof \DateTimeImmutable) {
        $age_days = (int) $release_date->diff(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->days;
        return $age_days > $sla_days ? 'SLA breach' : 'On track';
      }
    }

    if (str_contains(mb_strtolower($queue_status), 'queued')) {
      return 'Needs scoped release';
    }

    return 'Missing progression evidence';
  }

  public function formatRoadmapStatus(string $status, string $priority = ''): string {
    $label = ucwords(str_replace('_', ' ', trim($status)));
    return trim($label . ($priority !== '' ? ' - ' . trim($priority) : ''));
  }

  private function loadMarkdown(): string {
    $path = $this->paths->projectRegistryPath();
    if (!is_readable($path)) {
      return '';
    }

    $markdown = @file_get_contents($path);
    return $markdown === FALSE ? '' : $markdown;
  }

  private function parseProjectsRegistry(string $markdown): array {
    if (!preg_match('/^## Registry\s*$([\s\S]*?)(?:^---\s*$)/m', $markdown, $matches)) {
      return [];
    }

    $rows = [];
    foreach (preg_split('/\R/', trim($matches[1])) as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || $trimmed[0] !== '|' || str_contains($trimmed, '|---')) {
        continue;
      }

      $parts = array_map('trim', explode('|', trim($trimmed, '|')));
      if (count($parts) !== 8 || $parts[0] === 'ID') {
        continue;
      }

      $rows[] = [
        'id' => $parts[0],
        'name' => $parts[1],
        'type' => $parts[2],
        'product' => $parts[3],
        'status' => $parts[4],
        'priority' => $parts[5],
        'lead' => $parts[6],
        'started' => $parts[7],
      ];
    }

    usort($rows, fn(array $a, array $b): int => $this->extractProjectNumber((string) $a['id']) <=> $this->extractProjectNumber((string) $b['id']));
    return $rows;
  }

  private function parseProjectSections(string $markdown): array {
    $matches = [];
    preg_match_all('/^## (PROJ-\d+) — (.+)$/m', $markdown, $matches, PREG_OFFSET_CAPTURE);
    if (empty($matches[0])) {
      return [];
    }

    $sections = [];
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
      $project_id = $matches[1][$i][0];
      $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
      $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($markdown);
      $body = trim(substr($markdown, $start, $end - $start));
      $sections[$project_id] = [
        'roadmap_reference' => $this->extractLabeledValue($body, 'Roadmap') ?: $this->extractLabeledValue($body, 'Roadmap audit runbook'),
        'scope' => $this->extractLabeledValue($body, 'Scope'),
        'problem' => $this->extractParagraphAfterHeading($body, '### Problem'),
        'current_state' => $this->extractLabeledValue($body, 'Current state') ?: $this->extractLabeledValue($body, 'Current status'),
        'last_scoped_release' => $this->extractLabeledValue($body, 'Last scoped release'),
        'progress_sla' => $this->extractLabeledValue($body, 'Progress SLA'),
        'next_step' => $this->extractLabeledValue($body, 'Next step'),
        'queue_status' => $this->extractLabeledValue($body, 'Queue status'),
        'goals' => $this->extractBulletsAfterHeading($body, '### Goals'),
      ];
    }

    return $sections;
  }

  private function extractLabeledValue(string $text, string $label): string {
    $label_pattern = preg_quote($label, '/') . '(?:\s*\([^)]+\))?';
    if (preg_match('/^\*\*' . $label_pattern . ':\*\*\s*(.+)$/m', $text, $matches)) {
      return Html::decodeEntities(trim(strip_tags($matches[1])));
    }
    if (preg_match('/^' . $label_pattern . ':\s*(.+)$/m', $text, $matches)) {
      return Html::decodeEntities(trim(strip_tags($matches[1])));
    }
    return '';
  }

  private function extractParagraphAfterHeading(string $text, string $heading): string {
    if (!preg_match('/^' . preg_quote($heading, '/') . '\s*$([\s\S]*?)(?:^\s*$|^### |\z)/m', $text, $matches)) {
      return '';
    }
    $paragraph = trim(preg_replace('/\s+/', ' ', $matches[1]));
    return Html::decodeEntities(trim(strip_tags($paragraph)));
  }

  private function extractBulletsAfterHeading(string $text, string $heading): array {
    if (!preg_match('/^' . preg_quote($heading, '/') . '\s*$([\s\S]*?)(?:^### |\z)/m', $text, $matches)) {
      return [];
    }

    $items = [];
    foreach (preg_split('/\R/', trim($matches[1])) as $line) {
      if (preg_match('/^\d+\.\s+(.+)$/', trim($line), $bullet) || preg_match('/^-\s+(.+)$/', trim($line), $bullet)) {
        $items[] = Html::decodeEntities(trim(strip_tags($bullet[1])));
      }
    }
    return $items;
  }

  private function resolveRoadmapProjectManager(array $row): string {
    $lead = (string) ($row['lead'] ?? '');
    if (preg_match('/\b(pm-[a-z0-9-]+)\b/i', $lead, $matches)) {
      return strtolower($matches[1]);
    }

    return match (strtolower(trim((string) ($row['product'] ?? '')))) {
      'dungeoncrawler' => 'pm-dungeoncrawler',
      'forseti.life' => 'pm-forseti',
      'infrastructure' => 'pm-infra',
      default => '',
    };
  }

  private function extractProjectNumber(string $project_id): int {
    if (preg_match('/^PROJ-(\d+)$/i', trim($project_id), $matches)) {
      return (int) $matches[1];
    }
    return PHP_INT_MAX;
  }
}
