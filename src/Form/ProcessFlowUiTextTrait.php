<?php

namespace Drupal\drupal_langgraph\Form;

trait ProcessFlowUiTextTrait {

  protected function formStatusMessage(string $title, string $message, array $items = []): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--status']],
      'message' => [
        '#markup' => '<strong>' . $this->t($title) . ':</strong> ' . $this->t($message),
      ],
    ];

    if ($items !== []) {
      $build['items'] = [
        '#theme' => 'item_list',
        '#items' => array_map(fn(string $item): string => (string) $this->t($item), $items),
      ];
    }

    return $build;
  }

  protected function formWarningMessage(string $title, string $message): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'message' => [
        '#markup' => '<strong>' . $this->t($title) . ':</strong> ' . $this->t($message),
      ],
    ];
  }

  protected function ownerFieldDescription(): string {
    return (string) $this->t('Use a real seat ID from org-chart/agents/agents.yaml so flow ownership maps to the org chart and instruction stack.');
  }

  protected function graphTypeFieldDescription(): string {
    return (string) $this->t('Choose the LangGraph architecture this flow represents: state graph, subgraph, supervisor graph, or router graph.');
  }

  protected function primarySectionFieldDescription(): string {
    return (string) $this->t('This section becomes the primary landing context when opening the flow workspace.');
  }

  protected function defaultEntrypointDescription(): string {
    return (string) $this->t('The first node or orchestration entrypoint for this flow. It should match a configured node when nodes are defined. If you need to change nodes and entrypoint together, use the Nodes editor.');
  }

  protected function versionFieldDescription(): string {
    return (string) $this->t('Use a durable version marker such as draft, runtime-observed, or a release identifier like v1.0.0.');
  }

  protected function stateSchemaDescription(): string {
    return (string) $this->t('Describe the state carried between nodes and what matters operationally.');
  }

  protected function nodesDescription(): string {
    return (string) $this->t('One node per line. Use the same names referenced by entrypoints and runtime traces.');
  }

  protected function routingRulesDescription(): string {
    return (string) $this->t('One routing rule per line. Keep rules unique so Build and Test show unambiguous flow behavior.');
  }

  protected function toolsDescription(): string {
    return (string) $this->t('Bind the runtime capabilities and artifact surfaces this flow depends on.');
  }

  protected function promptNotesDescription(): string {
    return (string) $this->t('Capture system-prompt, guardrail, or orchestration notes for this process flow.');
  }

}
