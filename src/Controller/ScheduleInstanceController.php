<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Spawns a new CiviCRM event instance from a course's linked template.
 *
 * Uses CRM_Event_BAO_Event::copy() — that's the only API that carries
 * scheduled reminders, price set, and registration profiles from a CiviCRM
 * template. Verified locally on template id=3 (Workshop): 5 reminders, 1
 * price set, 6 profiles all carried over with entity_value rewritten to the
 * new event id.
 *
 * After cloning, the controller flips is_template to 0, sets is_active=1
 * (per project preference), parks start_date 7 days out as a placeholder
 * (forces staff to set the real date), and writes the Drupal back-reference
 * field_parent_course plus the course image. Then redirects staff to the
 * event edit form.
 */
class ScheduleInstanceController extends ControllerBase {

  /**
   * Access check for the route + local task tab.
   *
   * Tab only appears on course nodes for users who can create CiviCRM events.
   * Non-course nodes get a "tab doesn't exist" 403 instead of a confusing
   * "Only courses can be scheduled" error after a click.
   */
  public function access(AccountInterface $account, NodeInterface $node): AccessResult {
    return AccessResult::allowedIf(
      $node->bundle() === 'course'
      && $account->hasPermission('create civicrm_event entities')
    )
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /**
   * Schedule a new instance of the given course.
   */
  public function schedule(NodeInterface $node) {
    if ($node->bundle() !== 'course') {
      $this->messenger()->addError($this->t('Only course nodes can be scheduled.'));
      return $this->redirectToCourse($node);
    }

    $template_id = $node->hasField('field_civicrm_template_id') && !$node->get('field_civicrm_template_id')->isEmpty()
      ? (int) $node->get('field_civicrm_template_id')->value
      : NULL;

    if (!$template_id) {
      $this->messenger()->addError($this->t('This course has no CiviCRM template linked. Set @field on the course before scheduling instances.', [
        '@field' => 'field_civicrm_template_id',
      ]));
      return $this->redirectToCourse($node);
    }

    \Drupal::service('civicrm')->initialize();

    try {
      // Verify the template exists and is actually a template before cloning.
      $template = \civicrm_api3('Event', 'getsingle', ['id' => $template_id]);
      if (empty($template['is_template'])) {
        $this->messenger()->addError($this->t('Linked CiviCRM event @id is not a template (is_template = 0). Update the course\'s template id.', [
          '@id' => $template_id,
        ]));
        return $this->redirectToCourse($node);
      }

      $new_event = \CRM_Event_BAO_Event::copy($template_id);
      $new_event_id = (int) $new_event->id;

      // BAO::copy returns is_template=1 and prefixes "Copy of " on the title.
      // Flip both via Event.create.
      $start = new \DateTime('+7 days');
      $start->setTime(18, 0, 0);
      $duration_minutes = 120;
      if ($node->hasField('field_stat_duration_minutes') && !$node->get('field_stat_duration_minutes')->isEmpty()) {
        $duration_minutes = max(15, (int) $node->get('field_stat_duration_minutes')->value);
      }
      $end = (clone $start)->modify('+' . $duration_minutes . ' minutes');

      // Title / dates / state go through CiviCRM API. Description and summary
      // intentionally do NOT — they're text_long base fields on the Drupal
      // civicrm_event entity, and writing them via civicrm_api3 leaves the
      // Drupal-side format = plain_text, which causes the edit form to render
      // HTML tags as visible text. We write them via the entity API in
      // writeDrupalFields() with format=full_html.
      \civicrm_api3('Event', 'create', [
        'id' => $new_event_id,
        'title' => $node->label(),
        'is_template' => 0,
        'is_active' => 1,
        'start_date' => $start->format('Y-m-d H:i:s'),
        'end_date' => $end->format('Y-m-d H:i:s'),
      ]);

      $source = $this->findMostRecentPastEvent((int) $node->id());
      $this->writeDrupalFields($new_event_id, $node, $source);

      \Drupal::logger('instructor_companion')->info('Cloned event @new from template @tpl for course @c (@title)', [
        '@new' => $new_event_id,
        '@tpl' => $template_id,
        '@c' => $node->id(),
        '@title' => $node->label(),
      ]);

      $this->messenger()->addStatus($this->t('Created new instance of @course. Reminders, fees, and profiles were carried over from template @tpl. Set the real date and instructor before publishing.', [
        '@course' => $node->label(),
        '@tpl' => $template_id,
      ]));

      return new RedirectResponse(Url::fromRoute('entity.civicrm_event.edit_form', [
        'civicrm_event' => $new_event_id,
      ])->toString());
    }
    catch (\Throwable $e) {
      \Drupal::logger('instructor_companion')->error('Schedule instance failed for course @c: @msg', [
        '@c' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not schedule instance: @msg', [
        '@msg' => $e->getMessage(),
      ]));
      return $this->redirectToCourse($node);
    }
  }

  /**
   * Set Drupal-side fields on the cloned event entity.
   *
   * Two sources feed this:
   *  - The course node (description/summary, badges, parent back-reference).
   *  - The most recent past event under the course ($source) — image and
   *    area-of-interest, since those reference media/taxonomy entities of
   *    bundles specific to events. The course's field_course_image often
   *    points at a different (or stale) media bundle, so we don't use it.
   *
   * Description and summary are written here (not via civicrm_api3) so the
   * Drupal-side text format wraps with full_html instead of plain_text —
   * otherwise the edit form renders HTML tags as visible text.
   */
  protected function writeDrupalFields(int $event_id, NodeInterface $course, ?array $source): void {
    $storage = $this->entityTypeManager()->getStorage('civicrm_event');
    $event_entity = $storage->load($event_id);
    if (!$event_entity) {
      return;
    }

    if ($course->hasField('body') && !$course->get('body')->isEmpty()) {
      $body = $course->get('body');
      if (!empty($body->value)) {
        $event_entity->set('description', [
          'value' => $body->value,
          'format' => 'full_html',
        ]);
      }
      if (!empty($body->summary)) {
        $event_entity->set('summary', $body->summary);
      }
    }

    if ($event_entity->hasField('field_parent_course')) {
      $event_entity->set('field_parent_course', ['target_id' => $course->id()]);
    }

    if ($source && !empty($source['media_id']) && $event_entity->hasField('field_civi_event_media_image')) {
      $event_entity->set('field_civi_event_media_image', [
        'target_id' => $source['media_id'],
      ]);
    }

    if ($source && !empty($source['area_interest']) && $event_entity->hasField('field_civi_event_area_interest')) {
      $event_entity->set('field_civi_event_area_interest', $source['area_interest']);
    }

    // field_course_badges on the course is auto-derived from past events by
    // CourseBadgeSync. Forward it to the new instance as a sensible default.
    if ($event_entity->hasField('field_civi_event_badges')
      && $course->hasField('field_course_badges')
      && !$course->get('field_course_badges')->isEmpty()) {
      $event_entity->set(
        'field_civi_event_badges',
        $course->get('field_course_badges')->getValue()
      );
    }

    $event_entity->save();
  }

  /**
   * Most recent active, non-template event under this course.
   *
   * Returns the data we want to carry forward — image and areas of interest
   * — both live on the Drupal civicrm_event entity (not in CiviCRM core), so
   * BAO::copy doesn't bring them.
   *
   * @return array{id:int, media_id:?int, area_interest:array}|null
   */
  protected function findMostRecentPastEvent(int $course_nid): ?array {
    $event_id = \Drupal::database()->query(
      "SELECT e.id FROM civicrm_event e
       JOIN civicrm_event__field_parent_course pc ON pc.entity_id = e.id
       WHERE pc.field_parent_course_target_id = :nid
         AND e.is_active = 1
         AND e.is_template = 0
       ORDER BY e.start_date DESC
       LIMIT 1",
      [':nid' => $course_nid]
    )->fetchField();
    if (!$event_id) {
      return NULL;
    }

    $event = $this->entityTypeManager()->getStorage('civicrm_event')->load((int) $event_id);
    if (!$event) {
      return NULL;
    }

    $media_id = NULL;
    if ($event->hasField('field_civi_event_media_image') && !$event->get('field_civi_event_media_image')->isEmpty()) {
      $media_id = (int) $event->get('field_civi_event_media_image')->target_id;
    }

    $area_interest = [];
    if ($event->hasField('field_civi_event_area_interest') && !$event->get('field_civi_event_area_interest')->isEmpty()) {
      $area_interest = $event->get('field_civi_event_area_interest')->getValue();
    }

    return [
      'id' => (int) $event_id,
      'media_id' => $media_id,
      'area_interest' => $area_interest,
    ];
  }

  protected function redirectToCourse(NodeInterface $node): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
  }

}
