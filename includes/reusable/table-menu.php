<?php
/**
 * Register an admin page for creating and editing rows in a configured table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable table editor configured with a table name, key, fields, and menu.
 * Editing and saving are restricted to users with manage_options.
 */
class table_menu {

	public $table_name;
	public $key;
	public $data_columns;
	public $menu_name;
	public $parent_slug;
	public $hook_prefix;
	public $page_name;
	public $hook_save;

	public function __construct(
		$table_name = 'wp_table',
		$key = array(
			'id' => '%d',
		),
		$data_columns = array(
			'name'  => '%s',
			'value' => '%d',
		),
		$optional_additions = array(
			'menu_name' => 'Table menu',
			'parent_slug' => 'loopis-webmaster',
			'hook_prefix' => 'loopis_table',
			'page_name' => 'loopis-admin-records',
		)
	) {
		$optional_defaults = array(
			'menu_name' => 'Table menu',
			'parent_slug' => 'loopis-webmaster',
			'hook_prefix' => 'loopis_table',
			'page_name' => 'loopis-admin-records',
		);

		$optional_additions = wp_parse_args(
			$optional_additions,
			$optional_defaults
		);

		$this->table_name   = $table_name;
		$this->key          = $key;
		$this->data_columns = $data_columns;
		$this->menu_name    = $optional_additions['menu_name'];
		$this->parent_slug  = $optional_additions['parent_slug'];
		$this->hook_prefix  = $optional_additions['hook_prefix'];
		$this->page_name    = $optional_additions['page_name'];
		$this->hook_save    = $this->hook_prefix . 'save';

		add_action(
			'admin_menu',
			array( $this, 'register_admin_menu' ),
			20
		);

		add_action(
			'admin_post_'.$this->hook_save,
			array( $this, 'save_record' )
		);
	}


	private function get_key_name() {
		return (string) key( $this->key );
	}

	private function get_key_format() {
		return current( $this->key );
	}


	/** Validate and quote a database table or column identifier. */
	private function identifier( $name ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			wp_die( 'Invalid database identifier.' );
		}

		return '`' . $name . '`';
	}

	private function get_table_name() {
		return $this->identifier( $this->table_name );
	}

	private function get_key_identifier() {
		return $this->identifier( $this->get_key_name() );
	}

	/** Add the configured editor as a submenu under the configured parent. */
	public function register_admin_menu() {
		add_submenu_page(
			$this->parent_slug,
			$this->menu_name,
			$this->menu_name,
			'manage_options',
			$this->page_name,
			array( $this, 'render_table_page' )
		);
	}

	/** Render the add/edit form, records table, and configured extension hooks. */
	public function render_table_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		do_action( $this->hook_prefix . '_before_page' );

		global $wpdb;

		$table_name = $this->get_table_name();
		$key_name   = $this->get_key_name();
		$key_id     = $this->get_key_identifier();

		$edit_id = isset( $_GET['edit'] )
			? absint( $_GET['edit'] )
			: 0;

		$record = null;

		if ( $edit_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE {$key_id} = %d",
				$edit_id
			);

			$record = $wpdb->get_row( $sql, ARRAY_A );
		}

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table_name} ORDER BY {$key_id} DESC",
			ARRAY_A
		);

		?>
		<div class="wrap">

			<h1>
				<?php
				echo esc_html(
					$edit_id ? 'Edit Record' : $this->menu_name
				);
				?>
			</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Record saved successfully.</p>
				</div>
			<?php endif; ?>

			<div style="max-width: 700px; margin: 20px 0;">

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>
					<input
						type="hidden"
						name="action"
						value="<?php echo esc_attr( $this->hook_save ); ?>"
					>

					<input
						type="hidden"
						name="<?php echo esc_attr( $key_name ); ?>"
						value="<?php echo esc_attr( $record[ $key_name ] ?? 0 ); ?>"
					>

					<?php
					wp_nonce_field(
						$this->hook_save,
						'loopis_admin_nonce'
					);
					?>

					<table class="form-table" role="presentation">
						<?php foreach ( $this->data_columns as $column => $format ) : ?>
							<tr>
								<th scope="row">
									<label
										for="<?php echo esc_attr( 'loopis-admin-' . $column ); ?>"
									>
										<?php echo esc_html( ucfirst( $column ) ); ?>
									</label>
								</th>

								<td>
									<input
										type="text"
										id="<?php echo esc_attr( 'loopis-admin-' . $column ); ?>"
										name="<?php echo esc_attr( $column ); ?>"
										class="regular-text"
										value="<?php echo esc_attr( $record[ $column ] ?? '' ); ?>"
									>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>

					<?php submit_button( $edit_id ? 'Update' : 'Add' ); ?>

					<?php if ( $edit_id ) : ?>
						<a
							href="<?php echo esc_url(
				admin_url(
					'admin.php?page=' . $this->page_name
				)
							); ?>"
							class="button"
						>
							Cancel
						</a>
					<?php endif; ?>
				</form>

			</div>

			<hr>

			<h2>All Records</h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html( ucfirst( $key_name ) ); ?></th>

						<?php foreach ( $this->data_columns as $column => $format ) : ?>
							<th><?php echo esc_html( ucfirst( $column ) ); ?></th>
						<?php endforeach; ?>

						<th>Actions</th>
					</tr>
				</thead>

				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td
								colspan="<?php echo esc_attr( count( $this->data_columns ) + 2 ); ?>"
							>
								No records found.
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $row[ $key_name ] ); ?>
								</td>

								<?php foreach ( $this->data_columns as $column => $format ) : ?>
									<td>
										<?php echo esc_html( $row[ $column ] ?? '' ); ?>
									</td>
								<?php endforeach; ?>

								<td>
									<a
										href="<?php echo esc_url(
											add_query_arg(
												array(
													'page' => $this->page_name,
													'edit' => absint(
														$row[ $key_name ]
													),
												),
												admin_url( 'admin.php' )
											)
										); ?>"
									>
										Edit
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

		</div>
		<?php
		do_action( $this->hook_prefix . '_after_page' );
	}

	/** Validate and save the submitted record, then return to the editor page. */
	public function save_record() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to perform this action.' );
		}

		check_admin_referer(
			$this->hook_save,
			'loopis_admin_nonce'
		);

		global $wpdb;

		$key_name = $this->get_key_name();

		$record_id = isset( $_POST[ $key_name ] )
			? absint( $_POST[ $key_name ] )
			: 0;

		$data    = array();
		$formats = array();

		foreach ( $this->data_columns as $column => $format ) {
			if ( ! isset( $_POST[ $column ] ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $column ] );

			if ( '%d' === $format ) {
				$value = absint( $value );
			} elseif ( '%f' === $format ) {
				$value = (float) $value;
			} else {
				$value = sanitize_text_field( $value );
			}

			$data[ $column ] = $value;
			$formats[]       = $format;
		}

		$table_name = $this->table_name;

		if ( $record_id > 0 ) {
			$wpdb->update(
				$table_name,
				$data,
				array(
					$key_name => $record_id,
				),
				$formats,
				array( $this->get_key_format() )
			);
		} else {
			$wpdb->insert(
				$table_name,
				$data,
				$formats
			);
		}

		$redirect_url = add_query_arg(
			array(
				'page'    => $this->page_name,
				'updated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}