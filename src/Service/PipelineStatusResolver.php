<?php

namespace Drupal\drupal_langgraph\Service;

final class PipelineStatusResolver {

  private const PIPELINE_TO_ROADMAP = [
    'shipped' => 'implemented',
    'done' => 'implemented',
    'in_progress' => 'in_progress',
    'ready' => 'queued',
    'backlog' => 'pending',
    'pending' => 'pending',
    'planned' => 'pending',
    'pre-triage' => 'pending',
    'deferred' => 'pending',
  ];

  private const PREFIX_TO_PROJECT = [
    'forseti-langgraph-' => 'PROJ-001',
    'forseti-copilot-agent-tracker' => 'PROJ-001',
    'forseti-agent-tracker-' => 'PROJ-001',
    'forseti-qa-' => 'PROJ-002',
    'forseti-jobhunter-' => 'PROJ-004',
    'forseti-ai-' => 'PROJ-005',
    'dc-' => 'PROJ-003',
  ];

  private ?array $cache = NULL;

  public function __construct(
    private readonly HqPathManager $paths,
  ) {}

  public function getProjectCounts(string $project_id): array {
    $all = $this->getAllProjectCounts();
    return $all[strtoupper(trim($project_id))] ?? $this->emptyCounts();
  }

  public function getProjectFeatureGroups(string $project_id): array {
    $project_id = strtoupper(trim($project_id));
    $groups = [];
    $feature_files = glob($this->paths->featuresPath() . '/*/feature.md') ?: [];

    foreach ($feature_files as $feature_file) {
      $feature_id = basename(dirname($feature_file));
      $content = @file_get_contents($feature_file);
      if ($content === FALSE || trim($content) === '') {
        continue;
      }

      if ($this->resolveProjectId($feature_id, $content) !== $project_id) {
        continue;
      }

      $pipeline_status = $this->extractField($content, 'Status');
      if ($pipeline_status === '') {
        continue;
      }

      $roadmap_status = self::PIPELINE_TO_ROADMAP[strtolower($pipeline_status)] ?? 'pending';
      $group_key = $this->extractField($content, 'Group') ?: 'ungrouped';
      $group_title = $this->extractField($content, 'Group Title') ?: 'Other';
      $group_order = (int) ($this->extractField($content, 'Group Order') ?: '99');
      $feat_sort = (int) ($this->extractField($content, 'Group Sort') ?: '99');

      if (!isset($groups[$group_key])) {
        $groups[$group_key] = [
          'title' => $group_title,
          'sort' => $group_order,
          'counts' => $this->emptyCounts(),
          'features' => [],
        ];
      }

      $groups[$group_key]['counts'][$roadmap_status]++;
      $groups[$group_key]['counts']['total']++;
      $groups[$group_key]['features'][] = [
        'feature_id' => $feature_id,
        'title' => $this->extractFeatureTitle($content, $feature_id),
        'scope' => $this->extractScope($content),
        'status' => $roadmap_status,
        'pipeline_status' => strtolower($pipeline_status),
        'status_label' => ucwords(str_replace('_', ' ', strtolower($pipeline_status))),
        'sort' => $feat_sort,
      ];
    }

    foreach ($groups as &$group) {
      usort($group['features'], fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
      $total = $group['counts']['total'];
      $group['counts']['impl_pct'] = $total > 0 ? (int) round($group['counts']['implemented'] * 100 / $total) : 0;
      $group['counts']['progress_pct'] = $total > 0 ? (int) round(($group['counts']['implemented'] + $group['counts']['in_progress']) * 100 / $total) : 0;
    }
    unset($group);

    uasort($groups, fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    return $groups;
  }

  public function getAllProjectCounts(): array {
    if ($this->cache !== NULL) {
      return $this->cache;
    }

    $this->cache = [];
    $feature_files = glob($this->paths->featuresPath() . '/*/feature.md') ?: [];

    foreach ($feature_files as $feature_file) {
      $feature_id = basename(dirname($feature_file));
      $content = @file_get_contents($feature_file);
      if ($content === FALSE || trim($content) === '') {
        continue;
      }

      $project = $this->resolveProjectId($feature_id, $content);
      $pipeline_status = strtolower($this->extractField($content, 'Status'));
      if ($project === '' || $pipeline_status === '') {
        continue;
      }

      $roadmap_status = self::PIPELINE_TO_ROADMAP[$pipeline_status] ?? 'pending';
      if (!isset($this->cache[$project])) {
        $this->cache[$project] = $this->emptyCounts();
      }

      $this->cache[$project][$roadmap_status]++;
      $this->cache[$project]['total']++;
    }

    foreach ($this->cache as &$counts) {
      $total = $counts['total'];
      $counts['impl_pct'] = $total > 0 ? (int) round($counts['implemented'] * 100 / $total) : 0;
      $counts['progress_pct'] = $total > 0 ? (int) round(($counts['implemented'] + $counts['in_progress']) * 100 / $total) : 0;
    }
    unset($counts);

    return $this->cache;
  }

  private function resolveProjectId(string $feature_id, string $content): string {
    $explicit = strtoupper(trim($this->extractField($content, 'Project')));
    if ($explicit !== '') {
      return $explicit;
    }

    foreach (self::PREFIX_TO_PROJECT as $prefix => $project) {
      if ($feature_id === $prefix || str_starts_with($feature_id, $prefix)) {
        return $project;
      }
    }

    return '';
  }

  private function extractField(string $content, string $field): string {
    if (preg_match('/^- ' . preg_quote($field, '/') . ':\s*(.+)$/mi', $content, $matches)) {
      return trim($matches[1]);
    }
    return '';
  }

  private function extractFeatureTitle(string $content, string $fallback): string {
    if (preg_match('/^##\s+(.+)$/m', $content, $matches) || preg_match('/^#\s+(.+)$/m', $content, $matches)) {
      return trim($matches[1]);
    }
    return $fallback;
  }

  private function extractScope(string $content): string {
    if (preg_match('/^##\s+(Goal|Summary)\s*$([\s\S]*?)(?:^## |\z)/mi', $content, $matches)) {
      foreach (preg_split('/\R\R+/', trim($matches[2])) as $paragraph) {
        $paragraph = trim(strip_tags($paragraph));
        if ($paragraph !== '') {
          return preg_replace('/\s+/', ' ', $paragraph) ?? $paragraph;
        }
      }
    }
    return '';
  }

  private function emptyCounts(): array {
    return [
      'implemented' => 0,
      'in_progress' => 0,
      'queued' => 0,
      'pending' => 0,
      'total' => 0,
      'impl_pct' => 0,
      'progress_pct' => 0,
    ];
  }
}
