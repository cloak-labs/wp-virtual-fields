<?php
// Standalone regression test: php tests/QueryOptOutTest.php
$filters = [];
function add_filter($name, $callback, ...$args) { $GLOBALS['filters'][$name][] = $callback; }
function add_action(...$args) {}
class WP_Query {
  public function __construct(private array $args = []) {}
  public function get($name) { return $this->args[$name] ?? ''; }
}
#[AllowDynamicProperties]
class WP_Post { public string $post_type = 'attachment'; }
require __DIR__ . '/../src/register.php';
$calls = 0;
$field = new class($calls) {
  public function __construct(private int &$calls) {}
  public function getSettings() { return ['name'=>'test_value','excludedFrom'=>[]]; }
  public function getMaxRecursiveDepth() { return 2; }
  public function _getRecursiveIterationCount() { return 0; }
  public function getValue($post) { $this->calls++; return 'enriched'; }
};
register_virtual_fields('attachment', [$field]);
$enrich = $filters['the_posts'][0];
$post = new WP_Post();
$normal = $enrich([$post], new WP_Query());
if ($calls !== 1 || $normal[0]->test_value !== 'enriched' || isset($post->test_value)) throw new RuntimeException('Default enrichment or cloning changed');
$skipped = $enrich([$post], new WP_Query(['cloakwp_virtual_fields'=>false]));
if ($calls !== 1 || $skipped[0] !== $post || isset($skipped[0]->test_value)) throw new RuntimeException('Explicit query opt-out did not skip enrichment');
echo "PASS: default enrichment preserved; explicit opt-out avoids callbacks and cloning\n";
