<?php
/** Dump every icl_strings row with its context, to locate the OPF package rows. */
global $wpdb;
foreach ( $wpdb->get_results( "SELECT id, language, context, name, string_package_id, status, type FROM {$wpdb->prefix}icl_strings ORDER BY id", ARRAY_A ) as $r ) {
	echo wp_json_encode( $r ), "\n";
}
echo 'total=', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_strings" ), "\n";
