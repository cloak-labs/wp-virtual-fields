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
class WP_Post { public string $post_type = 'attachment'; public int $ID = 42; }
$primed = [];
function _prime_post_caches($ids, $terms, $meta) {
  $GLOBALS['primed'][] = [$ids, $terms, $meta];
}
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
if ($primed !== [[[42], true, true]]) throw new RuntimeException('Enrichment did not prime matching posts in a batch');
$skipped = $enrich([$post], new WP_Query(['cloakwp_virtual_fields'=>false]));
if ($calls !== 1 || $skipped[0] !== $post || isset($skipped[0]->test_value)) throw new RuntimeException('Explicit query opt-out did not skip enrichment');
if (count($primed) !== 1) throw new RuntimeException('Opted-out query primed caches');
$enrich([$post], new WP_Query(['cache_results'=>false]));
if (count($primed) !== 1) throw new RuntimeException('Cache-disabled query primed caches');
$other = clone $post; $other->post_type = 'page';
$enrich([$other], new WP_Query());
if (count($primed) !== 1) throw new RuntimeException('Unregistered post type primed caches');
echo "PASS: default enrichment preserved; explicit opt-out avoids callbacks and cloning\n";
