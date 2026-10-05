<?php
/** Standalone cache-floor probe; optional second argument loads real WP cache source. */
$mode = $argv[1] ?? 'missing';
$wp_source = $argv[2] ?? '';
define( 'ABSPATH', $wp_source ? rtrim( $wp_source, '/' ) . '/' : dirname( __DIR__, 2 ) . '/' );
define( 'OPF_DIR', dirname( __DIR__, 2 ) . '/' );
require OPF_DIR . 'includes/Autoloader.php';

class WP_Post {
	public $ID;
	public $post_title;
	public $post_content;
	public function __construct( $id, $title, $content ) {
		$this->ID = $id;
		$this->post_title = $title;
		$this->post_content = $content;
	}
}
class WC_Product {
	public function get_parent_id() { return 0; }
	public function get_id() { return 42; }
}
function get_posts( $args = [] ) {
	$GLOBALS['cache_probe_post_reads']++;
	return array_values( $GLOBALS['cache_probe_posts'] );
}
function is_user_logged_in() { return false; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wc_get_product_term_ids( $id, $taxonomy ) { return []; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_slash( $value ) { return $value; }
function is_wp_error( $value ) { return false; }
function get_post_field( $field, $id ) { return $GLOBALS['cache_probe_posts'][ $id ]->$field ?? ''; }
function wp_insert_post( $fields, $return_error = false ) {
	$id = $fields['ID'] ?: count( $GLOBALS['cache_probe_posts'] ) + 1;
	$GLOBALS['cache_probe_posts'][ $id ] = new WP_Post( $id, $fields['post_title'], $fields['post_content'] );
	return $id;
}
function __( $text, $domain = '' ) { return $text; }

$GLOBALS['cache_probe_cache'] = [];
$GLOBALS['cache_probe_cache_reads'] = 0;
$GLOBALS['cache_probe_cache_writes'] = 0;
$GLOBALS['cache_probe_flush_groups'] = [];
$GLOBALS['cache_probe_post_reads'] = 0;
if ( $wp_source ) {
	define( 'WPINC', 'wp-includes' );
	function is_multisite() { return false; }
	require ABSPATH . WPINC . '/cache.php';
	wp_cache_init();
} else {
	function wp_cache_get( $key, $group = '' ) {
		$GLOBALS['cache_probe_cache_reads']++;
		return $GLOBALS['cache_probe_cache'][ $group ][ $key ] ?? false;
	}
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		$GLOBALS['cache_probe_cache_writes']++;
		$GLOBALS['cache_probe_cache'][ $group ][ $key ] = $value;
		return true;
	}
	function wp_cache_flush() { throw new RuntimeException( 'A global cache flush is forbidden.' ); }
	if ( 'missing' !== $mode ) {
		function wp_cache_flush_group( $group ) {
			if ( 'supported' !== $GLOBALS['cache_probe_mode'] ) {
				throw new RuntimeException( 'Unsupported group flush was called.' );
			}
			$GLOBALS['cache_probe_flush_groups'][] = $group;
			$GLOBALS['cache_probe_cache'][ $group ] = [];
			return true;
		}
	}
	if ( ! in_array( $mode, [ 'missing', 'legacy' ], true ) ) {
		function wp_cache_supports( $feature ) {
			return 'flush_group' === $feature && 'supported' === $GLOBALS['cache_probe_mode'];
		}
	}
}
$GLOBALS['cache_probe_mode'] = $mode;

$data = [ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ] ] ];
$GLOBALS['cache_probe_posts'] = [ 1 => new WP_Post( 1, 'Current group', json_encode( $data ) ) ];
$product = new WC_Product();
$cache_key = Closure::bind(
	static function () {
		return OPF\Service\FieldGroups::cache_key_for_viewer( 42, [ 'logged_in' => false, 'roles' => [], 'language' => 'default' ], '' );
	},
	null,
	OPF\Service\FieldGroups::class
)();
$stale = [ [ 'title' => 'Legacy stale group' ] ];
wp_cache_set( $cache_key, $stale, 'opf_groups_for_product' );
wp_cache_set( 'keep', 'untouched', 'foreign_plugin' );
$GLOBALS['cache_probe_cache_reads'] = $GLOBALS['cache_probe_cache_writes'] = 0;

$before = array_column( OPF\Service\FieldGroups::for_product( $product ), 'title' );
// Repeated lookups reuse request-local source groups even without persistent caching.
OPF\Service\FieldGroups::for_product( $product );
$reads_before_save = $GLOBALS['cache_probe_post_reads'];
OPF\Service\FieldGroups::save( 1, $data, [ 'title' => 'Saved group' ] );
$saved = array_column( OPF\Service\FieldGroups::for_product( $product ), 'title' );
// Duplication uses this exact normalize/duplicate/save path in handle_duplicate().
$duplicate = OPF\Engine\FieldGroup::duplicate( $data );
OPF\Service\FieldGroups::save( 0, $duplicate['group'], [ 'title' => 'Copied group' ] );
$copied = array_column( OPF\Service\FieldGroups::for_product( $product ), 'title' );
$runtime_reads = $GLOBALS['cache_probe_cache_reads'];
$runtime_writes = $GLOBALS['cache_probe_cache_writes'];
$cached = wp_cache_get( $cache_key, 'opf_groups_for_product' );
$foreign = wp_cache_get( 'keep', 'foreign_plugin' );
echo json_encode( [
	'mode' => $mode,
	'group_flush_api' => function_exists( 'wp_cache_flush_group' ),
	'supports_api' => function_exists( 'wp_cache_supports' ),
	'before' => $before,
	'saved' => $saved,
	'copied' => $copied,
	'foreign' => $foreign,
	'legacy_entry_retained' => $stale === $cached,
	'reads_before_save' => $reads_before_save,
	'post_reads' => $GLOBALS['cache_probe_post_reads'],
	'cache_reads' => $runtime_reads,
	'cache_writes' => $runtime_writes,
	'flushed_groups' => $GLOBALS['cache_probe_flush_groups'],
] ), "\n";
