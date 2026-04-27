<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_langgraph\Service\ControlPlaneArtifactService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProcessFlowRuntimeRequestForm extends FormBase {

  use ProcessFlowUiTextTrait;

  public function __construct(
    private readonly ControlPlaneArtifactService $artifacts,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.control_plane_artifacts'),
    );
  }

  public function getFormId(): string {
    return 'drupal_langgraph_process_flow_runtime_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $flow = [], string $subsection = ''): array {
    $form_state->set('flow', $flow);
    $form_state->set('subsection', $subsection);

    $form['intro'] = $this->formStatusMessage(
      'Submit runtime control request',
      'Record an auditable runtime control request for this flow.',
      [
        'Requests are written to the shared control-plane artifact model for pickup by runtime automation.',
        'Use dry run when you want evidence without publication, and use pause or resume only when you intend to change automation state.',
      ]
    );

    if ($subsection === 'manual-run') {
        $form['requested_mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Requested mode'),
          '#description' => $this->t('Live runs allow normal publication behavior; dry runs execute the tick without publishing.'),
          '#options' => [
          'live' => $this->t('Live'),
          'dry_run' => $this->t('Dry run'),
        ],
        '#default_value' => 'dry_run',
        '#required' => TRUE,
      ];
    }

    if ($subsection === 'pause-resume') {
        $form['requested_state'] = [
          '#type' => 'select',
          '#title' => $this->t('Requested state'),
          '#description' => $this->t('Pause disables org automation until resumed. Resume re-enables the current org control contract.'),
          '#options' => [
          'pause' => $this->t('Pause'),
          'resume' => $this->t('Resume'),
        ],
        '#default_value' => 'pause',
        '#required' => TRUE,
      ];
    }

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Explain why this request is needed so operators can interpret the artifact history later.'),
      '#rows' => 4,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit control request'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = (array) $form_state->get('flow');
    $subsection = (string) $form_state->get('subsection');
    $flow_id = (string) ($flow['id'] ?? '');
    if ($flow_id === '') {
      throw new \RuntimeException('Runtime request form is missing the target flow identifier.');
    }
    $record = $this->artifacts->submitRuntimeRequest($flow, $subsection, $this->currentUser()->getAccountName(), [
      'reason' => (string) $form_state->getValue('reason'),
      'requested_mode' => $subsection === 'manual-run' ? (string) $form_state->getValue('requested_mode') : NULL,
      'requested_state' => $subsection === 'pause-resume' ? (string) $form_state->getValue('requested_state') : NULL,
    ]);

    $this->messenger()->addStatus($this->t('Submitted a @action request for %flow. Request ID: %request_id.', [
      '@action' => $subsection,
      '%flow' => $record['flow_label'] !== '' ? $record['flow_label'] : $record['flow_id'],
      '%request_id' => $record['request_id'],
    ]));
    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $record['flow_id'],
      'section' => 'run',
      'subsection' => $subsection,
    ]);
  }

}
