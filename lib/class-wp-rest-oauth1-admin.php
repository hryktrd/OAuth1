<?php

class WP_REST_OAuth1_Admin {
	const BASE_SLUG = 'rest-oauth1-apps';

	/**
	 * Register the admin page
	 */
	public static function register() {
		/**
		 * Include anything we need that relies on admin classes/functions
		 */
		include_once dirname( __FILE__ ) . '/class-wp-rest-oauth1-listtable.php';

		$hook = add_users_page(
			// Page title
			__( 'Registered OAuth Applications', 'rest_oauth1' ),

			// Menu title
			_x( 'Applications', 'menu title', 'rest_oauth1' ),

			// Capability
			'list_users',

			// Menu slug
			self::BASE_SLUG,

			// Callback
			array( get_class(), 'dispatch' )
		);

		add_action( 'load-' . $hook, array( get_class(), 'load' ) );
	}

	/**
	 * Get the URL for an admin page.
	 *
	 * @param array|string $params Map of parameter key => value, or wp_parse_args string.
	 * @return string Requested URL.
	 */
	protected static function get_url( $params = array() ) {
		$url = admin_url( 'users.php' );
		$params = array( 'page' => self::BASE_SLUG ) + wp_parse_args( $params );
		return add_query_arg( urlencode_deep( $params ), $url );
	}

	/**
	 * Get the current page action.
	 *
	 * @return string One of 'add', 'edit', 'delete', or '' for default (list)
	 */
	protected static function current_action() {
		return isset( $_GET['action'] ) ? $_GET['action'] : '';
	}

	/**
	 * Load data for our page.
	 */
	public static function load() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
				return self::render_edit_page();

			case 'delete':
				return self::handle_delete();

			default:
				global $wp_list_table;

				$wp_list_table = new WP_REST_OAuth1_ListTable();

				$wp_list_table->prepare_items();

				return;
		}

	}

	public static function dispatch() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
			case 'delete':
				return;

			default:
				return self::render();
		}
	}

	/**
	 * Render the list page.
	 */
	public static function render() {
		global $wp_list_table;

		?>
		<div class="wrap">
			<h2>
				<?php
				esc_html_e( 'Registered Applications', 'rest_oauth1' );

				if ( current_user_can( 'create_users' ) ): ?>
					<a href="<?php echo esc_url( self::get_url( 'action=add' ) ) ?>"
						class="add-new-h2"><?php echo esc_html_x( 'Add New', 'application', 'rest_oauth1' ); ?></a>
				<?php
				endif;
				?>
			</h2>
			<?php
			if ( ! empty( $_GET['deleted'] ) ) {
				echo '<div id="message" class="updated"><p>' . esc_html__( 'Deleted application.', 'rest_oauth1' ) . '</p></div>';
			}
			?>

			<?php $wp_list_table->views(); ?>

			<form action="" method="get">

				<?php $wp_list_table->search_box( __( 'Search Applications', 'rest_oauth1' ), 'rest_oauth1' ); ?>

				<?php $wp_list_table->display(); ?>

			</form>

			<br class="clear" />

		</div>
		<?php
	}

	protected static function validate_parameters( $params ) {
		$valid = array();

		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_name', __( 'Consumer name is required', 'rest_oauth1' ) );
		}
		$valid['name'] = wp_filter_post_kses( $params['name'] );

		if ( empty( $params['description'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_description', __( 'Consumer description is required', 'rest_oauth1' ) );
		}
		$valid['description'] = wp_filter_post_kses( $params['description'] );

		if ( empty( $params['callback'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_description', __( 'Consumer callback is required and must be a valid URL.', 'rest_oauth1' ) );
		}
		if ( ! empty( $params['callback'] ) ) {
			$valid['callback'] = $params['callback'];
		}

		return $valid;
	}

	/**
	 * Handle submission of the add page
	 *
	 * @return array|null List of errors. Issues a redirect and exits on success.
	 */
	protected static function handle_edit_submit( $consumer ) {
		$messages = array();
		if ( empty( $consumer ) ) {
			$did_action = 'add';
			check_admin_referer( 'rest-oauth1-add' );
		}
		else {
			$did_action = 'edit';
			check_admin_referer( 'rest-oauth1-edit-' . $consumer->ID );
		}

		// Check that the parameters are correct first
		$params = self::validate_parameters( wp_unslash( $_POST ) );
		if ( is_wp_error( $params ) ) {
			$messages[] = $params->get_error_message();
			return $messages;
		}

		if ( empty( $consumer ) ) {
			$authenticator = new WP_REST_OAuth1();

			// Create the consumer
			$data = array(
				'name' => $params['name'],
				'description' => $params['description'],
				'meta' => array(
					'callback' => $params['callback'],
				),
			);
			$consumer = $result = $authenticator->add_consumer( $data );
		}
		else {
			// Update the existing consumer post
			$data = array(
				'ID' => $consumer->ID,
				'post_title' => $params['name'],
				'post_content' => $params['description'],
			);
			$result = wp_update_post( $data, true );
			update_post_meta( $consumer->ID, 'callback', wp_slash( $params['callback'] ) );
		}

		if ( is_wp_error( $result ) ) {
			$messages[] = $result->get_error_message();

			return $messages;
		}

		// Success, redirect to alias page
		$location = self::get_url(
			array(
				'action'     => 'edit',
				'id'         => $consumer->ID,
				'did_action' => $did_action,
			)
		);
		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * Output alias editing page
	 */
	public static function render_edit_page() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( __( 'You do not have permission to access this page.' ) );
		}

		// Are we editing?
		$consumer = null;
		$form_action = self::get_url('action=add');
		if ( ! empty( $_REQUEST['id'] ) ) {
			$id = absint( $_REQUEST['id'] );
			$consumer = get_post( $id );
			if ( is_wp_error( $consumer ) || empty( $consumer ) ) {
				wp_die( __( 'Invalid consumer ID.' ) );
			}

			$form_action = self::get_url( array( 'action' => 'edit', 'id' => $id ) );
		}

		// Handle form submission
		$messages = array();
		if ( ! empty( $_POST['submit'] ) ) {
			$messages = self::handle_edit_submit( $consumer );
		}
		if ( ! empty( $_GET['did_action'] ) ) {
			if ( $_GET['did_action'] === 'edit' ) {
				$messages[] = __( 'Updated application.', 'rest_oauth1' );
			} else {
				$messages[] = __( 'Successfully created application.', 'rest_oauth1' );
			}
		}

		$data = array();

		if ( empty( $consumer ) || ! empty( $_POST['_wpnonce'] ) ) {
			foreach ( array( 'name', 'description', 'callback' ) as $key ) {
				$data[ $key ] = empty( $_POST[ $key ] ) ? '' : wp_unslash( $_POST[ $key ] );
			}
		}
		else {
			$data['name'] = $consumer->post_title;
			$data['description'] = $consumer->post_content;
			$data['callback'] = $consumer->callback;
		}

		// Header time!
		global $title, $parent_file, $submenu_file;
		$title = $consumer ? __( 'Edit Application', 'rest_oauth1' ) : __( 'Add Application', 'rest_oauth1' );
		$parent_file = 'users.php';
		$submenu_file = self::BASE_SLUG;

		include( ABSPATH . 'wp-admin/admin-header.php' );
	?>

	<div class="wrap">
		<h2 id="edit-site"><?php echo esc_html( $title ) ?></h2>

		<?php
		if ( ! empty( $messages ) ) {
			foreach ( $messages as $msg )
				echo '<div id="message" class="updated"><p>' . esc_html( $msg ) . '</p></div>';
		}
		?>

		<form method="post" action="<?php echo esc_url( $form_action ) ?>">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="oauth-name"><?php echo esc_html_x( 'Consumer Name', 'field name' ) ?></label>
					</th>
					<td>
						<input type="text" class="regular-text"
							name="name" id="oauth-name"
							value="<?php echo esc_attr( $data['name'] ) ?>" />
						<p class="description"><?php echo esc_html( 'This is shown to users during authorization and in their profile.' ) ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oauth-description"><?php echo esc_html_x( 'Description', 'field name' ) ?></label>
					</th>
					<td>
						<textarea class="regular-text" name="description" id="oauth-description"
							cols="30" rows="5" style="width: 500px"><?php echo esc_textarea( $data['description'] ) ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oauth-callback"><?php echo esc_html_x( 'Callback', 'field name' ) ?></label>
					</th>
					<td>
						<input type="text" class="regular-text"
							name="callback" id="oauth-callback"
							value="<?php echo esc_attr( $data['callback'] ) ?>" />
						<p class="description"><?php echo esc_html( "Your application's callback URL. The callback passed with the request token must match the scheme, host, port, and path of this URL." ) ?></p>
					</td>
				</tr>

				<?php if ( ! empty( $consumer ) ): ?>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Client Key' ) ?>
						</th>
						<td>
							<code><?php echo esc_html( $consumer->key ) ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Client Secret' ) ?>
						</th>
						<td>
							<code><?php echo esc_html( $consumer->secret ) ?></code>
						</td>
					</tr>
				<?php endif ?>
			</table>

			<?php

			if ( empty( $consumer ) ) {
				wp_nonce_field( 'rest-oauth1-add' );
				submit_button( __( 'Add Consumer' ) );
			}
			else {
				echo '<input type="hidden" name="id" value="' . esc_attr( $consumer->ID ) . '" />';
				wp_nonce_field( 'rest-oauth1-edit-' . $consumer->ID );
				submit_button( __( 'Save Consumer' ) );
			}

			?>
		</form>
	</div>

	<?php

		include(ABSPATH . 'wp-admin/admin-footer.php');
	}

	public static function handle_delete() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = $_GET['id'];
		check_admin_referer( 'rest-oauth1-delete:' . $id );

		if ( ! rest_delete_client( $id ) ) {
			$message = 'Invalid consumer ID';
			wp_die( $message );
			return;
		}

		wp_redirect( self::get_url( 'deleted=1' ) );
		exit;
	}
}
