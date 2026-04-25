<?php

namespace Drupal\drupal_langgraph\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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
    $paths = $this->paths->artifactPaths();
    $latest_tick = $this->readLatestTick();
    $parity = $this->readParity();
    $org_control = $this->readOrgControl();
    $release_control = $this->readReleaseControl();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];

    $build = $this->buildPage('Drupal LangGraph Console', 'Control-plane frame for the consolidated roadmap and LangGraph management surface.', $this->buildSectionRows('home'));
    $build['live_status'] = $this->tableDetails('Live Runtime Status', ['Signal', 'Current Value', 'Source'], [
      ['Latest tick timestamp', (string) ($latest_tick['ts'] ?? 'unavailable'), $this->toRelativePath($paths['ticks'])],
      ['Latest tick age', $this->formatAgeFromTimestamp((string) ($latest_tick['ts'] ?? '')), 'derived from latest tick timestamp'],
      ['Engine mode', (string) ($latest_tick['engine_mode'] ?? 'unknown'), $this->toRelativePath($paths['ticks'])],
      ['Provider', (string) ($latest_tick['provider'] ?? 'unknown'), $this->toRelativePath($paths['ticks'])],
      ['dry_run', $this->boolLabel($latest_tick['dry_run'] ?? NULL), $this->toRelativePath($paths['ticks'])],
      ['publish_enabled', $this->boolLabel($latest_tick['publish_enabled'] ?? NULL), $this->toRelativePath($paths['ticks'])],
      ['Org automation enabled', $this->boolLabel($org_control['enabled'] ?? NULL), $this->toRelativePath($this->activeControlPath('ORG_CONTROL_FILE', 'org_control_default', 'org_control_legacy'))],
      ['Release-cycle enabled', $this->boolLabel($release_control['enabled'] ?? NULL), $this->toRelativePath($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy'))],
      ['Parity health', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown', $this->toRelativePath($paths['parity'])],
      ['Latest step count', (string) count($step_results), $this->toRelativePath($paths['ticks'])],
    ]);
    $build['controls'] = $this->tableDetails('Management Controls', ['Control', 'Enabled', 'Updated at', 'Updated by', 'Reason'], [
      [
        'Org automation',
        $this->boolLabel($org_control['enabled'] ?? NULL),
        (string) ($org_control['updated_at'] ?? '-'),
        (string) ($org_control['updated_by'] ?? '-'),
        (string) ($org_control['reason'] ?? '-'),
      ],
      [
        'Release-cycle automation',
        $this->boolLabel($release_control['enabled'] ?? NULL),
        (string) ($release_control['updated_at'] ?? '-'),
        (string) ($release_control['updated_by'] ?? '-'),
        (string) ($release_control['reason'] ?? '-'),
      ],
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
    $build['graph_shape'] = $this->tableDetails('Observed Graph Shape', ['Node', 'Observation'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
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
    $build['parity_evidence'] = $this->tableDetails('Current Validation Evidence', ['Field', 'Value'], $rows, $this->toRelativePath($this->paths->artifactPaths()['parity']));
    return $build;
  }

  public function run(): array {
    $rows = [];
    $ticks = array_slice($this->readTicks(), -25);
    foreach (array_reverse($ticks) as $tick) {
      $rows[] = [
        (string) ($tick['ts'] ?? ''),
        $this->formatAgeFromTimestamp((string) ($tick['ts'] ?? '')),
        (string) ($tick['engine_mode'] ?? 'unknown'),
        (string) ($tick['provider'] ?? ''),
        (string) ($tick['agent_cap'] ?? ''),
        (string) $this->countTickErrors($tick),
      ];
    }

    $build = $this->buildPage('Run', 'Execution-plane timeline for recent LangGraph activity.', $this->buildSectionRows('run'));
    $build['run_timeline'] = $this->tableDetails('Recent Runs Timeline', ['Timestamp', 'Age', 'Engine mode', 'Provider', 'Agent cap', 'Error count'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
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
      $rows[] = [(string) $node, $status, $this->detailSummary($detail, ['mode', 'rc', 'skipped', 'error'])];
    }

    $build = $this->buildPage('Observe', 'Observability view over node diagnostics and runtime behavior.', $this->buildSectionRows('observe'));
    $build['node_diagnostics'] = $this->tableDetails('Latest Node Diagnostics', ['Node', 'Status', 'Details'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function release(): array {
    $release_control = $this->readReleaseControl();
    $release_rows = $this->readReleaseCycleRows();
    $coverage_rows = $this->buildReleaseCoverageRows($release_rows);

    $build = $this->buildPage('Release', 'Release-cycle and promotion posture for the control plane.', $this->buildSectionRows('release'));
    $build['control'] = $this->tableDetails('Release-cycle Control', ['Field', 'Value'], [
      ['enabled', $this->boolLabel($release_control['enabled'] ?? NULL)],
      ['updated_at', (string) ($release_control['updated_at'] ?? '-')],
      ['updated_by', (string) ($release_control['updated_by'] ?? '-')],
      ['reason', (string) ($release_control['reason'] ?? '-')],
    ], $this->toRelativePath($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy')));
    $build['release_state'] = $this->tableDetails('Release Cycle State', ['Team', 'Current Release', 'Next Release', 'Source'], $release_rows);
    $build['coverage'] = $this->tableDetails('Active Release Evidence Coverage', ['Release id', 'Release notes', 'PM signoffs'], $coverage_rows);
    return $build;
  }

  public function releaseEvidence(): array {
    $notes = $this->listReleaseNotes();
    $signoffs = $this->listReleaseSignoffs();

    $note_rows = [];
    foreach (array_slice($notes, 0, 10) as $note) {
      $note_rows[] = [
        $note['release_id'],
        $note['seat'],
        $note['site'],
        $note['state'],
        $note['updated_at'],
        $note['path'],
      ];
    }

    $signoff_rows = [];
    foreach (array_slice($signoffs, 0, 10) as $signoff) {
      $signoff_rows[] = [
        $signoff['release_id'],
        $signoff['site'],
        $signoff['seat'],
        $signoff['signed_off_at'],
        $signoff['path'],
      ];
    }

    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Release: Evidence') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Latest release-note narratives and PM signoff artifacts sourced from HQ session state.') . '</p>'],
      'notes' => $this->tableDetails('Recent Release Notes', ['Release id', 'Seat', 'Site', 'State', 'Updated at', 'Path'], $note_rows),
      'signoffs' => $this->tableDetails('Recent PM Signoffs', ['Release id', 'Site', 'PM seat', 'Signed off at', 'Path'], $signoff_rows),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Release'), Url::fromRoute('drupal_langgraph.langgraph_console_release'))->toString() . '</p>'],
    ];

    if ($notes !== []) {
      $build['latest_note'] = $this->textDetails('Latest Release-note Excerpt', $notes[0]['excerpt'], $notes[0]['path']);
    }

    return $build;
  }

  public function releaseTroubleshooting(): array {
    $items = $this->listActiveInboxItems();
    $rows = [];
    foreach ($items as $item) {
      $rows[] = [
        $item['seat'],
        $item['item_id'],
        $item['triage'],
        $item['status'],
        $item['roi'],
        $item['age'],
        $item['summary'],
        $item['path'],
      ];
    }

    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Release: Troubleshooting') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Seat-level triage of live inbox pressure, blocker-like work, and escalation-oriented items.') . '</p>'],
      'active_work' => $this->tableDetails('Active Inbox Items', ['Seat', 'Item', 'Triage', 'Status', 'ROI', 'Age', 'Summary', 'Path'], $rows),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Release'), Url::fromRoute('drupal_langgraph.langgraph_console_release'))->toString() . '</p>'],
    ];
  }

  public function featureProgress(): array {
    $feature_progress = $this->readFeatureProgress();
    $rows = [];
    foreach ($feature_progress['rows'] as $row) {
      $rows[] = [
        $row['Work item'] ?? '',
        $row['Website'] ?? '',
        $row['Module'] ?? '',
        $row['Status'] ?? '',
        $row['Priority'] ?? '',
        $row['PM'] ?? '',
        $row['Dev'] ?? '',
        $row['QA'] ?? '',
      ];
    }

    $summary = [];
    foreach ($feature_progress['rows'] as $row) {
      $status = trim((string) ($row['Status'] ?? 'unknown'));
      $summary[$status] = ($summary[$status] ?? 0) + 1;
    }
    ksort($summary);

    $build = $this->buildPage('Feature Progress', 'Read-only workflow snapshot sourced from the HQ feature progress dashboard.', []);
    $build['summary'] = $this->tableDetails(
      'Status Summary',
      ['Status', 'Count'],
      array_map(
        static fn(string $status, int $count): array => [$status !== '' ? $status : 'unknown', (string) $count],
        array_keys($summary),
        $summary
      ),
      $this->toRelativePath($this->paths->artifactPaths()['feature_progress'])
    );
    $build['generated'] = [
      '#markup' => '<p><strong>' . $this->t('Generated') . ':</strong> ' . $this->t('@generated', [
        '@generated' => $feature_progress['generated_at'] !== '' ? $feature_progress['generated_at'] : 'unknown',
      ]) . '</p>',
    ];
    $build['table'] = $this->tableDetails('Feature Progress', ['Work item', 'Website', 'Module', 'Status', 'Priority', 'PM', 'Dev', 'QA'], $rows, $this->toRelativePath($this->paths->artifactPaths()['feature_progress']));
    return $build;
  }

  public function admin(): array {
    $paths = $this->paths->artifactPaths();
    $rows = [];
    foreach ($paths as $label => $path) {
      $rows[] = [
        $label,
        $this->toRelativePath($path),
        file_exists($path) ? 'yes' : 'no',
        is_readable($path) ? 'yes' : 'no',
        is_file($path) ? ((string) filesize($path) . ' bytes') : '-',
      ];
    }

    $build = $this->buildPage('Admin', 'Path contracts and artifact health for the consolidated HQ module.', $this->buildSectionRows('admin'));
    $build['roots'] = $this->tableDetails('Runtime Roots', ['Root', 'Resolved path'], [
      ['FORSETI_ROOT', $this->paths->forsetiRoot()],
      ['COPILOT_HQ_ROOT', $this->paths->hqRuntimeRoot()],
    ]);
    $build['artifact_health'] = $this->tableDetails('Artifact Health', ['Artifact', 'Path', 'Exists', 'Readable', 'Size'], $rows);
    return $build;
  }

  public function adminRuntimeRoots(): array {
    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Admin: Runtime Roots') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Resolved filesystem roots used by Drupal LangGraph when reading HQ-managed artifacts.') . '</p>'],
      'roots' => $this->tableDetails('Runtime Roots', ['Root', 'Resolved path'], [
        ['FORSETI_ROOT', $this->paths->forsetiRoot()],
        ['COPILOT_HQ_ROOT', $this->paths->hqRuntimeRoot()],
      ]),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Admin'), Url::fromRoute('drupal_langgraph.langgraph_console_admin'))->toString() . '</p>'],
    ];
  }

  public function subsection(string $section, string $subsection): array {
    $map = $this->sectionMap();
    if (!isset($map[$section])) {
      throw new NotFoundHttpException();
    }

    $subsections = $map[$section]['subsections'];
    if (!isset($subsections[$subsection])) {
      throw new NotFoundHttpException();
    }

    $sub_info = $subsections[$subsection];
    $method = $sub_info['method'] ?? NULL;
    if (is_string($method) && method_exists($this, $method)) {
      return $this->{$method}();
    }

    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@section: @subsection', ['@section' => $map[$section]['title'], '@subsection' => $sub_info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($sub_info['description']) . '</p>'],
      'notice' => ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Stub Subsection') . ':</strong> ' . $this->t('This subsection frame is ready for future workflow wiring.') . '</div>'],
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to @section', ['@section' => $map[$section]['title']]), Url::fromRoute('drupal_langgraph.langgraph_console_' . $section))->toString() . '</p>'],
    ];
  }

  private function sectionMap(): array {
    return [
      'home' => [
        'title' => 'Home',
        'subsections' => [
          'runtime-status' => ['title' => 'Runtime Status', 'description' => 'High-level runtime health and control posture.', 'method' => 'home'],
        ],
      ],
      'build' => [
        'title' => 'Build',
        'subsections' => [
          'graph-shape' => ['title' => 'Graph Shape', 'description' => 'Observed node set and graph evidence from runtime artifacts.', 'method' => 'build'],
        ],
      ],
      'test' => [
        'title' => 'Test',
        'subsections' => [
          'parity-evidence' => ['title' => 'Parity Evidence', 'description' => 'Current parity report and validation summary.', 'method' => 'test'],
        ],
      ],
      'run' => [
        'title' => 'Run',
        'subsections' => [
          'recent-runs' => ['title' => 'Recent Runs', 'description' => 'Recent run timeline from the tick stream.', 'method' => 'run'],
        ],
      ],
      'observe' => [
        'title' => 'Observe',
        'subsections' => [
          'node-diagnostics' => ['title' => 'Node Diagnostics', 'description' => 'Latest node-level diagnostics and anomalies.', 'method' => 'observe'],
        ],
      ],
      'release' => [
        'title' => 'Release',
        'subsections' => [
          'release-cycle' => ['title' => 'Release Cycle', 'description' => 'Current and next release markers by team.', 'method' => 'release'],
          'release-evidence' => ['title' => 'Release Evidence', 'description' => 'Latest release notes and PM signoffs.', 'method' => 'releaseEvidence'],
          'release-troubleshooting' => ['title' => 'Release Troubleshooting', 'description' => 'Live inbox pressure and blocker-oriented work items.', 'method' => 'releaseTroubleshooting'],
        ],
      ],
      'admin' => [
        'title' => 'Admin',
        'subsections' => [
          'runtime-roots' => ['title' => 'Runtime Roots', 'description' => 'Resolved HQ filesystem roots for this environment.', 'method' => 'adminRuntimeRoots'],
          'artifacts' => ['title' => 'Artifacts', 'description' => 'Filesystem health for the module contract.', 'method' => 'admin'],
        ],
      ],
    ];
  }

  private function buildSectionRows(string $section): array {
    $rows = [];
    foreach ($this->sectionMap()[$section]['subsections'] as $slug => $info) {
      $rows[] = [
        Link::fromTextAndUrl($this->t($info['title']), Url::fromRoute('drupal_langgraph.langgraph_console_subsection', ['section' => $section, 'subsection' => $slug]))->toString(),
        $info['description'],
        $this->t('Ready'),
      ];
    }
    return $rows;
  }

  private function buildPage(string $title, string $description, array $sections): array {
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t($title) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($description) . '</p>'],
    ];

    if ($sections !== []) {
      $build['sections'] = [
        '#type' => 'table',
        '#header' => [$this->t('Subsection'), $this->t('Purpose'), $this->t('Status')],
        '#rows' => $sections,
      ];
    }

    return $build;
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

  private function textDetails(string $title, string $text, ?string $source = NULL): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => FALSE,
      'preview' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $text !== '' ? $text : $this->t('No text available.'),
      ],
    ];

    if ($source !== NULL) {
      $build['source'] = ['#markup' => '<p><strong>' . $this->t('Source') . ':</strong> ' . $source . '</p>'];
    }

    return $build;
  }

  private function boolLabel(mixed $value): string {
    return isset($value) ? ((bool) $value ? 'yes' : 'no') : 'unknown';
  }

  private function detailSummary(array $detail, array $keys): string {
    $parts = [];
    foreach ($keys as $key) {
      if (isset($detail[$key])) {
        $parts[] = $key . '=' . (string) $detail[$key];
      }
    }

    return $parts !== [] ? implode('; ', $parts) : 'ok';
  }

  private function activeControlPath(string $env_key, string $default_key, string $legacy_key): string {
    $paths = $this->paths->artifactPaths();
    $preferred = trim((string) getenv($env_key));
    foreach ([$preferred, $paths[$default_key] ?? '', $paths[$legacy_key] ?? ''] as $path) {
      $path = trim((string) $path);
      if ($path !== '' && is_readable($path)) {
        return $path;
      }
    }

    return trim((string) ($paths[$default_key] ?? $paths[$legacy_key] ?? ''));
  }

  private function readJsonFile(string $path): array {
    if ($path === '' || !is_readable($path)) {
      return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return [];
    }

    $decoded = json_decode((string) $raw, TRUE);
    return is_array($decoded) ? $decoded : [];
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
    return $this->readJsonFile($this->paths->artifactPaths()['parity']);
  }

  private function readOrgControl(): array {
    return $this->readJsonFile($this->activeControlPath('ORG_CONTROL_FILE', 'org_control_default', 'org_control_legacy'));
  }

  private function readReleaseControl(): array {
    return $this->readJsonFile($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy'));
  }

  private function readTextFile(string $path): string {
    if ($path === '' || !is_readable($path)) {
      return '';
    }

    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return '';
    }

    return trim((string) $raw);
  }

  private function readFeatureProgress(): array {
    $path = $this->paths->artifactPaths()['feature_progress'];
    $raw = $this->readTextFile($path);
    if ($raw === '') {
      return ['generated_at' => '', 'rows' => []];
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
      if (!str_starts_with($line, '|') || !str_starts_with($next, '|')) {
        continue;
      }
      if (!preg_match('/^\|\s*-+/', $next)) {
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
        $rows[] = array_combine($headers, $values) ?: [];
      }
      break;
    }

    return [
      'generated_at' => $generated_at,
      'rows' => $rows,
    ];
  }

  private function parseMarkdownTableRow(string $line): array {
    $parts = explode('|', trim($line, '|'));
    return array_map(static fn(string $part): string => trim($part), $parts);
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
      $rows[] = [$team, $current !== '' ? $current : '-', $next !== '' ? $next : '-', $this->toRelativePath($file)];
    }
    return $rows;
  }

  private function buildReleaseCoverageRows(array $release_rows): array {
    $notes = $this->listReleaseNotes();
    $signoffs = $this->listReleaseSignoffs();
    $note_ids = [];
    $signoff_counts = [];

    foreach ($notes as $note) {
      $note_ids[$note['release_id']] = TRUE;
    }
    foreach ($signoffs as $signoff) {
      $signoff_counts[$signoff['release_id']] = ($signoff_counts[$signoff['release_id']] ?? 0) + 1;
    }

    $rows = [];
    foreach ($release_rows as $row) {
      $release_id = (string) ($row[1] ?? '');
      if ($release_id === '' || $release_id === '-') {
        continue;
      }
      $rows[] = [
        $release_id,
        isset($note_ids[$release_id]) ? 'present' : 'missing',
        (string) ($signoff_counts[$release_id] ?? 0),
      ];
    }

    return $rows;
  }

  private function listReleaseNotes(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $files = glob($sessions_dir . '/*/artifacts/release-candidates/*/05-release-notes.md') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $rows = [];
    foreach ($files as $file) {
      $text = $this->readTextFile($file);
      $rows[] = [
        'release_id' => $this->extractFrontMatterValue($text, ['Release id']) ?: basename(dirname($file)),
        'seat' => basename(dirname(dirname(dirname($file)))),
        'site' => $this->extractFrontMatterValue($text, ['Site']),
        'state' => $this->extractFrontMatterValue($text, ['State']),
        'updated_at' => gmdate('c', (int) filemtime($file)),
        'path' => $this->toRelativePath($file),
        'excerpt' => implode("\n", array_slice(preg_split('/\R/', $text) ?: [], 0, 40)),
      ];
    }

    return $rows;
  }

  private function listReleaseSignoffs(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $files = glob($sessions_dir . '/*/artifacts/release-signoffs/*.md') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $rows = [];
    foreach ($files as $file) {
      $text = $this->readTextFile($file);
      $rows[] = [
        'release_id' => $this->extractFrontMatterValue($text, ['Release id']),
        'site' => $this->extractFrontMatterValue($text, ['Site']),
        'seat' => $this->extractFrontMatterValue($text, ['PM seat']) ?: basename(dirname(dirname(dirname($file)))),
        'signed_off_at' => $this->extractFrontMatterValue($text, ['Signed off at']) ?: gmdate('c', (int) filemtime($file)),
        'path' => $this->toRelativePath($file),
      ];
    }

    return $rows;
  }

  private function listActiveInboxItems(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $items = [];
    foreach (glob($sessions_dir . '/*/inbox/*') ?: [] as $path) {
      if (!is_dir($path) || basename($path) === '_archived') {
        continue;
      }

      $seat = basename(dirname(dirname($path)));
      $item_id = basename($path);
      $roi = $this->readTextFile($path . '/roi.txt');
      $summary = $this->readItemSummary($path);
      $last_progress = is_file($path . '/.last-progress-at') ? filemtime($path . '/.last-progress-at') : filemtime($path);
      $status = is_file($path . '/.inwork') ? 'in_progress' : 'queued';
      $triage = $this->inferTriageLabel($item_id, $summary);

      $items[] = [
        'seat' => $seat,
        'item_id' => $item_id,
        'triage' => $triage,
        'status' => $status,
        'roi' => $roi !== '' ? $roi : '-',
        'age' => $this->formatAgeFromEpoch((int) $last_progress),
        'summary' => $summary,
        'path' => $this->toRelativePath($path),
        'sort_epoch' => (int) $last_progress,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      if ($a['triage'] !== $b['triage']) {
        return strcmp((string) $a['triage'], (string) $b['triage']);
      }
      return ($a['sort_epoch'] ?? 0) <=> ($b['sort_epoch'] ?? 0);
    });

    return array_map(static function (array $item): array {
      unset($item['sort_epoch']);
      return $item;
    }, $items);
  }

  private function readItemSummary(string $item_dir): string {
    foreach (['command.md', 'README.md', '00-problem-statement.md'] as $candidate) {
      $path = $item_dir . '/' . $candidate;
      if (!is_readable($path)) {
        continue;
      }
      $lines = preg_split('/\R/', $this->readTextFile($path)) ?: [];
      foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '**From:**') || str_starts_with($line, '**To:**')) {
          continue;
        }
        return ltrim($line, "# \t");
      }
    }

    return '(no summary found)';
  }

  private function inferTriageLabel(string $item_id, string $summary): string {
    $haystack = strtolower($item_id . ' ' . $summary);
    if (str_contains($haystack, 'needs-') || str_contains($haystack, 'needs info')) {
      return 'needs-info';
    }
    if (str_contains($haystack, 'blocked') || str_contains($haystack, 'stagnation') || str_contains($haystack, 'awaiting')) {
      return 'blocked';
    }

    return 'active';
  }

  private function extractFrontMatterValue(string $text, array $labels): string {
    $lines = preg_split('/\R/', $text) ?: [];
    $normalized_labels = array_map(static fn(string $label): string => trim(str_replace('**', '', $label)), $labels);
    foreach ($lines as $line) {
      $normalized_line = trim(str_replace('**', '', (string) $line));
      foreach ($normalized_labels as $label) {
        $prefix = $label . ':';
        if (str_starts_with($normalized_line, $prefix)) {
          return trim(substr($normalized_line, strlen($prefix)));
        }
      }
    }

    return '';
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

  private function formatAgeFromEpoch(int $epoch): string {
    if ($epoch <= 0) {
      return 'unknown';
    }
    return (string) max(0, time() - $epoch) . 's';
  }

  private function toRelativePath(string $path): string {
    $forseti_root = rtrim($this->paths->forsetiRoot(), '/') . '/';
    if (str_starts_with($path, $forseti_root)) {
      return substr($path, strlen($forseti_root));
    }
    return $path;
  }

}
