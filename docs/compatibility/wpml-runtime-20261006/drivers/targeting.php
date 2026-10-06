<?php
/**
 * Language-specific targeting: the ES product is added to the group's product
 * rule terms so the ES page can be served with the package translations, while
 * the EN-only rule (which needs wpml_object_id remapping) stays as it was.
 */
global $wpdb;
$ids = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) $ids['group_id'];
$post = get_post( $group_id );
$data = json_decode( (string) $post->post_content, true );
foreach ( $data['rule_groups'] as $gi => $group_rule ) {
	foreach ( $group_rule['rules'] as $ri => $rule ) {
		if ( 'product' === ( $rule['subject'] ?? '' ) ) {
			$terms = array_map( 'strval', (array) $rule['terms'] );
			$ids_to_add = [ (string) $ids['es_product_id'] ];
			foreach ( $ids_to_add as $id ) {
				if ( ! in_array( $id, $terms, true ) ) { $terms[] = $id; }
			}
			$data['rule_groups'][ $gi ]['rules'][ $ri ]['terms'] = $terms;
		}
	}
}
$new_id = \OPF\Service\FieldGroups::save( $group_id, $data, [ 'title' => $post->post_title ] );
printf( "group_id=%d resaved=%d product_terms=%s\n", $group_id, $new_id, wp_json_encode( $data['rule_groups'][0]['rules'][0]['terms'] ) );
