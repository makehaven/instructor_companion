<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Breadcrumbs for the instructor pages under /instructor/.
 *
 * The path-based builder made /instructor (the "Instructor Interest"
 * recruiting page) the parent of every instructor page, so the dashboard,
 * setup list and post-class pages all pointed back to "Instructor Interest".
 * Their real parent is the dashboard (the Education console for staff).
 */
class InstructorBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  protected const ROUTES = [
    'instructor_companion.dashboard',
    'instructor_companion.setup',
    'instructor_companion.class_checkout',
    'instructor_companion.post_event_hub',
    'instructor_companion.attendance',
    'instructor_companion.class_email',
  ];

  public function __construct(protected AccountInterface $currentUser) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, $cacheability = NULL) {
    return in_array($route_match->getRouteName(), self::ROUTES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route', 'user.permissions']);
    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    $route = $route_match->getRouteName();
    if ($route !== 'instructor_companion.dashboard') {
      $staff = $this->currentUser->hasPermission('access education console')
        && !in_array('instructor', $this->currentUser->getRoles(), TRUE);
      $links[] = $staff
        ? Link::createFromRoute($this->t('Education'), 'instructor_companion.education_console')
        : Link::createFromRoute($this->t('Instructor Dashboard'), 'instructor_companion.dashboard');
    }
    return $breadcrumb->setLinks($links);
  }

}
