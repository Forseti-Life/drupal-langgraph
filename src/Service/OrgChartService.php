<?php

namespace Drupal\drupal_langgraph\Service;

use Symfony\Component\Yaml\Yaml;

final class OrgChartService {

  private const ORG_WIDE_PATH = 'org-chart/org-wide.instructions.md';

  private ?array $seatCache = NULL;

  public function __construct(
    private readonly HqPathManager $paths,
  ) {}

  public function seats(): array {
    if ($this->seatCache !== NULL) {
      return $this->seatCache;
    }

    $agents = $this->readYaml($this->agentsPath())['agents'] ?? [];
    $module_ownership = $this->readYaml($this->moduleOwnershipPath())['websites'] ?? [];
    $repository_ownership = $this->readYaml($this->repositoryOwnershipPath())['repositories'] ?? [];

    $seats = [];
    foreach (is_array($agents) ? $agents : [] as $agent) {
      if (!is_array($agent)) {
        continue;
      }

      $seat_id = trim((string) ($agent['id'] ?? ''));
      if ($seat_id === '') {
        continue;
      }

      $role = (string) ($agent['role'] ?? '');
      $website_scope = array_values(array_filter(array_map('strval', (array) ($agent['website_scope'] ?? [])), static fn(string $value): bool => $value !== ''));

      $seats[$seat_id] = [
        'id' => $seat_id,
        'name' => (string) ($agent['name'] ?? $seat_id),
        'role' => $role,
        'role_label' => $this->roleLabel($role),
        'supervisor' => (string) ($agent['supervisor'] ?? ''),
        'website_scope' => $website_scope,
        'scope_label' => $website_scope !== [] ? implode(', ', $website_scope) : '-',
        'paused' => (bool) ($agent['paused'] ?? FALSE),
        'notes' => (string) ($agent['notes'] ?? ''),
        'module_ownership_declared' => array_values(array_filter(array_map('strval', (array) ($agent['module_ownership'] ?? [])), static fn(string $value): bool => $value !== '')),
        'instruction_layers' => $this->instructionLayersForSeat($seat_id, $role, $website_scope),
        'ownership_context' => [],
        'subordinates' => [],
      ];
    }

    foreach ($seats as $seat_id => &$seat) {
      $seat['ownership_context'] = array_merge(
        $this->moduleOwnershipContext($seat_id, $seat, is_array($module_ownership) ? $module_ownership : []),
        $this->repositoryOwnershipContext($seat_id, is_array($repository_ownership) ? $repository_ownership : [])
      );
    }
    unset($seat);

    foreach ($seats as $seat_id => $seat) {
      $supervisor = (string) ($seat['supervisor'] ?? '');
      if ($supervisor !== '' && isset($seats[$supervisor])) {
        $seats[$supervisor]['subordinates'][] = $seat_id;
      }
    }

    foreach ($seats as &$seat) {
      sort($seat['subordinates']);
    }
    unset($seat);

    uasort($seats, static function (array $a, array $b): int {
      return strcmp((string) ($a['name'] ?? $a['id'] ?? ''), (string) ($b['name'] ?? $b['id'] ?? ''));
    });

    $this->seatCache = $seats;
    return $this->seatCache;
  }

  public function getSeat(string $seat_id): ?array {
    $seats = $this->seats();
    return $seats[$seat_id] ?? NULL;
  }

  public function seatOwnerLabel(string $seat_id): string {
    $seat = $this->getSeat($seat_id);
    if ($seat === NULL) {
      return $seat_id;
    }

    return sprintf('%s — %s', $seat['id'], $seat['name']);
  }

  public function seatOptions(): array {
    $options = [];
    foreach ($this->seats() as $seat) {
      $suffix = $seat['paused'] ? ' [paused]' : '';
      $options[$seat['id']] = sprintf('%s — %s%s', $seat['id'], $seat['name'], $suffix);
    }
    return $options;
  }

  public function instructionModelRows(): array {
    return [
      ['Org-wide', self::ORG_WIDE_PATH, 'Applies to every seat in the system.'],
      ['Role', 'org-chart/roles/<role>.instructions.md', 'Defines role-level rules and expectations shared across seats with the same role.'],
      ['Site/Product', 'org-chart/sites/<site>/site.instructions.md', 'Adds environment and product operating rules for seats scoped to a site or product.'],
      ['Seat', 'org-chart/agents/instructions/<agent-id>.instructions.md', 'Adds seat-specific scope, process details, and local operational guidance.'],
    ];
  }

  public function summary(array $flows = []): array {
    $seats = $this->seats();
    $paused = 0;
    $roles = [];
    $sites = [];
    foreach ($seats as $seat) {
      if ($seat['paused']) {
        $paused++;
      }
      if (($seat['role'] ?? '') !== '') {
        $roles[$seat['role']] = TRUE;
      }
      foreach ((array) ($seat['website_scope'] ?? []) as $scope) {
        if ($scope !== '') {
          $sites[(string) $scope] = TRUE;
        }
      }
    }

    $flow_owner_gaps = 0;
    foreach ($flows as $flow) {
      if (!isset($seats[(string) ($flow['owner'] ?? '')])) {
        $flow_owner_gaps++;
      }
    }

    return [
      ['Configured seats', (string) count($seats)],
      ['Active seats', (string) (count($seats) - $paused)],
      ['Paused seats', (string) $paused],
      ['Distinct roles', (string) count($roles)],
      ['Distinct site scopes', (string) count($sites)],
      ['Flows with non-seat owner values', (string) $flow_owner_gaps],
    ];
  }

  private function instructionLayersForSeat(string $seat_id, string $role, array $website_scope): array {
    $layers = [];

    $layers[] = $this->instructionLayerRow('Org-wide', $this->paths->resolveForseti(self::ORG_WIDE_PATH), 'All seats');

    if ($role !== '') {
      $layers[] = $this->instructionLayerRow('Role', $this->paths->resolveForseti(sprintf('org-chart/roles/%s.instructions.md', $role)), $this->roleLabel($role));
    }

    foreach ($website_scope as $scope) {
      if ($scope === '*') {
        continue;
      }
      $layers[] = $this->instructionLayerRow('Site/Product', $this->paths->resolveForseti(sprintf('org-chart/sites/%s/site.instructions.md', $scope)), $scope);
    }

    $layers[] = $this->instructionLayerRow('Seat', $this->paths->resolveForseti(sprintf('org-chart/agents/instructions/%s.instructions.md', $seat_id)), $seat_id);

    return $layers;
  }

  private function instructionLayerRow(string $layer, string $path, string $applies_to): array {
    return [
      'layer' => $layer,
      'path' => $path,
      'exists' => is_readable($path),
      'applies_to' => $applies_to,
    ];
  }

  private function moduleOwnershipContext(string $seat_id, array $seat, array $websites): array {
    $context = [];
    foreach ($seat['module_ownership_declared'] as $module_name) {
      $context[] = [
        'type' => 'Declared module scope',
        'label' => $module_name,
        'detail' => 'Seat declares direct module ownership in agents.yaml.',
      ];
    }

    foreach ($websites as $website => $data) {
      if (!is_array($data)) {
        continue;
      }
      $module_owners = $data['module_owners'] ?? [];
      if (!is_array($module_owners)) {
        continue;
      }

      foreach ($module_owners as $module => $owner_map) {
        if (!is_array($owner_map)) {
          continue;
        }
        foreach ($owner_map as $responsibility => $owner_seat) {
          if ((string) $owner_seat === $seat_id) {
            $context[] = [
              'type' => 'Module ownership',
              'label' => sprintf('%s / %s', (string) $website, (string) $module),
              'detail' => sprintf('%s seat for module ownership mapping.', $this->roleLabelFromResponsibility((string) $responsibility)),
            ];
          }
        }
      }
    }

    return $context;
  }

  private function repositoryOwnershipContext(string $seat_id, array $repositories): array {
    $context = [];
    foreach ($repositories as $repo_key => $repo) {
      if (!is_array($repo)) {
        continue;
      }
      foreach ([
        'owning_pm' => 'Repository ownership (PM)',
        'owning_dev' => 'Repository ownership (Dev)',
        'owning_qa' => 'Repository ownership (QA)',
        'owning_security' => 'Repository ownership (Security)',
      ] as $field => $label) {
        if ((string) ($repo[$field] ?? '') === $seat_id) {
          $context[] = [
            'type' => $label,
            'label' => (string) $repo_key,
            'detail' => sprintf('%s; module=%s', (string) ($repo['product_team'] ?? 'unknown team'), (string) ($repo['module'] ?? 'n/a')),
          ];
        }
      }
    }

    return $context;
  }

  private function roleLabel(string $role): string {
    if ($role === '') {
      return 'Unknown';
    }

    return ucwords(str_replace(['-', '_'], ' ', $role));
  }

  private function roleLabelFromResponsibility(string $responsibility): string {
    return match ($responsibility) {
      'pm' => 'PM',
      'ba' => 'BA',
      'dev' => 'Dev',
      'qa' => 'QA',
      'security' => 'Security',
      default => $this->roleLabel($responsibility),
    };
  }

  private function readYaml(string $path): array {
    if (!is_readable($path)) {
      return [];
    }

    try {
      $decoded = Yaml::parseFile($path);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  private function agentsPath(): string {
    return $this->paths->resolveForseti('org-chart/agents/agents.yaml');
  }

  private function moduleOwnershipPath(): string {
    return $this->paths->resolveForseti('org-chart/ownership/module-ownership.yaml');
  }

  private function repositoryOwnershipPath(): string {
    return $this->paths->resolveForseti('org-chart/ownership/repository-ownership.yaml');
  }

}
