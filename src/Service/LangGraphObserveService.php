<?php

namespace Drupal\drupal_langgraph\Service;

use Symfony\Component\Yaml\Yaml;

final class LangGraphObserveService {

  private ?array $ticks = NULL;
  private ?array $featureProgress = NULL;
  private ?array $llmUsage = NULL;
  private ?array $routingConfig = NULL;

  public function __construct(
    private readonly HqPathManager $paths,
  ) {}

  public function overviewSummary(): array {
    $latest_tick = $this->latestTick();
    $metrics = $this->metricSummary();
    $incidents = $this->incidentRows();
    $feature_progress = $this->langGraphFeatureProgress();

    return [
      ['Latest tick', (string) ($latest_tick['ts'] ?? 'unavailable')],
      ['Tick count loaded', (string) count($this->ticks())],
      ['Selected agents', (string) ($metrics['selected_agents'] ?? 0)],
      ['Queued agents', (string) ($metrics['queued_agents'] ?? 0)],
      ['Workers', (string) ($metrics['workers'] ?? 0)],
      ['Incidents (24h)', (string) count($incidents)],
      ['LangGraph features tracked', (string) count($feature_progress['rows'])],
      ['Next backlog-ready status count', (string) ($feature_progress['summary']['ready'] ?? 0)],
    ];
  }

  public function nodeTraceRows(): array {
    $latest_tick = $this->latestTick();
    $timestamp = (string) ($latest_tick['ts'] ?? '');
    $rows = [];

    foreach ($this->stepResults($latest_tick) as $step => $detail) {
      $rows[] = [
        'step' => $step,
        'timestamp' => $timestamp,
        'status' => $this->stepStatus($detail),
        'summary' => $this->detailSummary($detail),
        'details' => json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
      ];
    }

    return $rows;
  }

  public function metricSummary(): array {
    $ticks = $this->ticks();
    $latest_tick = $this->latestTick();
    $latest_steps = $this->stepResults($latest_tick);
    $previous_tick = count($ticks) > 1 ? $ticks[count($ticks) - 2] : [];
    $previous_ts = $this->timestampToEpoch((string) ($previous_tick['ts'] ?? ''));
    $latest_ts = $this->timestampToEpoch((string) ($latest_tick['ts'] ?? ''));
    $current_gap = ($previous_ts !== NULL && $latest_ts !== NULL) ? max(0, $latest_ts - $previous_ts) : NULL;
    $pick_agents = is_array($latest_steps['pick_agents'] ?? NULL) ? $latest_steps['pick_agents'] : [];
    $exec_agents = is_array($latest_steps['exec_agents'] ?? NULL) ? $latest_steps['exec_agents'] : [];
    $health = is_array($latest_steps['health_check'] ?? NULL) ? $latest_steps['health_check'] : [];

    return [
      'ts' => (string) ($latest_tick['ts'] ?? ''),
      'provider' => (string) ($latest_tick['provider'] ?? 'unknown'),
      'selected_agents' => count((array) ($latest_tick['selected_agents'] ?? [])),
      'queued_agents' => (int) ($pick_agents['queued_agents'] ?? 0),
      'workers' => (int) ($exec_agents['workers'] ?? 0),
      'blocked_count' => (int) ($health['blocked_count'] ?? 0),
      'idle_with_inbox' => (int) ($health['idle_with_inbox'] ?? 0),
      'error_count' => $this->countTickErrors($latest_tick),
      'current_gap_seconds' => $current_gap,
    ];
  }

  public function metricTrendRows(int $limit = 10): array {
    $ticks = array_slice($this->ticks(), -1 * $limit);
    $rows = [];
    $previous_epoch = NULL;

    foreach ($ticks as $tick) {
      $ts = (string) ($tick['ts'] ?? '');
      $epoch = $this->timestampToEpoch($ts);
      $steps = $this->stepResults($tick);
      $pick_agents = is_array($steps['pick_agents'] ?? NULL) ? $steps['pick_agents'] : [];
      $exec_agents = is_array($steps['exec_agents'] ?? NULL) ? $steps['exec_agents'] : [];
      $gap = ($previous_epoch !== NULL && $epoch !== NULL) ? max(0, $epoch - $previous_epoch) : NULL;

      $rows[] = [
        'timestamp' => $ts,
        'gap_seconds' => $gap,
        'selected_agents' => count((array) ($tick['selected_agents'] ?? [])),
        'queued_agents' => (int) ($pick_agents['queued_agents'] ?? 0),
        'workers' => (int) ($exec_agents['workers'] ?? 0),
        'error_count' => $this->countTickErrors($tick),
      ];

      $previous_epoch = $epoch;
    }

    return $rows;
  }

  public function metricAnomalies(int $limit = 10): array {
    $rows = $this->metricTrendRows($limit);
    $intervals = array_values(array_filter(array_map(
      static fn(array $row): ?float => isset($row['gap_seconds']) ? (float) $row['gap_seconds'] : NULL,
      $rows
    ), static fn(?float $value): bool => $value !== NULL));
    $errors = array_map(static fn(array $row): float => (float) $row['error_count'], $rows);
    $messages = [];

    if (count($intervals) >= 3) {
      $current = end($intervals);
      $mean = $this->mean($intervals);
      $stdev = $this->stdev($intervals, $mean);
      if ($current !== FALSE && $current > ($mean + (2 * $stdev))) {
        $messages[] = sprintf(
          'Tick cadence anomaly: latest gap %.0fs exceeds recent average %.1fs by more than 2σ.',
          $current,
          $mean
        );
      }
    }

    if (count($errors) >= 3) {
      $current = end($errors);
      $mean = $this->mean($errors);
      $stdev = $this->stdev($errors, $mean);
      if ($current !== FALSE && $current > ($mean + (2 * $stdev))) {
        $messages[] = sprintf(
          'Error anomaly: latest tick reported %.0f errors versus recent average %.1f.',
          $current,
          $mean
        );
      }
    }

    return $messages;
  }

  public function driftRows(): array {
    $ticks = $this->ticks();
    $recent_ticks = array_slice($ticks, -5);
    $total_ticks = max(1, count($ticks));
    $baseline = [];
    $recent = [];
    $latest_steps = $this->stepResults($this->latestTick());

    foreach ($ticks as $tick) {
      foreach ($this->stepResults($tick) as $step => $detail) {
        $baseline[$step]['seen'] = ($baseline[$step]['seen'] ?? 0) + 1;
        $baseline[$step]['errors'] = ($baseline[$step]['errors'] ?? 0) + ($this->stepHasError($detail) ? 1 : 0);
      }
    }

    foreach ($recent_ticks as $tick) {
      foreach ($this->stepResults($tick) as $step => $detail) {
        $recent[$step]['seen'] = ($recent[$step]['seen'] ?? 0) + 1;
        $recent[$step]['errors'] = ($recent[$step]['errors'] ?? 0) + ($this->stepHasError($detail) ? 1 : 0);
      }
    }

    $steps = array_unique(array_merge(array_keys($baseline), array_keys($recent), array_keys($latest_steps)));
    sort($steps);
    $rows = [];
    foreach ($steps as $step) {
      $baseline_seen = (int) ($baseline[$step]['seen'] ?? 0);
      $recent_seen = (int) ($recent[$step]['seen'] ?? 0);
      $baseline_error_rate = $baseline_seen > 0 ? round(((int) ($baseline[$step]['errors'] ?? 0) / $baseline_seen) * 100, 1) : 0.0;
      $recent_error_rate = $recent_seen > 0 ? round(((int) ($recent[$step]['errors'] ?? 0) / $recent_seen) * 100, 1) : 0.0;
      $presence_rate = round(($baseline_seen / $total_ticks) * 100, 1);
      $latest_status = isset($latest_steps[$step]) ? $this->stepStatus($latest_steps[$step]) : 'missing';
      $delta = round($recent_error_rate - $baseline_error_rate, 1);

      if (abs($delta) < 20 && $latest_status !== 'error' && $latest_status !== 'missing') {
        continue;
      }

      $rows[] = [
        'step' => $step,
        'baseline_presence_pct' => $presence_rate,
        'baseline_error_pct' => $baseline_error_rate,
        'recent_error_pct' => $recent_error_rate,
        'delta_pct' => $delta,
        'latest_status' => $latest_status,
      ];
    }

    usort($rows, static function (array $a, array $b): int {
      if ($a['latest_status'] !== $b['latest_status']) {
        return strcmp((string) $a['latest_status'], (string) $b['latest_status']);
      }
      return abs((float) $b['delta_pct']) <=> abs((float) $a['delta_pct']);
    });

    return $rows;
  }

  public function incidentRows(int $limit = 50): array {
    $rows = array_merge(
      $this->executorFailureIncidents(),
      $this->blockedInboxIncidents(),
      $this->timeoutIncidents()
    );

    usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
    return array_slice($rows, 0, $limit);
  }

  public function langGraphFeatureProgress(): array {
    if ($this->featureProgress !== NULL) {
      return $this->featureProgress;
    }

    $path = $this->paths->artifactPaths()['feature_progress'];
    $raw = $this->readTextFile($path);
    if ($raw === '') {
      $this->featureProgress = ['generated_at' => '', 'rows' => [], 'summary' => []];
      return $this->featureProgress;
    }

    $generated_at = '';
    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $line) {
      if (preg_match('/^Generated:\s*(.+)$/', trim((string) $line), $matches)) {
        $generated_at = trim((string) $matches[1]);
        break;
      }
    }

    $rows = [];
    for ($i = 0; $i < count($lines); $i++) {
      $line = trim((string) $lines[$i]);
      $next = trim((string) ($lines[$i + 1] ?? ''));
      if (!str_starts_with($line, '|') || !str_starts_with($next, '|') || !preg_match('/^\|\s*-+/', $next)) {
        continue;
      }

      $headers = $this->parseMarkdownTableRow($line);
      for ($j = $i + 2; $j < count($lines); $j++) {
        $row_line = trim((string) $lines[$j]);
        if ($row_line === '' || !str_starts_with($row_line, '|')) {
          break;
        }
        $values = $this->parseMarkdownTableRow($row_line);
        if (count($values) !== count($headers)) {
          continue;
        }
        $row = array_combine($headers, $values) ?: [];
        $work_item = (string) ($row['Work item'] ?? '');
        $module = (string) ($row['Module'] ?? '');
        if (
          str_starts_with($work_item, 'forseti-langgraph')
          || $work_item === 'forseti-copilot-agent-tracker'
          || $module === 'drupal_langgraph'
          || $module === 'copilot_agent_tracker'
        ) {
          $rows[] = $row;
        }
      }
      break;
    }

    $summary = [];
    foreach ($rows as $row) {
      $status = trim((string) ($row['Status'] ?? 'unknown'));
      $summary[$status] = ($summary[$status] ?? 0) + 1;
    }
    ksort($summary);

    $this->featureProgress = [
      'generated_at' => $generated_at,
      'rows' => $rows,
      'summary' => $summary,
    ];
    return $this->featureProgress;
  }

  public function featureProgressSummaryRows(): array {
    $data = $this->langGraphFeatureProgress();
    $rows = [];
    foreach ($data['summary'] as $status => $count) {
      $rows[] = [$status !== '' ? $status : 'unknown', (string) $count];
    }
    return $rows;
  }

  public function featureProgressRows(): array {
    $rows = [];
    foreach ($this->langGraphFeatureProgress()['rows'] as $row) {
      $rows[] = [
        (string) ($row['Work item'] ?? ''),
        (string) ($row['Module'] ?? ''),
        (string) ($row['Status'] ?? ''),
        (string) ($row['Priority'] ?? ''),
      ];
    }
    return $rows;
  }

  public function integrationOverviewSummary(): array {
    $usage = $this->llmUsage();
    $configured_backends = $this->configuredBackends();
    $observed_backends = $this->observedBackends();
    $latest = $usage[0] ?? [];
    $latest_timestamp = (string) ($latest['ts'] ?? 'unavailable');
    $recent_failures = count(array_filter($usage, fn(array $row): bool => !$this->usageSuccess($row) && $this->withinHours((string) ($row['ts'] ?? ''), 24)));
    $recent_rate_limits = count(array_filter($usage, static fn(array $row): bool => !empty($row['rate_limited'])));
    $token_totals = $this->usageTokenTotals($usage);

    return [
      ['Configured backends', $configured_backends !== [] ? implode(', ', $configured_backends) : 'none'],
      ['Observed backends', $observed_backends !== [] ? implode(', ', $observed_backends) : 'none'],
      ['Recent provider calls loaded', (string) count($usage)],
      ['Latest provider call', $latest_timestamp],
      ['Input tokens loaded', (string) $token_totals['input']],
      ['Output tokens loaded', (string) $token_totals['output']],
      ['Calls with exact token counts', (string) $token_totals['exact_calls']],
      ['Calls with estimated token counts', (string) $token_totals['estimated_calls']],
      ['Failed calls (24h)', (string) $recent_failures],
      ['Rate-limited calls seen', (string) $recent_rate_limits],
    ];
  }

  public function integrationRoutingRows(): array {
    $routing = $this->routingConfig();
    $rows = [];

    if (($routing['default'] ?? '') !== '') {
      $rows[] = ['default', 'org-wide fallback', (string) $routing['default']];
    }

    $roles = is_array($routing['roles'] ?? NULL) ? $routing['roles'] : [];
    ksort($roles);
    foreach ($roles as $role => $backend) {
      $rows[] = ['role', (string) $role, (string) $backend];
    }

    $agents = is_array($routing['agents'] ?? NULL) ? $routing['agents'] : [];
    ksort($agents);
    foreach ($agents as $agent_id => $backend) {
      $rows[] = ['agent', (string) $agent_id, (string) $backend];
    }

    return $rows;
  }

  public function integrationCoverageRows(): array {
    $usage = $this->llmUsage();
    $configured_backends = $this->configuredBackends();
    $observed_backends = $this->observedBackends();
    $backends = array_values(array_unique(array_merge($configured_backends, $observed_backends, ['copilot', 'bedrock', 'local'])));
    sort($backends);

    $rows = [];
    foreach ($backends as $backend) {
      $records = array_values(array_filter($usage, static fn(array $row): bool => (string) ($row['backend'] ?? '') === $backend));
      $latest = $records[0] ?? [];
      $rows[] = [
        $backend,
        in_array($backend, $configured_backends, TRUE) ? 'yes' : 'no',
        in_array($backend, $observed_backends, TRUE) ? 'yes' : 'no',
        $this->backendTokenVisibility($records),
        $latest !== [] ? (string) ($latest['status'] ?? '-') : '-',
        $latest !== [] ? (string) ($latest['ts'] ?? '-') : '-',
      ];
    }

    return $rows;
  }

  public function integrationTokenSummaryRows(): array {
    $usage = $this->llmUsage();
    $totals = $this->usageTokenTotals($usage);
    $highest = $this->highestTokenCall($usage);

    return [
      ['Total input tokens', (string) $totals['input']],
      ['Total output tokens', (string) $totals['output']],
      ['Total combined tokens', (string) ($totals['input'] + $totals['output'])],
      ['Calls with token totals', (string) $totals['calls_with_tokens']],
      ['Calls missing token totals', (string) $totals['calls_without_tokens']],
      ['Highest-token call', $highest],
    ];
  }

  public function integrationUtilizationRows(): array {
    $groups = [];
    foreach ($this->llmUsage() as $row) {
      $backend = trim((string) ($row['backend'] ?? ''));
      $model = trim((string) ($row['model_id'] ?? ''));
      $key = ($backend !== '' ? $backend : 'unknown') . '|' . ($model !== '' ? $model : '-');
      if (!isset($groups[$key])) {
        $groups[$key] = [
          'backend' => $backend !== '' ? $backend : 'unknown',
          'model' => $model !== '' ? $model : '-',
          'calls' => 0,
          'input' => 0,
          'output' => 0,
          'exact_calls' => 0,
          'estimated_calls' => 0,
          'calls_with_tokens' => 0,
          'last_seen' => '',
          'latest_status' => '-',
        ];
      }

      $groups[$key]['calls']++;
      $groups[$key]['input'] += $this->tokenCountForDirection($row, 'input') ?? 0;
      $groups[$key]['output'] += $this->tokenCountForDirection($row, 'output') ?? 0;
      if ($this->rowHasTokenTotals($row)) {
        $groups[$key]['calls_with_tokens']++;
      }
      if ($this->rowHasExactTokens($row)) {
        $groups[$key]['exact_calls']++;
      }
      elseif ($this->rowHasEstimatedTokens($row)) {
        $groups[$key]['estimated_calls']++;
      }

      $timestamp = (string) ($row['ts'] ?? '');
      if ($timestamp !== '' && $timestamp > $groups[$key]['last_seen']) {
        $groups[$key]['last_seen'] = $timestamp;
        $groups[$key]['latest_status'] = (string) ($row['status'] ?? '-');
      }
    }

    usort($groups, static function (array $a, array $b): int {
      $a_total = $a['input'] + $a['output'];
      $b_total = $b['input'] + $b['output'];
      if ($a_total !== $b_total) {
        return $b_total <=> $a_total;
      }
      return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
    });

    $rows = [];
    foreach ($groups as $group) {
      $total = $group['input'] + $group['output'];
      $avg = $group['calls_with_tokens'] > 0 ? (string) round($total / $group['calls_with_tokens'], 1) : '-';
      $rows[] = [
        $group['backend'],
        $group['model'],
        (string) $group['calls'],
        sprintf('exact %d / est %d', $group['exact_calls'], $group['estimated_calls']),
        (string) $group['input'],
        (string) $group['output'],
        (string) $total,
        $avg,
        $group['latest_status'],
        $group['last_seen'] !== '' ? $group['last_seen'] : '-',
      ];
    }

    return $rows;
  }

  public function integrationRecentCallRows(int $limit = 25): array {
    $rows = [];
    foreach (array_slice($this->llmUsage(), 0, $limit) as $row) {
      $rows[] = [
        (string) ($row['ts'] ?? ''),
        (string) ($row['backend'] ?? ''),
        (string) ($row['agent_id'] ?? ''),
        (string) ($row['model_id'] ?? '-'),
        (string) ($row['status'] ?? ''),
        $this->usageSuccess($row) ? 'yes' : 'no',
        $this->tokenSummary($row),
        isset($row['duration_ms']) ? ((string) $row['duration_ms'] . ' ms') : '-',
        $this->usageErrorSummary($row),
      ];
    }

    return $rows;
  }

  public function integrationGapMessages(): array {
    $messages = [];
    $usage = $this->llmUsage();
    $configured_backends = $this->configuredBackends();
    $observed_backends = $this->observedBackends();

    if ($usage === []) {
      $messages[] = 'No provider usage records are available yet. If agents have been running, verify inbox/responses/langgraph-llm-usage.jsonl is being written by the executor.';
      return $messages;
    }

    foreach ($configured_backends as $backend) {
      if (!in_array($backend, $observed_backends, TRUE)) {
        $messages[] = sprintf('Configured backend "%s" has no recorded LangGraph usage yet.', $backend);
      }
    }

    foreach (['copilot', 'bedrock', 'local'] as $backend) {
      $records = array_values(array_filter($usage, static fn(array $row): bool => (string) ($row['backend'] ?? '') === $backend));
      if ($records === []) {
        continue;
      }
      if ($backend === 'copilot' && $this->backendTokenVisibility($records) === 'unavailable') {
        $messages[] = 'Copilot calls are visible and estimated token totals are shown, but the current Copilot CLI runtime surface does not expose exact provider token counts.';
      }
    }

    if (count(array_filter($usage, static fn(array $row): bool => !empty($row['rate_limited']))) > 0) {
      $messages[] = 'Recent usage records include at least one rate-limited Copilot call.';
    }

    if (count(array_filter($usage, fn(array $row): bool => $this->rowHasTokenTotals($row))) === 0) {
      $messages[] = 'Provider usage records are present, but none currently expose usable token totals. Verify the executor is writing prompt/response token estimates into langgraph-llm-usage.jsonl.';
    }

    return $messages;
  }

  private function ticks(): array {
    if ($this->ticks !== NULL) {
      return $this->ticks;
    }

    $path = $this->paths->artifactPaths()['ticks'];
    if (!is_readable($path)) {
      $this->ticks = [];
      return $this->ticks;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows = [];
    foreach ($lines as $line) {
      $decoded = json_decode(trim((string) $line), TRUE);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }

    $this->ticks = $rows;
    return $this->ticks;
  }

  private function latestTick(): array {
    $ticks = $this->ticks();
    if ($ticks === []) {
      return [];
    }

    $latest = end($ticks);
    return is_array($latest) ? $latest : [];
  }

  private function llmUsage(): array {
    if ($this->llmUsage !== NULL) {
      return $this->llmUsage;
    }

    $path = $this->paths->artifactPaths()['llm_usage'] ?? '';
    if ($path === '' || !is_readable($path)) {
      $this->llmUsage = [];
      return $this->llmUsage;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows = [];
    foreach ($lines as $line) {
      $decoded = json_decode(trim((string) $line), TRUE);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }

    usort($rows, fn(array $a, array $b): int => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));
    $this->llmUsage = $rows;
    return $this->llmUsage;
  }

  private function routingConfig(): array {
    if ($this->routingConfig !== NULL) {
      return $this->routingConfig;
    }

    $path = $this->paths->artifactPaths()['llm_routing'] ?? '';
    if ($path === '' || !is_readable($path)) {
      $this->routingConfig = [];
      return $this->routingConfig;
    }

    try {
      $decoded = Yaml::parseFile($path);
      $this->routingConfig = is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      $this->routingConfig = [];
    }

    return $this->routingConfig;
  }

  private function configuredBackends(): array {
    $routing = $this->routingConfig();
    $values = [];

    foreach ([$routing['default'] ?? NULL] as $value) {
      if (is_string($value) && $value !== '') {
        $values[] = $value;
      }
    }
    foreach (['roles', 'agents'] as $section) {
      foreach ((array) ($routing[$section] ?? []) as $value) {
        if (is_string($value) && $value !== '') {
          $values[] = $value;
        }
      }
    }

    $values = array_values(array_unique($values));
    sort($values);
    return $values;
  }

  private function observedBackends(): array {
    $backends = [];
    foreach ($this->llmUsage() as $row) {
      $backend = trim((string) ($row['backend'] ?? ''));
      if ($backend !== '') {
        $backends[] = $backend;
      }
    }
    $backends = array_values(array_unique($backends));
    sort($backends);
    return $backends;
  }

  private function backendTokenVisibility(array $records): string {
    if ($records === []) {
      return 'unobserved';
    }

    if (count(array_filter($records, fn(array $row): bool => $this->rowHasExactTokens($row))) > 0) {
      return 'exact';
    }
    if (count(array_filter($records, fn(array $row): bool => $this->rowHasEstimatedTokens($row))) > 0) {
      return 'estimated';
    }
    $values = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['token_visibility'] ?? ''), $records)));
    if (in_array('unavailable', $values, TRUE)) {
      return 'unavailable';
    }
    return 'unknown';
  }

  private function tokenSummary(array $row): string {
    $input = $this->tokenCountForDirection($row, 'input');
    $output = $this->tokenCountForDirection($row, 'output');

    if ($input !== NULL || $output !== NULL) {
      $label = $this->rowHasExactTokens($row) ? 'exact' : 'est';
      return sprintf('%s %s/%s', $label, $input ?? '-', $output ?? '-');
    }
    if ((string) ($row['token_visibility'] ?? '') === 'unavailable') {
      return 'provider exact unavailable';
    }
    return '-';
  }

  private function usageTokenTotals(array $usage): array {
    $totals = [
      'input' => 0,
      'output' => 0,
      'exact_calls' => 0,
      'estimated_calls' => 0,
      'calls_with_tokens' => 0,
      'calls_without_tokens' => 0,
    ];

    foreach ($usage as $row) {
      $input = $this->tokenCountForDirection($row, 'input');
      $output = $this->tokenCountForDirection($row, 'output');
      if ($input !== NULL || $output !== NULL) {
        $totals['calls_with_tokens']++;
        $totals['input'] += $input ?? 0;
        $totals['output'] += $output ?? 0;
      }
      else {
        $totals['calls_without_tokens']++;
      }

      if ($this->rowHasExactTokens($row)) {
        $totals['exact_calls']++;
      }
      elseif ($this->rowHasEstimatedTokens($row)) {
        $totals['estimated_calls']++;
      }
    }

    return $totals;
  }

  private function highestTokenCall(array $usage): string {
    $highest_row = NULL;
    $highest_total = -1;

    foreach ($usage as $row) {
      $total = $this->rowTotalTokens($row);
      if ($total !== NULL && $total > $highest_total) {
        $highest_total = $total;
        $highest_row = $row;
      }
    }

    if ($highest_row === NULL) {
      return 'none';
    }

    return sprintf(
      '%s / %s / %s tokens',
      (string) ($highest_row['backend'] ?? 'unknown'),
      (string) ($highest_row['model_id'] ?? '-'),
      (string) $highest_total
    );
  }

  private function rowTotalTokens(array $row): ?int {
    $input = $this->tokenCountForDirection($row, 'input');
    $output = $this->tokenCountForDirection($row, 'output');
    if ($input === NULL && $output === NULL) {
      return NULL;
    }
    return ($input ?? 0) + ($output ?? 0);
  }

  private function rowHasTokenTotals(array $row): bool {
    return $this->rowTotalTokens($row) !== NULL;
  }

  private function rowHasExactTokens(array $row): bool {
    return $this->normalizeTokenCount($row['exact_input_tokens'] ?? NULL) !== NULL
      || $this->normalizeTokenCount($row['exact_output_tokens'] ?? NULL) !== NULL;
  }

  private function rowHasEstimatedTokens(array $row): bool {
    return !$this->rowHasExactTokens($row) && (
      $this->normalizeTokenCount($row['prompt_tokens_est'] ?? NULL) !== NULL
      || $this->normalizeTokenCount($row['response_tokens_est'] ?? NULL) !== NULL
    );
  }

  private function tokenCountForDirection(array $row, string $direction): ?int {
    $exact_key = $direction === 'input' ? 'exact_input_tokens' : 'exact_output_tokens';
    $estimated_key = $direction === 'input' ? 'prompt_tokens_est' : 'response_tokens_est';
    $exact = $this->normalizeTokenCount($row[$exact_key] ?? NULL);
    if ($exact !== NULL) {
      return $exact;
    }
    return $this->normalizeTokenCount($row[$estimated_key] ?? NULL);
  }

  private function normalizeTokenCount(mixed $value): ?int {
    if (is_int($value)) {
      return $value >= 0 ? $value : NULL;
    }
    if (is_numeric($value)) {
      $normalized = (int) $value;
      return $normalized >= 0 ? $normalized : NULL;
    }
    return NULL;
  }

  private function usageSuccess(array $row): bool {
    $value = $row['success'] ?? FALSE;
    if (is_bool($value)) {
      return $value;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes'], TRUE);
  }

  private function usageErrorSummary(array $row): string {
    $error = trim((string) ($row['error'] ?? ''));
    if ($error !== '') {
      return mb_strimwidth($error, 0, 100, '...');
    }
    return '-';
  }

  private function withinHours(string $timestamp, int $hours): bool {
    $epoch = $this->timestampToEpoch($timestamp);
    if ($epoch === NULL) {
      return FALSE;
    }
    return $epoch >= (time() - ($hours * 3600));
  }

  private function stepResults(array $tick): array {
    $steps = is_array($tick['step_results'] ?? NULL) ? $tick['step_results'] : [];
    unset($steps['summarize_tick']);
    return $steps;
  }

  private function stepStatus(array $detail): string {
    if ($this->stepHasError($detail)) {
      return 'error';
    }
    if (!empty($detail['skipped'])) {
      return 'skipped';
    }
    return 'ok';
  }

  private function stepHasError(array $detail): bool {
    if (isset($detail['error']) || !empty($detail['errors'])) {
      return TRUE;
    }
    return isset($detail['rc']) && (int) $detail['rc'] !== 0;
  }

  private function detailSummary(array $detail, int $limit = 120): string {
    $parts = [];
    foreach ($detail as $key => $value) {
      if (is_scalar($value) || $value === NULL) {
        $parts[] = $key . '=' . (string) $value;
      }
      elseif (is_array($value)) {
        $parts[] = $key . '[' . count($value) . ']';
      }
      if (strlen(implode('; ', $parts)) >= $limit) {
        break;
      }
    }

    $summary = $parts !== [] ? implode('; ', $parts) : 'No detail fields';
    return mb_strimwidth($summary, 0, $limit, '...');
  }

  private function countTickErrors(array $tick): int {
    $count = count((array) ($tick['errors'] ?? []));
    foreach ($this->stepResults($tick) as $detail) {
      if (is_array($detail) && $this->stepHasError($detail)) {
        $count++;
      }
    }
    return $count;
  }

  private function executorFailureIncidents(): array {
    $dir = $this->paths->resolveForseti('tmp/executor-failures');
    if (!is_dir($dir)) {
      return [];
    }

    $rows = [];
    foreach (glob($dir . '/*') ?: [] as $path) {
      if (!is_file($path)) {
        continue;
      }
      $text = $this->readTextFile($path);
      $rows[] = [
        'timestamp' => $this->extractLineValue($text, 'Failed at') ?: gmdate('c', (int) filemtime($path)),
        'severity' => 'error',
        'category' => 'executor-failure',
        'seat' => $this->extractLineValue($text, 'Agent'),
        'summary' => $this->extractLineValue($text, 'Failure reason') ?: 'Executor failure',
        'path' => $path,
      ];
    }

    return $rows;
  }

  private function blockedInboxIncidents(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $rows = [];
    foreach (glob($sessions_dir . '/*/inbox/*/command.md') ?: [] as $path) {
      $text = $this->readTextFile($path);
      if (!preg_match('/^-?\s*Status:\s*(.+)$/mi', $text, $matches)) {
        continue;
      }
      $status = strtolower(trim((string) $matches[1]));
      if (!in_array($status, ['blocked', 'needs-info'], TRUE)) {
        continue;
      }

      $item_dir = dirname($path);
      $rows[] = [
        'timestamp' => gmdate('c', (int) filemtime($item_dir)),
        'severity' => $status === 'blocked' ? 'warn' : 'info',
        'category' => $status,
        'seat' => basename(dirname(dirname($item_dir))),
        'summary' => basename($item_dir),
        'path' => $item_dir,
      ];
    }

    return $rows;
  }

  private function timeoutIncidents(): array {
    $path = $this->paths->artifactPaths()['orchestrator_log'];
    if (!is_readable($path)) {
      return [];
    }

    $lines = preg_split('/\R/', $this->readTextFile($path)) ?: [];
    $rows = [];
    foreach ($lines as $line) {
      if (!preg_match('/timeout|timed out/i', $line)) {
        continue;
      }
      $rows[] = [
        'timestamp' => gmdate('c', (int) filemtime($path)),
        'severity' => 'warn',
        'category' => 'tick-timeout',
        'seat' => '-',
        'summary' => mb_strimwidth(trim($line), 0, 160, '...'),
        'path' => $path,
      ];
    }

    return $rows;
  }

  private function extractLineValue(string $text, string $label): string {
    if (preg_match('/^-\s*' . preg_quote($label, '/') . ':\s*(.+)$/mi', $text, $matches)) {
      return trim((string) $matches[1]);
    }
    return '';
  }

  private function parseMarkdownTableRow(string $line): array {
    $parts = explode('|', trim($line, '|'));
    return array_map(static fn(string $part): string => trim($part), $parts);
  }

  private function readTextFile(string $path): string {
    if ($path === '' || !is_readable($path)) {
      return '';
    }
    $raw = @file_get_contents($path);
    return $raw === FALSE ? '' : trim((string) $raw);
  }

  private function timestampToEpoch(string $timestamp): ?int {
    if ($timestamp === '') {
      return NULL;
    }
    $value = strtotime($timestamp);
    return $value === FALSE ? NULL : $value;
  }

  private function mean(array $values): float {
    if ($values === []) {
      return 0.0;
    }
    return array_sum($values) / count($values);
  }

  private function stdev(array $values, ?float $mean = NULL): float {
    if (count($values) < 2) {
      return 0.0;
    }
    $mean ??= $this->mean($values);
    $sum = 0.0;
    foreach ($values as $value) {
      $sum += ($value - $mean) ** 2;
    }
    return sqrt($sum / count($values));
  }

}
