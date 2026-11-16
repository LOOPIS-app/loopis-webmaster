<?php
/**
 * QR-code administration page.
 *
 * The reusable table editor lists, adds, and edits network-wide QR codes.
 * This file adds visit counts from the network-wide QR visit log below it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render the visit statistics below the reusable QR-code table editor. */
function loopis_qr_codes_render_stats() {
	global $wpdb;

	$codes  = $wpdb->base_prefix . 'loopis_qr_codes';
	$visits = $wpdb->base_prefix . 'loopis_qr_visits';
	$rows   = $wpdb->get_results(
		"SELECT codes.`name` AS 'name', COUNT(*) AS 'count', codes.`uid` AS 'uid' FROM {$codes} codes INNER JOIN {$visits} visit ON visit.`name`=codes.`name` GROUP BY codes.`name`;",
		ARRAY_A
	);
	?>
	<h1>Statistik</h1>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>Name</th>
				<th>Number of visits</th>
				<th>QR download</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="3">No records found.</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['count'] ); ?></td>
						<td><button type="button"></button></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
}
