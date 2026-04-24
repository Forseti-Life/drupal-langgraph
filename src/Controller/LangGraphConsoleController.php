<?php

namespace Drupal\drupal_langgraph\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_langgraph\Service\HqPathManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LangGraphConsoleController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly HqPathManager $paths,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('drupal_langgraph.path_manager'));
  }

  public function adminAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer drupal langgraph')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer copilot agent tracker'));
  }

  public function home(): array {
    $latest_tick = $this->readLatestTick();
    $parity = $this->readParity();
    $release_control = $this->readReleaseControl();

    $build = $this->buildPage('Drupal LangGraph Console', 'Control-plane frame for the consolidated roadmap and LangGraph management surface.', $this->buildSectionRows('home'));
    $build['live_status'] = $this->tableDetails('Live Runtime Status', ['Signal', 'Current Value', 'Source'], [
      ['Latest tick timestamp', (string) ($latest_tick['ts'] ?? 'unavailable'), 'copilot-hq/inbox/responses/langgraph-ticks.jsonl'],
      ['Latest tick age', $this->formatAgeFromTimestamp((string) ($latest_tick['ts'] ?? '')), 'derived from tick timestamp'],
      ['dry_run', !empty($latest_tick['dry_run']) ? 'yes' : 'no', 'copilot-hq/inbox/responses/langgraph-ticks.jsonl'],
      ['publish_enabled', !empty($latest_tick['publish_enabled']) ? 'yes' : 'no', 'copilot-hq/inbox/responses/langgraph-ticks.jsonl'],
      ['provider', (string) ($latest_tick['provider'] ?? 'unknown'), 'copilot-hq/inbox/responses/langgraph-ticks.jsonl'],
      ['parity_ok', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown', 'copilot-hq/inbox/responses/langgraph-parity-latest.json'],
      ['release_cycle_enabled', isset($release_control['enabled']) ? ((bool) $release_control['enabled'] ? 'yes' : 'no') : 'unknown', 'tmp/release-cycle-control.json'],
    ]);
    return $build;
  }

  public function build(): array {
    $latest_tick = $this->readLatestTick();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    $nodes = array_values(array_filter(array_keys($step_results), static fn($key) => $key !== 'summarize_tick'));
    sort($nodes);
    $rows = $nodes ? array_map(fn(string $node): array => [$node, 'Observed in latest tick'], $nodes) : [['(none)', 'No step_results found in latest tick artifact.']];

    $build = $this->buildPage('Build', 'Design-time view over graph topology and structure evidence.', $this->buildSectionRows('build'));
    $build['graph_shape'] = $this->tableDetails('Observed Graph Shape', ['Node', 'Observation'], $rows, 'copilot-hq/inbox/responses/langgraph-ticks.jsonl');
    return $build;
  }

  public function test(): array {
    $parity = $this->readParity();
    $errors = is_array($parity['errors'] ?? NULL) ? $parity['errors'] : [];
    $rows = [
      ['parity_ok', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown'],
      ['selected_agents.match', isset($parity['selected_agents']['match']) ? ((bool) $parity['selected_agents']['match'] ? 'yes' : 'no') : 'unknown'],
      ['steps.match', isset($parity['steps']['match']) ? ((bool) $parity['steps']['match'] ? 'yes' : 'no') : 'unknown'],
      ['generated_at', (string) ($parity['generated_at'] ?? 'unknown')],
      ['errors', $errors ? implode('; ', array_map('strval', $errors)) : '(none)'],
    ];

    $build = $this->buildPage('Test', 'Validation view over parity and correctness evidence.', $this->buildSectionRows('test'));
    $build['parity_evidence'] = $this->tableDetails('Current Validation Evidence', ['Field', 'Value'], $rows, 'copilot-hq/inbox/responses/langgraph-parity-latest.json');
    return $build;
  }

  public function run(): array {
    $rows = [];
    $ticks = array_slice($this->readTicks(), -25);
    foreach (array_reverse($ticks) as $tick) {
      $rows[] = [
        (string) ($tick['ts'] ?? ''),
        !empty($tick['dry_run']) ? 'yes' : 'no',
        !empty($tick['publish_enabled']) ? 'yes' : 'no',
        (string) ($tick['provider'] ?? ''),
        (string) ($tick['agent_cap'] ?? ''),
        (string) $this->countTickErrors($tick),
      ];
    }

    $build = $this->buildPage('Run', 'Execution-plane timeline for recent LangGraph activity.', $this->buildSectionRows('run'));
    $build['run_timeline'] = $this->tableDetails('Recent Runs Timeline', ['Timestamp', 'dry_run', 'publish_enabled', 'provider', 'agent_cap', 'error_count'], $rows, 'copilot-hq/inbox/responses/langgraph-ticks.jsonl');
    return $build;
  }

  public function observe(): array {
    $latest_tick = $this->readLatestTick();
    $steps = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    $rows = [];
    foreach ($steps as $node => $detail) {
      if ($node === 'summarize_tick' || !is_array($detail)) {
        continue;
      }
      $status = isset($detail['error']) || !empty($detail['errors']) ? 'error' : (isset($detail['skipped']) ? 'skipped' : 'ok');
      $summary = [];
      foreach (['mode', 'rc', 'skipped', 'error'] as $key) {
        if (isset($detail[$key])) {
          $summary[] = $key . '=' . (string) $detail[$key];
        }
      }
      $rows[] = [(string) $node, $status, $summary ? implode('; ', $summary) : 'ok'];
    }

    $build = $this->buildPage('Observe', 'Observability view over node diagnostics and runtime behavior.', $this->buildSectionRows('observe'));
    $build['node_diagnostics'] = $this->tableDetails('Latest Node Diagnostics', ['Node', 'Status', 'Details'], $rows, 'copilot-hq/inbox/responses/langgraph-ticks.jsonl');
    return $build;
  }

  public function release(): array {
    $build = $this->buildPage('Release', 'Release-cycle and promotion posture for the control plane.', $this->buildSectionRows('release'));
    $build['release_state'] = $this->tableDetails('Release Cycle State', ['Team', 'Current Release', 'Next Release', 'Source'], $this->readReleaseCycleRows());
    return $build;
  }

  public function featureProgress(): array {
    $paths = $this->paths->artifactPaths();
    $relative = 'dashboards/FEATURE_PROGRESS.md';
    $raw = $this->readTextFile($paths['feature_progress']);

    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => [
        '#markup' => '<h2>' . $this->t('Feature Progress') . '</h2>',
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Read-only workflow snapshot sourced from the HQ feature progress dashboard.') . '</p>',
      ],
      'source' => [
        '#markup' => '<p><strong>' . $this->t('Source') . ':</strong> ' . $relative . '</p>',
      ],
      'content' => [
        '#type' => 'details',
        '#title' => $this->t('Dashboard content'),
        '#open' => TRUE,
        'preview' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => $raw !== '' ? $raw : $this->t('Feature progress dashboard is not readable at @path.', ['@path' => $relative]),
        ],
      ],
    ];
  }

  public function admin(): array {
    $rows = [];
    foreach ($this->paths->artifactPaths() as $label => $path) {
      $rows[] = [
        $label,
        $this->toRelativePath($path),
        file_exists($path) ? 'yes' : 'no',
        is_readable($path) ? 'yes' : 'no',
        is_file($path) ? ((string) filesize($path) . ' bytes') : '-',
      ];
    }

    $build = $this->buildPage('Admin', 'Path contracts and artifact health for the consolidated HQ module.', $this->buildSectionRows('admin'));
    $build['artifact_health'] = $this->tableDetails('Artifact Health', ['Artifact', 'Path', 'Exists', 'Readable', 'Size'], $rows);
    return $build;
  }

  public function subsection(string $section, string $subsection): array {
    $map = $this->sectionMap();
    if (!isset($map[$section])) {
      throw new NotFoundHttpException();
    }

    $subsections = $map[$section]['subsections'];
    $sub_info = $subsections[$subsection] ?? [ucwords(str_replace('-', ' ', $subsection)), 'Stub placeholder for this subsection.'];

    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@section: @subsection', ['@section' => $map[$section]['title'], '@subsection' => $sub_info[0]]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($sub_info[1]) . '</p>'],
      'notice' => ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Stub Subsection') . ':</strong> ' . $this->t('This subsection frame is ready for future workflow wiring.') . '</div>'],
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to @section', ['@section' => $map[$section]['title']]), Url::fromRoute('drupal_langgraph.langgraph_console_' . $section))->toString() . '</p>'],
    ];
  }

  private function sectionMap(): array {
    return [
      'home' => ['title' => 'Home', 'subsections' => ['runtime-status' => ['Runtime Status', 'High-level runtime health and control posture.']]],
      'build' => ['title' => 'Build', 'subsections' => ['graph-shape' => ['Graph Shape', 'Observed node set and graph evidence from runtime artifacts.']]],
      'test' => ['title' => 'Test', 'subsections' => ['parity-evidence' => ['Parity Evidence', 'Current parity report and validation summary.']]],
      'run' => ['title' => 'Run', 'subsections' => ['recent-runs' => ['Recent Runs', 'Recent run timeline from the tick stream.']]],
      'observe' => ['title' => 'Observe', 'subsections' => ['node-diagnostics' => ['Node Diagnostics', 'Latest node-level diagnostics and anomalies.']]],
      'release' => ['title' => 'Release', 'subsections' => ['release-cycle' => ['Release Cycle', 'Current and next release markers by team.']]],
      'admin' => ['title' => 'Admin', 'subsections' => ['artifacts' => ['Artifacts', 'Filesystem health for the module contract.']]],
    ];
  }

  private function buildSectionRows(string $section): array {
    $rows = [];
    foreach ($this->sectionMap()[$section]['subsections'] as $slug => $info) {
      $rows[] = [
        Link::fromTextAndUrl($this->t($info[0]), Url::fromRoute('drupal_langgraph.langgraph_console_subsection', ['section' => $section, 'subsection' => $slug]))->toString(),
        $info[1],
        $this->t('Ready'),
      ];
    }
    return $rows;
  }

  private function buildPage(string $title, string $description, array $sections): array {
    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t($title) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($description) . '</p>'],
      'sections' => [
        '#type' => 'table',
        '#header' => [$this->t('Subsection'), $this->t('Purpose'), $this->t('Status')],
        '#rows' => $sections,
      ],
    ];
  }

  private function tableDetails(string $title, array $header, array $rows, ?string $source = NULL): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => array_map(fn(string $label) => $this->t($label), $header),
        '#rows' => $rows,
        '#empty' => $this->t('No data available.'),
      ],
    ];

    if ($source !== NULL) {
      $build['source'] = ['#markup' => '<p><strong>' . $this->t('Source') . ':</strong> ' . $source . '</p>'];
    }

    return $build;
  }

  private function readTicks(): array {
    $path = $this->paths->artifactPaths()['ticks'];
    if (!is_readable($path)) {
      return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows = [];
    foreach ($lines as $line) {
      $decoded = json_decode(trim((string) $line), TRUE);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }
    return $rows;
  }

  private function readLatestTick(): array {
    $ticks = $this->readTicks();
    if ($ticks === []) {
      return [];
    }
    $latest = end($ticks);
    return is_array($latest) ? $latest : [];
  }

  private function readParity(): array {
    $path = $this->paths->artifactPaths()['parity'];
    if (!is_readable($path)) {
      return [];
    }
    $raw = (string) @file_get_contents($path);
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  private function readReleaseControl(): array {
    $paths = $this->paths->artifactPaths();
    $default_path = (string) (getenv('RELEASE_CYCLE_CONTROL_FILE') ?: $paths['release_control_default']);
    foreach ([$default_path, $paths['release_control_legacy']] as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $raw = (string) @file_get_contents($path);
      $decoded = json_decode($raw, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }
    return [];
  }

  private function readTextFile(string $path): string {
    if (!is_readable($path)) {
      return '';
    }

    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return '';
    }

    return trim((string) $raw);
  }

  private function readReleaseCycleRows(): array {
    $dir = $this->paths->artifactPaths()['release_cycle_dir'];
    if (!is_dir($dir)) {
      return [];
    }

    $files = glob($dir . '/*.release_id') ?: [];
    sort($files);
    $rows = [];
    foreach ($files as $file) {
      $team = basename((string) $file, '.release_id');
      $current = trim((string) @file_get_contents((string) $file));
      $next_file = $dir . '/' . $team . '.next_release_id';
      $next = is_readable($next_file) ? trim((string) @file_get_contents($next_file)) : '';
      $rows[] = [$team, $current !== '' ? $current : '-', $next !== '' ? $next : '-', 'tmp/release-cycle-active/' . $team . '.release_id'];
    }
    return $rows;
  }

  private function countTickErrors(array $tick): int {
    $count = count((array) ($tick['errors'] ?? []));
    $steps = is_array($tick['step_results'] ?? NULL) ? $tick['step_results'] : [];
    foreach ($steps as $step) {
      if (is_array($step) && (($step['status'] ?? '') === 'error' || !empty($step['errors']) || isset($step['error']))) {
        $count++;
      }
    }
    return $count;
  }

  private function formatAgeFromTimestamp(string $ts): string {
    if ($ts === '') {
      return 'unknown';
    }
    $value = strtotime($ts);
    if ($value === FALSE) {
      return 'unknown';
    }
    return (string) max(0, time() - $value) . 's';
  }

  private function toRelativePath(string $path): string {
    $forseti_root = rtrim($this->paths->forsetiRoot(), '/') . '/';
    if (str_starts_with($path, $forseti_root)) {
      return substr($path, strlen($forseti_root));
    }
    return $path;
  }
}
