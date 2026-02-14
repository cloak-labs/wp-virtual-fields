<?php

declare(strict_types=1);

namespace CloakWP\VirtualFields;

use InvalidArgumentException;

class VirtualField
{
  protected string $name;
  protected $value;
  protected array $excludedFrom = [];
  protected int $recursizeIterationCount = 0;
  /** Max recursion depth for this field (add when count < this). Default 2 so most fields can go 2 layers. */
  protected int $maxRecursiveDepth = 2;

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
      $this->value = function ($args) use ($value) {
        $this->recursizeIterationCount++;
        try {
          return $value($args);
        } finally {
          $this->recursizeIterationCount--;
        }
      };
    } else {
      $this->value = $value;
    }

    return $this;
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