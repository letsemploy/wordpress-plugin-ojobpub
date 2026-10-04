<?php
/**
 * Settings and status screen.
 *
 * @package OJobPub
 */

namespace OJobPub;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → oJobPub.
 */
final class Admin {

	const PAGE        = 'ojobpub';
	const TEST_ACTION = 'ojobpub_self_test';
	const REGISTER    = 'https://sources.letsemploy.org/sources/new';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'handle_self_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OJOBPUB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the page.
	 */
	public static function menu() {
		add_options_page( __( 'oJobPub', 'ojobpub' ), __( 'oJobPub', 'ojobpub' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Plugin list link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'ojobpub' ) ) );
		return $links;
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings() {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'employer', __( 'Employer', 'ojobpub' ), array( __CLASS__, 'section_employer' ), self::PAGE );
		self::field( 'employer_name', __( 'Name', 'ojobpub' ), 'employer', array( 'required' => true ) );
		self::field( 'employer_city', __( 'Headquarters city', 'ojobpub' ), 'employer' );
		self::field( 'employer_country', __( 'Headquarters country', 'ojobpub' ), 'employer', array( 'country' => true ) );
		self::field( 'employer_industry', __( 'Industry', 'ojobpub' ), 'employer', array( 'placeholder' => __( 'e.g. Software, Healthcare', 'ojobpub' ) ) );
		self::field( 'employer_url', __( 'Website', 'ojobpub' ), 'employer', array( 'type' => 'url' ) );

		add_settings_section( 'jobs', __( 'Jobs', 'ojobpub' ), '__return_null', self::PAGE );
		add_settings_field( 'source', __( 'Data source', 'ojobpub' ), array( __CLASS__, 'render_source' ), self::PAGE, 'jobs' );
		self::field( 'default_language', __( 'Default language', 'ojobpub' ), 'jobs', array( 'language' => true ) );
		self::field( 'default_country', __( 'Default country', 'ojobpub' ), 'jobs', array( 'country' => true ) );
		add_settings_field( 'default_job_type', __( 'Default job type', 'ojobpub' ), array( __CLASS__, 'render_job_type' ), self::PAGE, 'jobs' );

		add_settings_section( 'delivery', __( 'Delivery', 'ojobpub' ), '__return_null', self::PAGE );
		add_settings_field(
			'static_file',
			__( 'Static file', 'ojobpub' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE,
			'delivery',
			array(
				'key'   => 'static_file',
				'label' => __( 'Also write the feed as a file to /.well-known/ojobpub.json (for hosts that do not pass /.well-known/ to WordPress).', 'ojobpub' ),
			)
		);
		add_settings_field(
			'json_ld',
			__( 'Google for Jobs', 'ojobpub' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE,
			'delivery',
			array(
				'key'   => 'json_ld',
				'label' => __( 'Add schema.org JobPosting data to job pages managed by this plugin.', 'ojobpub' ),
			)
		);
	}

	/**
	 * Shortcut for a text field.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Label.
	 * @param string $section Section.
	 * @param array  $args    Extra args.
	 */
	private static function field( $key, $label, $section, array $args = array() ) {
		add_settings_field(
			$key,
			$label,
			array( __CLASS__, 'render_text' ),
			self::PAGE,
			$section,
			array_merge(
				array(
					'key'       => $key,
					'label_for' => 'ojobpub-' . $key,
				),
				$args
			)
		);
	}

	/**
	 * Employer section intro.
	 */
	public static function section_employer() {
		echo '<p>' . esc_html__( 'Published in the feed as the employer of all jobs.', 'ojobpub' ) . '</p>';
	}

	/**
	 * Text input.
	 *
	 * @param array $args Args.
	 */
	public static function render_text( $args ) {
		$stored  = get_option( Settings::OPTION, array() );
		$value   = is_array( $stored ) && isset( $stored[ $args['key'] ] ) ? $stored[ $args['key'] ] : '';
		$default = Settings::defaults()[ $args['key'] ];
		$attrs   = '';
		$class   = 'regular-text';
		if ( ! empty( $args['country'] ) ) {
			$attrs = ' maxlength="2" pattern="[A-Za-z]{2}"';
			$class = 'small-text';
		} elseif ( ! empty( $args['language'] ) ) {
			$attrs = ' maxlength="2" pattern="[A-Za-z]{2}"';
			$class = 'small-text';
		}
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : $default;
		printf(
			'<input type="%1$s" id="ojobpub-%2$s" name="%3$s[%2$s]" value="%4$s" placeholder="%5$s" class="%6$s"%7$s>',
			esc_attr( isset( $args['type'] ) ? $args['type'] : 'text' ),
			esc_attr( $args['key'] ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $value ),
			esc_attr( (string) $placeholder ),
			esc_attr( $class ),
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
		);
		if ( ! empty( $args['country'] ) ) {
			echo ' <span class="description">' . esc_html__( 'ISO 3166-1 alpha-2, e.g. CH, DE, AT', 'ojobpub' ) . '</span>';
		} elseif ( ! empty( $args['language'] ) ) {
			echo ' <span class="description">' . esc_html__( 'ISO 639-1, e.g. de, fr, en', 'ojobpub' ) . '</span>';
		} elseif ( '' === $value && '' !== (string) $default ) {
			echo '<p class="description">' . esc_html__( 'Empty uses the value shown in grey.', 'ojobpub' ) . '</p>';
		}
	}

	/**
	 * Source select.
	 */
	public static function render_source() {
		$current = Settings::get( 'source' );
		printf( '<select name="%s[source]">', esc_attr( Settings::OPTION ) );
		foreach ( Plugin::sources() as $id => $source ) {
			$label = $source->label();
			if ( ! $source->is_available() ) {
				/* translators: %s: source name */
				$label = sprintf( __( '%s (not active)', 'ojobpub' ), $label );
			}
			printf( '<option value="%s"%s%s>%s</option>', esc_attr( $id ), selected( $current, $id, false ), disabled( ! $source->is_available(), true, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Where the jobs come from. Use an existing job plugin if your openings are already managed there.', 'ojobpub' ) . '</p>';
	}

	/**
	 * Job type select.
	 */
	public static function render_job_type() {
		$current = Settings::get( 'default_job_type' );
		printf( '<select name="%s[default_job_type]">', esc_attr( Settings::OPTION ) );
		foreach ( Labels::job_types() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used when a job does not state its type.', 'ojobpub' ) . '</p>';
	}

	/**
	 * Checkbox.
	 *
	 * @param array $args Args.
	 */
	public static function render_checkbox( $args ) {
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="0"><label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( $args['key'] ),
			checked( (bool) Settings::get( $args['key'] ), true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Runs the self-test and redirects back.
	 */
	public static function handle_self_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ojobpub' ), 403 );
		}
		check_admin_referer( self::TEST_ACTION );
		Feed_Service::refresh();
		set_transient( 'ojobpub_self_test_' . get_current_user_id(), Self_Test::run(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '#ojobpub-status' ) );
		exit;
	}

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$feed    = Feed_Service::get();
		$results = get_transient( 'ojobpub_self_test_' . get_current_user_id() );
		delete_transient( 'ojobpub_self_test_' . get_current_user_id() );
		$apex = (string) wp_parse_url( Endpoint::apex_url(), PHP_URL_HOST );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'oJobPub', 'ojobpub' ); ?></h1>
			<p><?php esc_html_e( 'Publishes your job openings as an open, machine-readable feed at your own domain, so job boards and search engines can pick them up without an API key.', 'ojobpub' ); ?></p>

			<h2 id="ojobpub-status"><?php esc_html_e( 'Status', 'ojobpub' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL', 'ojobpub' ); ?></th>
					<td>
						<code><?php echo esc_html( Endpoint::apex_url() ); ?></code>
						<?php if ( null !== $feed ) : ?>
							— <a href="<?php echo esc_url( Endpoint::site_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'open', 'ojobpub' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Jobs in feed', 'ojobpub' ); ?></th>
					<td>
						<?php
						if ( null === $feed ) {
							esc_html_e( 'No feed: the employer name is missing.', 'ojobpub' );
						} else {
							echo esc_html( number_format_i18n( $feed['count'] ) );
							if ( ! empty( $feed['issues'] ) ) {
								/* translators: %d: number of skipped jobs */
								echo ' — ' . esc_html( sprintf( _n( '%d job skipped because of missing data (run the check for details).', '%d jobs skipped because of missing data (run the check for details).', count( $feed['issues'] ), 'ojobpub' ), count( $feed['issues'] ) ) );
							}
						}
						?>
					</td>
				</tr>
				<?php $static = Static_File::status(); ?>
				<?php if ( Settings::get( 'static_file' ) && null !== $static ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Static file', 'ojobpub' ); ?></th>
					<td><?php echo esc_html( ! empty( $static['error'] ) ? $static['error'] : ( isset( $static['path'] ) ? $static['path'] : '' ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<?php submit_button( __( 'Check feed', 'ojobpub' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( is_array( $results ) ) : ?>
				<ul class="ojobpub-results">
					<?php
					$icons = array(
						'ok'      => 'yes-alt',
						'warning' => 'warning',
						'error'   => 'dismiss',
					);
					foreach ( $results as $r ) :
						?>
						<li><span class="dashicons dashicons-<?php echo esc_attr( $icons[ $r['level'] ] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $r['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Get found', 'ojobpub' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: apex domain, 2: link to SourceTracker */
					esc_html__( 'Register %1$s once with %2$s. It checks the feed daily and passes it on to job boards and other consumers.', 'ojobpub' ),
					'<code>' . esc_html( $apex ) . '</code>',
					'<a href="' . esc_url( self::REGISTER ) . '" target="_blank" rel="noopener">SourceTracker</a>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
