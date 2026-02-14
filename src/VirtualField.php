<?php

declare(strict_types=1);

namespace CloakWP\VirtualFields;

use CloakWP\Core\Utils;
use InvalidArgumentException;

class VirtualField
{
  protected string $name;
  protected $value;
  protected array $excludedFrom = [];
  protected int $recursizeIterationCount = 0;
  /** Max recursion depth for this field (add when count < this). Default 2 so most fields can go 2 layers. */
  protected int $maxRecursiveDepth = 2;
  /**
   * Per-field runtime state for the duration of a request.
   * Keys are post IDs; values are `"processing"` or `"processed"`.
   *
   * This is intended for user-land recursion prevention (e.g. dropping a relationship
   * field that points to a post ID currently being processed).
   */
  protected array $state = [];

  public function __construct(string $field_name)
  {
    $this->name = $field_name; // todo: sanitize $field_name to ensure it's a valid format for a field name
  }

  /** Set how many recursive layers this field is allowed (stops infinite loops from e.g. ACF bidirectional relationships). */
  public function maxRecursiveDepth(int $depth): static
  {
    $this->maxRecursiveDepth = $depth;
    return $this;
  }

  public function getMaxRecursiveDepth(): int
  {
    return $this->maxRecursiveDepth;
  }

  public static function make(string $field_name): static
  {
    return new static($field_name);
  }

  /** Either provide a static value, or a callback function that receives 
   *  a WP_Post object and returns the value.
   */
  public function value(mixed $value): static
  {
    if (is_callable($value)) {
      $this->value = function ($post) use ($value) {
        $postObject = Utils::asPostObject($post);
        $postId = $postObject?->ID;

        if (is_int($postId) && $postId > 0) {
          $this->state[$postId] = 'processing';
        }

        $this->recursizeIterationCount++;
        try {
          // Preserve the original argument shape for user-land callbacks.
          return $this->callValueCallback($value, $post, $this->state);
        } finally {
          if (is_int($postId) && $postId > 0) {
            $this->state[$postId] = 'processed';
          }
          $this->recursizeIterationCount--;
        }
      };
    } else {
      $this->value = $value;
    }

    return $this;
  }

  /**
   * Public read-only access to this field's state.
   */
  public function getState(): array
  {
    return $this->state;
  }

  /**
   * Calls the user's callback in a backwards-compatible way:
   * - If callback expects 1 arg: ($args)
   * - If callback expects 2+ args: ($args, $state)
   */
  protected function callValueCallback(callable $cb, mixed $args, array $state): mixed
  {
    try {
      if (is_array($cb)) {
        $ref = new \ReflectionMethod($cb[0], $cb[1]);
      } elseif (is_string($cb) && str_contains($cb, '::')) {
        [$class, $method] = explode('::', $cb, 2);
        $ref = new \ReflectionMethod($class, $method);
      } else {
        $ref = new \ReflectionFunction(\Closure::fromCallable($cb));
      }
      $paramCount = $ref->getNumberOfParameters();
    } catch (\Throwable) {
      // If reflection fails for any reason, fall back to the original 1-arg call shape.
      $paramCount = 1;
    }

    if ($paramCount >= 2) {
      return $cb($args, $state);
    }

    return $cb($args);
  }

  /** For internal use only. */
  public function _resetRecursiveIterationCount()
  {
    $this->recursizeIterationCount = 0;
  }
  /** For internal use only. */
  public function _getRecursiveIterationCount(): int
  {
    return $this->recursizeIterationCount;
  }

  public function excludeFrom(array $excludeFrom): static
  {
    $allowedValues = ['rest', 'core', 'rest_revisions', 'acf'];
    $invalidValues = array_diff($excludeFrom, $allowedValues);

    if (!empty($invalidValues)) {
      $allowedValuesList = implode(', ', $allowedValues);
      $invalidValuesList = implode(', ', $invalidValues);
      throw new InvalidArgumentException("Invalid value(s) in the 'excludeFrom' array: $invalidValuesList. Allowed values are: $allowedValuesList");
    }

    $this->excludedFrom = $excludeFrom;
    return $this;
  }

  public function getSettings(): array
  {
    return [
      'name' => $this->name,
      'excludedFrom' => $this->excludedFrom,
      'maxRecursiveDepth' => $this->maxRecursiveDepth,
    ];
  }

  public function getValue($args)
  {
    $v = $this->value;
    return is_callable($v) ? $v($args) : $v;
  }
}