<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_langgraph\Service\ProcessFlowRegistryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProcessFlowAddForm extends FormBase {

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
    return 'drupal_langgraph_process_flow_add_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = $this->formStatusMessage(
      'Create process flow',
      'Create a draft process flow entry for the Drupal LangGraph console.',
      [
        'Use Build to define structure, Test to validate it, Run to request execution, Observe to inspect behavior, and Release to manage versions.',
        'This form captures the flow contract only; it does not execute LangGraph work by itself.',
      ]
    );

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Flow name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Flow ID'),
      '#required' => TRUE,
      '#machine_name' => [
        'exists' => [$this, 'flowIdExists'],
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#rows' => 4,
    ];

    $form['owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner seat ID'),
      '#default_value' => 'ceo-copilot-2',
      '#description' => $this->ownerFieldDescription(),
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        'draft' => $this->t('Draft'),
        'active' => $this->t('Active'),
        'paused' => $this->t('Paused'),
        'archived' => $this->t('Archived'),
      ],
      '#default_value' => 'draft',
      '#required' => TRUE,
    ];

    $form['graph_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Graph type'),
      '#description' => $this->graphTypeFieldDescription(),
      '#options' => [
        'state_graph' => $this->t('State graph'),
        'subgraph' => $this->t('Subgraph'),
        'supervisor_graph' => $this->t('Supervisor graph'),
        'router_graph' => $this->t('Router graph'),
      ],
      '#default_value' => 'state_graph',
      '#required' => TRUE,
    ];

    $form['primary_section'] = [
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
      '#default_value' => 'build',
      '#required' => TRUE,
    ];

    $form['default_entrypoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default entrypoint'),
      '#description' => $this->defaultEntrypointDescription(),
      '#required' => TRUE,
    ];

    $form['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Initial version'),
      '#default_value' => 'draft',
      '#description' => $this->versionFieldDescription(),
      '#required' => TRUE,
    ];

    $form['architecture'] = [
      '#type' => 'details',
      '#title' => $this->t('Flow structure'),
      '#open' => TRUE,
    ];

    $form['architecture']['state_schema_summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('State schema summary'),
      '#description' => $this->stateSchemaDescription(),
      '#rows' => 3,
    ];

    $form['architecture']['nodes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Nodes'),
      '#description' => $this->nodesDescription(),
      '#rows' => 5,
    ];

    $form['architecture']['routing_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Routing rules'),
      '#description' => $this->routingRulesDescription(),
      '#rows' => 4,
    ];

    $form['orchestration'] = [
      '#type' => 'details',
      '#title' => $this->t('Tools and prompts'),
      '#open' => TRUE,
    ];

    $form['orchestration']['tools'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Tools'),
      '#description' => $this->toolsDescription(),
      '#options' => $this->registry->toolOptions(),
    ];

    $form['orchestration']['prompt_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt notes'),
      '#description' => $this->promptNotesDescription(),
      '#rows' => 4,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create process flow'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $id = (string) $form_state->getValue('id');
    if ($this->flowIdExists($id)) {
      $form_state->setErrorByName('id', $this->t('A process flow with ID %id already exists.', ['%id' => $id]));
    }

    $nodes = $this->registry->parseLineList((string) $form_state->getValue('nodes'));
    $node_duplicates = $this->registry->duplicateValues($nodes);
    if ($node_duplicates !== []) {
      $form_state->setErrorByName('nodes', $this->t('Node names must be unique. Duplicate values: @duplicates', ['@duplicates' => implode(', ', $node_duplicates)]));
    }

    $entrypoint = trim((string) $form_state->getValue('default_entrypoint'));
    if (!$this->registry->entrypointMatchesNodes($entrypoint, $nodes)) {
      $form_state->setErrorByName('default_entrypoint', $this->t('Default entrypoint must match one of the configured nodes.'));
    }

    $routing_rules = $this->registry->parseLineList((string) $form_state->getValue('routing_rules'));
    $routing_duplicates = $this->registry->duplicateValues($routing_rules);
    if ($routing_duplicates !== []) {
      $form_state->setErrorByName('routing_rules', $this->t('Routing rules must be unique. Duplicate values: @duplicates', ['@duplicates' => implode(' | ', $routing_duplicates)]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = [
      'id' => (string) $form_state->getValue('id'),
      'label' => (string) $form_state->getValue('label'),
      'description' => (string) $form_state->getValue('description'),
      'owner' => (string) $form_state->getValue('owner'),
      'status' => (string) $form_state->getValue('status'),
      'graph_type' => (string) $form_state->getValue('graph_type'),
      'primary_section' => (string) $form_state->getValue('primary_section'),
      'default_entrypoint' => (string) $form_state->getValue('default_entrypoint'),
      'version' => (string) $form_state->getValue('version'),
      'source' => 'custom',
      'state_schema_summary' => (string) $form_state->getValue('state_schema_summary'),
      'nodes' => $this->registry->parseLineList((string) $form_state->getValue('nodes')),
      'routing_rules' => $this->registry->parseLineList((string) $form_state->getValue('routing_rules')),
      'tools' => array_values(array_filter(array_map('strval', (array) $form_state->getValue('tools')), static fn(string $value): bool => $value !== '0' && $value !== '')),
      'prompt_notes' => (string) $form_state->getValue('prompt_notes'),
    ];

    $this->registry->saveFlow($flow);
    $this->messenger()->addStatus($this->t('Process flow %label was added to the Drupal LangGraph registry.', ['%label' => $flow['label']]));
    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]);
  }

  public function flowIdExists(string $flow_id): bool {
    return $flow_id !== '' && $this->registry->getFlow($flow_id) !== NULL;
  }

}
