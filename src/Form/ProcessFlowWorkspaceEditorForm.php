<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_langgraph\Service\ProcessFlowRegistryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProcessFlowWorkspaceEditorForm extends FormBase {

  use ProcessFlowUiTextTrait;

  public function __construct(
    private readonly ProcessFlowRegistryService $registry,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.process_flow_registry'),
    );
  }

  public function getFormId(): string {
    return 'drupal_langgraph_process_flow_workspace_editor_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $flow = [], string $subsection = ''): array {
    $form_state->set('flow', $flow);
    $form_state->set('subsection', $subsection);

    $form['intro'] = $this->formStatusMessage(
      'Edit flow contract',
      'Update the flow contract for this workspace control.',
      [
        'Changes are stored in the Drupal LangGraph flow registry and then shown back through the Build and Test workspaces.',
        'Use this editor for contract changes only; runtime execution still happens through Run and Release automation.',
      ]
    );
    if (($flow['source'] ?? '') === 'built-in') {
      $form['override_notice'] = $this->formWarningMessage('Built-in flow override', 'Saving changes here creates a custom override for this environment. Build remains the edit surface, but the built-in definition is no longer the only source for this flow.');
    }

    switch ($subsection) {
      case 'metadata':
        $form += $this->buildMetadataElements($flow);
        break;

      case 'state-schema':
        $form['state_schema_summary'] = [
          '#type' => 'textarea',
          '#title' => $this->t('State schema summary'),
          '#description' => $this->stateSchemaDescription(),
          '#default_value' => (string) ($flow['state_schema_summary'] ?? ''),
          '#rows' => 6,
          '#required' => TRUE,
        ];
        break;

      case 'nodes':
        $form['nodes'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Nodes'),
          '#description' => $this->nodesDescription(),
          '#default_value' => implode("\n", (array) ($flow['nodes'] ?? [])),
          '#rows' => 10,
        ];
        $form['default_entrypoint'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Default entrypoint'),
          '#description' => $this->t('Use this field when node changes and entrypoint changes need to be saved together.'),
          '#default_value' => (string) ($flow['default_entrypoint'] ?? ''),
          '#required' => TRUE,
        ];
        break;

      case 'routing':
        $form['routing_rules'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Routing rules'),
          '#description' => $this->routingRulesDescription(),
          '#default_value' => implode("\n", (array) ($flow['routing_rules'] ?? [])),
          '#rows' => 8,
        ];
        break;

      case 'tools':
        $form['tools'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Tools'),
          '#description' => $this->toolsDescription(),
          '#options' => $this->registry->toolOptions(),
          '#default_value' => array_values((array) ($flow['tools'] ?? [])),
        ];
        break;

      case 'prompts':
        $form['prompt_notes'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Prompt notes'),
          '#description' => $this->promptNotesDescription(),
          '#default_value' => (string) ($flow['prompt_notes'] ?? ''),
          '#rows' => 8,
        ];
        break;
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save flow changes'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = (array) $form_state->get('flow');
    $subsection = (string) $form_state->get('subsection');
    $updated = $flow;

    switch ($subsection) {
      case 'metadata':
        $updated['label'] = (string) $form_state->getValue('label');
        $updated['description'] = (string) $form_state->getValue('description');
        $updated['owner'] = (string) $form_state->getValue('owner');
        $updated['status'] = (string) $form_state->getValue('status');
        $updated['graph_type'] = (string) $form_state->getValue('graph_type');
        $updated['primary_section'] = (string) $form_state->getValue('primary_section');
        $updated['default_entrypoint'] = (string) $form_state->getValue('default_entrypoint');
        $updated['version'] = (string) $form_state->getValue('version');
        break;

      case 'state-schema':
        $updated['state_schema_summary'] = (string) $form_state->getValue('state_schema_summary');
        break;

      case 'nodes':
        $updated['nodes'] = $this->registry->parseLineList((string) $form_state->getValue('nodes'));
        $updated['default_entrypoint'] = (string) $form_state->getValue('default_entrypoint');
        break;

      case 'routing':
        $updated['routing_rules'] = $this->registry->parseLineList((string) $form_state->getValue('routing_rules'));
        break;

      case 'tools':
        $updated['tools'] = array_values(array_filter(array_map('strval', (array) $form_state->getValue('tools')), static fn(string $value): bool => $value !== '0' && $value !== ''));
        break;

      case 'prompts':
        $updated['prompt_notes'] = (string) $form_state->getValue('prompt_notes');
        break;
    }

    if (($flow['source'] ?? '') === 'built-in') {
      $updated['source'] = 'custom_override';
    }

    $this->registry->saveFlow($updated);
    $this->messenger()->addStatus($this->t('Updated %label.', ['%label' => $updated['label'] ?? $updated['id'] ?? 'flow']));
    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $updated['id'],
      'section' => 'build',
      'subsection' => $subsection,
    ]);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $flow = (array) $form_state->get('flow');
    $subsection = (string) $form_state->get('subsection');

    if ($subsection === 'metadata') {
      $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
      $entrypoint = trim((string) $form_state->getValue('default_entrypoint'));
      if (!$this->registry->entrypointMatchesNodes($entrypoint, $nodes)) {
        $form_state->setErrorByName('default_entrypoint', $this->t('Default entrypoint must match one of the configured nodes, or update the node list first.'));
      }
    }

    if ($subsection === 'nodes') {
      $nodes = $this->registry->parseLineList((string) $form_state->getValue('nodes'));
      $duplicates = $this->registry->duplicateValues($nodes);
      if ($duplicates !== []) {
        $form_state->setErrorByName('nodes', $this->t('Node names must be unique. Duplicate values: @duplicates', ['@duplicates' => implode(', ', $duplicates)]));
      }

      $entrypoint = trim((string) $form_state->getValue('default_entrypoint'));
      if (!$this->registry->entrypointMatchesNodes($entrypoint, $nodes)) {
        $form_state->setErrorByName('default_entrypoint', $this->t('Default entrypoint %entrypoint is not present in the proposed node list.', ['%entrypoint' => $entrypoint]));
      }
    }

    if ($subsection === 'routing') {
      $routing_rules = $this->registry->parseLineList((string) $form_state->getValue('routing_rules'));
      $duplicates = $this->registry->duplicateValues($routing_rules);
      if ($duplicates !== []) {
        $form_state->setErrorByName('routing_rules', $this->t('Routing rules must be unique. Duplicate values: @duplicates', ['@duplicates' => implode(' | ', $duplicates)]));
      }
    }
  }

  private function buildMetadataElements(array $flow): array {
    return [
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Flow name'),
        '#default_value' => (string) ($flow['label'] ?? ''),
        '#required' => TRUE,
        '#maxlength' => 128,
      ],
      'description' => [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => (string) ($flow['description'] ?? ''),
        '#required' => TRUE,
        '#rows' => 4,
      ],
      'owner' => [
        '#type' => 'textfield',
        '#title' => $this->t('Owner seat ID'),
        '#default_value' => (string) ($flow['owner'] ?? 'ceo-copilot-2'),
        '#description' => $this->ownerFieldDescription(),
        '#required' => TRUE,
      ],
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          'draft' => $this->t('Draft'),
          'active' => $this->t('Active'),
          'paused' => $this->t('Paused'),
          'archived' => $this->t('Archived'),
        ],
        '#default_value' => (string) ($flow['status'] ?? 'draft'),
        '#required' => TRUE,
      ],
      'graph_type' => [
        '#type' => 'select',
        '#title' => $this->t('Graph type'),
        '#description' => $this->graphTypeFieldDescription(),
        '#options' => [
          'state_graph' => $this->t('State graph'),
          'subgraph' => $this->t('Subgraph'),
          'supervisor_graph' => $this->t('Supervisor graph'),
          'router_graph' => $this->t('Router graph'),
        ],
        '#default_value' => (string) ($flow['graph_type'] ?? 'state_graph'),
        '#required' => TRUE,
      ],
      'primary_section' => [
        '#type' => 'select',
        '#title' => $this->t('Primary console section'),
        '#description' => $this->primarySectionFieldDescription(),
        '#options' => [
          'flows' => $this->t('Flows'),
          'build' => $this->t('Build'),
          'test' => $this->t('Test'),
          'run' => $this->t('Run'),
          'observe' => $this->t('Observe'),
          'release' => $this->t('Release'),
          'admin' => $this->t('Admin'),
        ],
        '#default_value' => (string) ($flow['primary_section'] ?? 'build'),
        '#required' => TRUE,
      ],
      'default_entrypoint' => [
        '#type' => 'textfield',
        '#title' => $this->t('Default entrypoint'),
        '#description' => $this->defaultEntrypointDescription(),
        '#default_value' => (string) ($flow['default_entrypoint'] ?? ''),
        '#required' => TRUE,
      ],
      'version' => [
        '#type' => 'textfield',
        '#title' => $this->t('Version'),
        '#description' => $this->versionFieldDescription(),
        '#default_value' => (string) ($flow['version'] ?? 'draft'),
        '#required' => TRUE,
      ],
    ];
  }

}
