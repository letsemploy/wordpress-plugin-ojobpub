<?php
/**
 * Native job post type, taxonomies, meta fields and editor meta box.
 *
 * @package OJobPub
 */

namespace OJobPub;

use OJobPub\Feed\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own job post type, used when no other job plugin provides the data.
 */
final class PostType {

	const POST_TYPE    = 'ojobpub_job';
	const TAX_CATEGORY = 'ojobpub_job_category';
	const TAX_TAG      = 'ojobpub_job_tag';
	const META_PREFIX  = '_ojobpub_';
	const NONCE        = 'ojobpub_job_meta';

	/**
	 * Scalar meta fields: key => type.
	 */
	const FIELDS = array(
		'job_type'         => 'string',
		'language'         => 'string',
		'work_type'        => 'string',
		'experience_level' => 'string',
		'apply_before'     => 'string',
		'start_date'       => 'string',
		'end_date'         => 'string',
		'reference_id'     => 'string',
		'workload_min'     => 'number',
		'workload_max'     => 'number',
		'salary_min'       => 'number',
		'salary_max'       => 'number',
		'salary_currency'  => 'string',
		'salary_interval'  => 'string',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Registers post type, taxonomies and meta. Must also run on activation before flushing rewrites.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Jobs', 'ojobpub' ),
					'singular_name' => __( 'Job', 'ojobpub' ),
					'add_new_item'  => __( 'Add New Job', 'ojobpub' ),
					'edit_item'     => __( 'Edit Job', 'ojobpub' ),
					'all_items'     => __( 'All Jobs', 'ojobpub' ),
					'menu_name'     => __( 'Jobs', 'ojobpub' ),
				),
				'public'       => true,
				// Hidden when another job plugin is the source, to avoid maintaining jobs twice.
				'show_in_menu' => 'native' === Plugin::source()->id(),
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-businessperson',
				'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields' ),
				'rewrite'      => array(
					/**
					 * Filters the URL slug of job pages.
					 *
					 * @param string $slug Default "jobs".
					 */
					'slug'       => apply_filters( 'ojobpub_job_slug', 'jobs' ),
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX_CATEGORY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Job Categories', 'ojobpub' ),
					'singular_name' => __( 'Job Category', 'ojobpub' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'job-category' ),
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Job Tags', 'ojobpub' ),
					'singular_name' => __( 'Job Tag', 'ojobpub' ),
				),
				'description'  => __( 'Skills and technologies. Max. 16 per job, each up to 28 characters.', 'ojobpub' ),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'job-tag' ),
			)
		);

		foreach ( self::FIELDS as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				self::META_PREFIX . $key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_' . ( 'number' === $type ? 'number' : 'string' ) ),
					'auth_callback'     => array( __CLASS__, 'can_edit' ),
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_PREFIX . 'locations',
			array(
				'type'              => 'array',
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_locations' ),
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'city'    => array( 'type' => 'string' ),
								'country' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Meta auth callback.
	 *
	 * @param bool   $allowed  Unused.
	 * @param string $meta_key Unused.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function can_edit( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Sanitizes a string meta value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_string( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitizes a number meta value; empty stays empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_number( $value ) {
		$n = Normalizer::amount( is_scalar( $value ) ? (string) $value : '' );
		return null === $n ? '' : (string) $n;
	}

	/**
	 * Sanitizes the location list.
	 *
	 * @param mixed $value List of array( city, country ).
	 * @return array
	 */
	public static function sanitize_locations( $value ) {
		$out = array();
		foreach ( (array) $value as $loc ) {
			$loc = (array) $loc;
			$l   = Normalizer::location(
				isset( $loc['city'] ) ? sanitize_text_field( $loc['city'] ) : '',
				isset( $loc['country'] ) ? sanitize_text_field( $loc['country'] ) : ''
			);
			if ( ! empty( $l ) ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	/**
	 * Parses the textarea format "City, CC" (one location per line).
	 *
	 * @param string $text Text.
	 * @return array
	 */
	public static function parse_locations( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( ',', $line ) );
			$last    = end( $parts );
			$country = '';
			// "Zürich, CH" → city + country; "CH" alone → country only; "Zürich" → city only.
			if ( preg_match( '/^[A-Za-z]{2}$/', $last ) ) {
				$country = array_pop( $parts );
			}
			$out[] = array(
				'city'    => implode( ', ', $parts ),
				'country' => $country,
			);
		}
		return self::sanitize_locations( $out );
	}

	/**
	 * Registers the meta box.
	 */
	public static function add_meta_box() {
		add_meta_box( 'ojobpub-job', __( 'oJobPub details', 'ojobpub' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		$get      = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, self::META_PREFIX . $key, true );
		};
		$defaults = Settings::defaults_for_jobs();

		$locations = get_post_meta( $post->ID, self::META_PREFIX . 'locations', true );
		$loc_text  = '';
		foreach ( is_array( $locations ) ? $locations : array() as $loc ) {
			$loc_text .= trim( ( isset( $loc['city'] ) ? $loc['city'] : '' ) . ( isset( $loc['country'] ) ? ', ' . $loc['country'] : '' ), ', ' ) . "\n";
		}

		$select = static function ( $name, array $options, $current, $empty_label ) {
			printf( '<select name="ojobpub[%s]" id="ojobpub-%s">', esc_attr( $name ), esc_attr( $name ) );
			if ( null !== $empty_label ) {
				printf( '<option value="">%s</option>', esc_html( $empty_label ) );
			}
			foreach ( $options as $value => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		};

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'The job text above stays on this page. The feed only carries this summary plus a link here. The excerpt (or the beginning of the text) is used as the short description, max. 1000 characters.', 'ojobpub' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ojobpub-job_type"><?php esc_html_e( 'Job type', 'ojobpub' ); ?> *</label></th>
				<td><?php $select( 'job_type', Labels::job_types(), $get( 'job_type' ) ? $get( 'job_type' ) : $defaults['jobType'], null ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-locations"><?php esc_html_e( 'Locations', 'ojobpub' ); ?> *</label></th>
				<td>
					<textarea name="ojobpub[locations]" id="ojobpub-locations" rows="3" class="regular-text" placeholder="<?php echo esc_attr( 'Zürich, CH' ); ?>"><?php echo esc_textarea( $loc_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line: "City, CC" with a two-letter country code. Empty uses the default country from the settings.', 'ojobpub' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-work_type"><?php esc_html_e( 'Work type', 'ojobpub' ); ?></label></th>
				<td><?php $select( 'work_type', Labels::work_types(), $get( 'work_type' ), '—' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-experience_level"><?php esc_html_e( 'Experience level', 'ojobpub' ); ?></label></th>
				<td><?php $select( 'experience_level', Labels::experience_levels(), $get( 'experience_level' ), '—' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Workload (%)', 'ojobpub' ); ?></th>
				<td>
					<input type="number" min="0" max="100" step="5" name="ojobpub[workload_min]" value="<?php echo esc_attr( $get( 'workload_min' ) ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Minimum workload', 'ojobpub' ); ?>"> –
					<input type="number" min="0" max="100" step="5" name="ojobpub[workload_max]" value="<?php echo esc_attr( $get( 'workload_max' ) ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Maximum workload', 'ojobpub' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Salary', 'ojobpub' ); ?></th>
				<td>
					<input type="number" min="0" step="any" name="ojobpub[salary_min]" value="<?php echo esc_attr( $get( 'salary_min' ) ); ?>" class="small-text" style="width:8em" aria-label="<?php esc_attr_e( 'Minimum salary', 'ojobpub' ); ?>"> –
					<input type="number" min="0" step="any" name="ojobpub[salary_max]" value="<?php echo esc_attr( $get( 'salary_max' ) ); ?>" class="small-text" style="width:8em" aria-label="<?php esc_attr_e( 'Maximum salary', 'ojobpub' ); ?>">
					<input type="text" maxlength="3" pattern="[A-Za-z]{3}" name="ojobpub[salary_currency]" value="<?php echo esc_attr( $get( 'salary_currency' ) ); ?>" class="small-text" placeholder="CHF" aria-label="<?php esc_attr_e( 'Currency (ISO 4217)', 'ojobpub' ); ?>">
					<?php $select( 'salary_interval', Labels::salary_intervals(), $get( 'salary_interval' ), __( 'per …', 'ojobpub' ) ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-apply_before"><?php esc_html_e( 'Apply before', 'ojobpub' ); ?></label></th>
				<td>
					<input type="date" name="ojobpub[apply_before]" id="ojobpub-apply_before" value="<?php echo esc_attr( $get( 'apply_before' ) ); ?>">
					<p class="description"><?php esc_html_e( 'After this date the job is removed from the feed automatically.', 'ojobpub' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Start / end date', 'ojobpub' ); ?></th>
				<td>
					<input type="date" name="ojobpub[start_date]" value="<?php echo esc_attr( $get( 'start_date' ) ); ?>" aria-label="<?php esc_attr_e( 'Start date', 'ojobpub' ); ?>"> –
					<input type="date" name="ojobpub[end_date]" value="<?php echo esc_attr( $get( 'end_date' ) ); ?>" aria-label="<?php esc_attr_e( 'End date (fixed-term positions)', 'ojobpub' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-language"><?php esc_html_e( 'Language of the posting', 'ojobpub' ); ?></label></th>
				<td><input type="text" maxlength="2" pattern="[A-Za-z]{2}" name="ojobpub[language]" id="ojobpub-language" value="<?php echo esc_attr( $get( 'language' ) ); ?>" placeholder="<?php echo esc_attr( $defaults['language'] ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ojobpub-reference_id"><?php esc_html_e( 'Reference ID', 'ojobpub' ); ?></label></th>
				<td><input type="text" maxlength="255" name="ojobpub[reference_id]" id="ojobpub-reference_id" value="<?php echo esc_attr( $get( 'reference_id' ) ); ?>" class="regular-text"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE . '_nonce' ] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['ojobpub'] ) && is_array( $_POST['ojobpub'] ) ? wp_unslash( $_POST['ojobpub'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.

		$enums = array(
			'job_type'         => Normalizer::JOB_TYPES,
			'work_type'        => Normalizer::WORK_TYPES,
			'experience_level' => Normalizer::EXPERIENCE_LEVELS,
			'salary_interval'  => Normalizer::SALARY_INTERVALS,
		);

		foreach ( self::FIELDS as $key => $type ) {
			$raw = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			if ( isset( $enums[ $key ] ) ) {
				$value = (string) Normalizer::enum( $raw, $enums[ $key ] );
			} elseif ( in_array( $key, array( 'apply_before', 'start_date', 'end_date' ), true ) ) {
				$value = (string) Normalizer::date( $raw );
			} elseif ( 'language' === $key ) {
				$value = preg_match( '/^[A-Za-z]{2}$/', trim( $raw ) ) ? strtolower( trim( $raw ) ) : '';
			} elseif ( 'salary_currency' === $key ) {
				$value = preg_match( '/^[A-Za-z]{3}$/', trim( $raw ) ) ? strtoupper( trim( $raw ) ) : '';
			} elseif ( 'number' === $type ) {
				$value = self::sanitize_number( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			if ( '' === $value ) {
				delete_post_meta( $post_id, self::META_PREFIX . $key );
			} else {
				update_post_meta( $post_id, self::META_PREFIX . $key, $value );
			}
		}

		$locations = self::parse_locations( isset( $input['locations'] ) ? (string) $input['locations'] : '' );
		if ( empty( $locations ) ) {
			delete_post_meta( $post_id, self::META_PREFIX . 'locations' );
		} else {
			update_post_meta( $post_id, self::META_PREFIX . 'locations', $locations );
		}
	}
}
