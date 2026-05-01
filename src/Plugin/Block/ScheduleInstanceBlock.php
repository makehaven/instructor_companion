<?php

namespace Drupal\instructor_companion\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Theme-agnostic button to clone a course's CiviCRM template into a new event.
 *
 * Drop into the course bundle's Layout Builder layout via "Add block →
 * Schedule new instance" so staff can scheduled new instances without relying
 * on the Gin admin top-bar (which hides on narrow viewports and isn't visible
 * outside the admin theme). Permission-gated: only renders for users with
 * `create civicrm_event entities`.
 *
 * @Block(
 *   id = "instructor_companion_schedule_instance",
 *   admin_label = @Translation("Schedule new instance (course)"),
 *   category = @Translation("Instructor Companion"),
 *   context_definitions = {
 *     "node" = @ContextDefinition(
 *       "entity:node",
 *       label = @Translation("Node"),
 *       required = FALSE
 *     )
 *   }
 * )
 */
class ScheduleInstanceBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected AccountInterface $currentUser;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  public function build(): array {
    $node = $this->getContextValue('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'course') {
      return [];
    }

    $template_id = $node->hasField('field_civicrm_template_id') && !$node->get('field_civicrm_template_id')->isEmpty()
      ? (int) $node->get('field_civicrm_template_id')->value
      : NULL;

    if ($template_id) {
      $build = [
        '#type' => 'link',
        '#title' => $this->t('Schedule New Instance'),
        '#url' => Url::fromRoute('instructor_companion.schedule_instance', [
          'node' => $node->id(),
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--action'],
          'title' => $this->t('Clones template @id (with reminders, fees, profiles) into a new event and opens the edit form.', ['@id' => $template_id]),
        ],
      ];
    }
    else {
      $build = [
        '#type' => 'link',
        '#title' => $this->t('Configure CiviCRM Template to enable scheduling'),
        '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], [
          'fragment' => 'edit-field-civicrm-template-id-0-value',
        ]),
        '#attributes' => [
          'class' => ['button', 'button--secondary'],
          'title' => $this->t('Set field_civicrm_template_id on this course to a CiviCRM event template.'),
        ],
      ];
    }

    $build['#cache'] = [
      'contexts' => ['user.permissions'],
      'tags' => $node->getCacheTags(),
    ];
    return $build;
  }

  protected function blockAccess(AccountInterface $account): AccessResult {
    $node = $this->getContextValue('node');
    return AccessResult::allowedIf(
      $node instanceof NodeInterface
      && $node->bundle() === 'course'
      && $account->hasPermission('create civicrm_event entities')
    )->cachePerPermissions();
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user.permissions', 'route']);
  }

  public function getCacheTags(): array {
    $tags = parent::getCacheTags();
    $node = $this->getContextValue('node');
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return $tags;
  }

}
