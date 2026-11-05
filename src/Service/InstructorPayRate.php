<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

/**
 * Text helpers for what applicants are told about pay and the first meeting.
 */
final class InstructorPayRate {

  /**
   * Published pages that state base instructor pay.
   *
   * Instructor Interest, the FAQ, and the team-building instructor listing.
   */
  public const PAGES = [14366, 5045, 28615];

  /**
   * Puts the current rate in place of a dollar figure stated as hourly pay.
   *
   * Other dollar amounts on these pages, such as class prices, are left alone.
   */
  public static function apply(string $html, float $rate): string {
    if ($rate <= 0) {
      return $html;
    }
    $figure = '$' . (floor($rate) === $rate ? (string) (int) $rate : number_format($rate, 2, '.', ''));
    return (string) preg_replace_callback('~\$\d+(?:\.\d{1,2})?(?=\s*/\s*hr\b)|(?<=hourly rate of )\$\d+(?:\.\d{1,2})?~i', static fn(): string => $figure, $html);
  }

  /**
   * Where an applicant books their intro meeting.
   *
   * Name and email are filled in so the booking matches the application.
   * Without a link set, they write to the education team instead.
   */
  public static function bookingUrl(string $base, string $name, string $email): string {
    $base = trim($base);
    if ($base === '') {
      return 'mailto:education@makehaven.org';
    }
    $query = array_filter(['name' => trim($name), 'email' => trim($email)]);
    if (!$query) {
      return $base;
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

}
