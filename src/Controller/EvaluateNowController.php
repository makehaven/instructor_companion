<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\PostEventStatusService;

/**
 * One evaluation link for the classroom wall: /evaluate.
 *
 * A QR code on the wall cannot know which class is in the room, so this
 * looks it up: the class (or session) that started in the last few hours,
 * or is about to. One match goes straight to that class's evaluation; more
 * than one gets a short list to pick from. JR 2026-10-29: people rarely fill
 * in the emailed evaluation after they leave.
 */
class EvaluateNowController extends ControllerBase {

  /**
   * A class counts as "in the room" from this long before its start…
   */
  protected const BEFORE_MINUTES = 30;

  /**
   * …until this long after it (long classes, people leaving late).
   */
  protected const AFTER_HOURS = 5;

  /**
   * The page behind the wall QR code.
   */
  public function build(): array|TrustedRedirectResponse {
    \Drupal::service('page_cache_kill_switch')->trigger();
    $classes = $this->classesNow();

    if (count($classes) === 1) {
      $class = reset($classes);
      $response = new TrustedRedirectResponse($class['url'], 302);
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    $build = ['#cache' => ['max-age' => 0]];
    if (!$classes) {
      $build['none'] = [
        '#markup' => '<p>' . $this->t('No class is running right now. If you took one recently, the evaluation link is in the email we sent as class ended, or write to <a href="mailto:education@makehaven.org">education@makehaven.org</a>.') . '</p>',
      ];
      return $build;
    }
    $items = [];
    foreach ($classes as $class) {
      $items[] = [
        '#type' => 'link',
        '#title' => $class['title'],
        '#url' => Url::fromUri($class['url']),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    $build['intro'] = ['#markup' => '<p>' . $this->t('Which class are you in?') . '</p>'];
    $build['list'] = ['#theme' => 'item_list', '#items' => $items];
    return $build;
  }

  /**
   * A printable sign with the /evaluate QR code, for the classroom wall.
   */
  public function poster(): array {
    $url = Url::fromRoute('instructor_companion.evaluate_now', [], ['absolute' => TRUE])->toString();
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ic-eval-poster'], 'style' => 'text-align:center;padding:2rem 1rem'],
      '#cache' => ['contexts' => ['url.site']],
      'head' => [
        '#markup' => '<h2 style="font-size:2.4rem;margin:0 0 .5rem">' . $this->t('How was class?') . '</h2>'
        . '<p style="font-size:1.3rem;margin:0 0 1.5rem">' . $this->t('Scan to tell us. It takes a minute, and your instructor reads every answer.') . '</p>',
      ],
      'qr' => self::qrImage($url, 360),
      'foot' => [
        '#markup' => '<p style="margin-top:1rem;font-size:1.1rem">' . htmlspecialchars(preg_replace('#^https?://(www\.)?#', '', $url)) . '</p>'
        . '<p style="margin-top:2rem"><small>' . $this->t('Print this page (Ctrl+P) and put it on the classroom wall or screen.') . '</small></p>',
      ],
    ];
  }

  /**
   * A QR code image for $url (inline SVG data URI) as a render element.
   *
   * An element rather than markup: #markup's filter strips data: URIs.
   */
  public static function qrImage(string $url, int $size = 180): array {
    $options = new QROptions([
      'outputType' => QROutputInterface::MARKUP_SVG,
      'outputBase64' => TRUE,
    ]);
    return [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'src' => (new QRCode($options))->render($url),
        'width' => $size,
        'height' => $size,
        'alt' => (string) t('QR code for @url', ['@url' => $url]),
        'class' => ['ic-qr'],
      ],
    ];
  }

  /**
   * Classes in the room now: [event_id => ['title' => ..., 'url' => ...]].
   */
  protected function classesNow(): array {
    $now = \Drupal::time()->getRequestTime();
    $types = PostEventStatusService::closeoutEventTypes();
    $rows = \Drupal::service('instructor_companion.sessions')->sessionsStartingBetween(
      date('Y-m-d H:i:s', $now - self::AFTER_HOURS * 3600),
      date('Y-m-d H:i:s', $now + self::BEFORE_MINUTES * 60),
      function ($q) use ($types) {
        $q->addField('e', 'title', 'title');
        $q->addField('e', 'event_type_id', 'event_type_id');
        $q->condition('e.is_active', 1);
        $q->condition('e.is_template', 0);
        if ($types) {
          $q->condition('e.event_type_id', $types, 'IN');
        }
      }
    );
    $evaluation = \Drupal::service('instructor_companion.session_evaluation');
    $out = [];
    foreach ($rows as $row) {
      $id = (int) $row['event_id'];
      $out[$id] ??= [
        'title' => (string) $row['title'],
        'url' => $evaluation->evaluationUrl($id, (int) $row['event_type_id']),
      ];
    }
    return $out;
  }

}
