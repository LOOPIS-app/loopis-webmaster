<?php
/**
 * Render the Move User administration page for authorized super administrators.
 *
 * Lists network users and LOOPIS areas as source and destination sites, and
 * displays the result of the submitted migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Render the admin page and form.
 */
function loopis_move_user_page() {
    if ( ! is_super_admin() || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }
    global $wpdb;

    $table = $wpdb->base_prefix . 'loopis_areas';

    $site_ids = $wpdb->get_col(
        "SELECT DISTINCT blog_id
         FROM {$table}"
    );
    if ( $wpdb->last_error ) {
        ?>
        <div class="wrap">
            <h1>Move User</h1>
            <div class="notice notice-error"><p>Could not load areas from the LOOPIS areas table. Check the database configuration.</p></div>
        </div>
        <?php
        return;
    }

	?>
	<div class="wrap">
		<h1>Move User</h1>
        

		<?php if ( isset( $_GET['moved'] ) && '1' === $_GET['moved'] ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>User moved successfully.</p>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['error'] ) && 'migration_failed' === $_GET['error'] ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>The user migration failed. Some changes may already have been made.</p>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['error'] ) && 'migration_partial' === $_GET['error'] ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>The user was moved, but some posts could not be moved. Check the PHP error log before retrying.</p>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['error'] ) && 'same_site' === $_GET['error'] ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>The source and target sites must be different.</p>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['error'] ) && 'values_wrong' === $_GET['error'] ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>Please provide valid user, source blog, and target blog IDs.</p>
			</div>
		<?php endif; ?>


		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="loopis_move_user">

			<?php 
            wp_nonce_field( 'loopis_move_user_action', 'loopis_move_user_nonce' ); 
            $member_users = get_users( array( 'blog_id' => 0 ) );
            ?>
                
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="user_id">User ID</label>
					</th>
					<td>
                        <select id="user_id" name="user_id" style="max-width: 175px; margin-right: 10px;">
                            <option value="">Välj medlem</option>
                            <?php foreach ($member_users as $member_user) : ?>
                                <option value="<?php echo esc_attr($member_user->ID); ?>">
                                    <?php echo esc_html($member_user->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
					</td>
				</tr>


				<tr>
					<th scope="row">
						<label for="source_blog">Från område</label>
					</th>
					<td>
						<select name="source_blog" id="source_blog" required>
							<option value="">Välj område</option>

							<?php foreach ( $site_ids as $site_id ) : ?>
								<option value="<?php echo esc_attr( $site_id ); ?>">
									<?php echo esc_html( get_blog_option( $site_id, 'blogname' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
							
				<tr>
					<th scope="row">
						<label for="target_blog">Till område</label>
					</th>
					<td>
						<select name="target_blog" id="target_blog" required>
							<option value="">Välj område</option>
							
							<?php foreach ( $site_ids as $site_id ) : ?>
								<option value="<?php echo esc_attr( $site_id ); ?>">
									 <?php echo esc_html( get_blog_option( $site_id, 'blogname' )); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

			</table>

			<?php submit_button( 'Move User' ); ?>
		</form>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			const sourceSelect = document.getElementById('source_blog');
			const targetSelect = document.getElementById('target_blog');

			function preventSameSite() {
				const sourceValue = sourceSelect.value;
				const targetValue = targetSelect.value;

				Array.from(targetSelect.options).forEach(function (option) {
					option.disabled = option.value !== '' && option.value === sourceValue;
				});

				Array.from(sourceSelect.options).forEach(function (option) {
					option.disabled = option.value !== '' && option.value === targetValue;
				});

				if (sourceValue && sourceValue === targetValue) {
					targetSelect.value = '';
				}
			}

			sourceSelect.addEventListener('change', preventSameSite);
			targetSelect.addEventListener('change', preventSameSite);

			preventSameSite();
		});
		</script>
	</div>
	<?php
}
