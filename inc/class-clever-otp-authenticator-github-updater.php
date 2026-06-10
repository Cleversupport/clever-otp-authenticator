<?php
/**
 * GitHub Releases updater for Clever OTP Authenticator.
 *
 * @package Clever_OTP_Authenticator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-contained WordPress updater backed by GitHub Releases.
 *
 * How updates work:
 * - WordPress asks this class for plugin update data in the admin area.
 * - The updater checks the latest GitHub Release for this repository.
 * - The GitHub release version/tag must be higher than the plugin header Version.
 * - The release ZIP must contain/install into the correct WordPress plugin folder:
 *   otp-authenticator, so the plugin file remains otp-authenticator/otp-authenticator.php.
 * - Private repositories are supported by saving a GitHub token in the
 *   clever_otp_authenticator_github_token option from Settings > OTP Authenticator > Updates.
 */
class Clever_OTP_Authenticator_GitHub_Updater {
	/** GitHub repository owner. */
	const OWNER = 'Cleversupport';

	/** GitHub repository name. */
	const REPO = 'clever-otp-authenticator';

	/** GitHub latest release API endpoint. */
	const RELEASE_ENDPOINT = 'https://api.github.com/repos/Cleversupport/clever-otp-authenticator/releases/latest';

	/** GitHub tags API endpoint. */
	const TAGS_ENDPOINT = 'https://api.github.com/repos/Cleversupport/clever-otp-authenticator/tags';

	/** WordPress option containing an optional GitHub token. */
	const TOKEN_OPTION = 'clever_otp_authenticator_github_token';

	/** Optional wp-config.php constant containing a GitHub token. */
	const TOKEN_CONSTANT = 'CLEVER_OTP_AUTHENTICATOR_GITHUB_TOKEN';

	/** WordPress.org-style plugin slug. */
	const PLUGIN_SLUG = 'otp-authenticator';

	/** Settings tab slug. */
	const SETTINGS_TAB = 'updates';

	/** Settings group name. */
	const SETTINGS_GROUP = 'clever_otp_authenticator_updater';

	/** User agent sent to GitHub. */
	const USER_AGENT = 'Clever-OTP-Authenticator-Updater';

	/** Cache key for GitHub API results. */
	const CACHE_KEY = 'clever_otp_authenticator_github_release';

	/** Marker added to authenticated package URLs. */
	const PRIVATE_PACKAGE_MARKER = 'clever_otp_authenticator_private_package';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin basename used by WordPress update APIs.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Currently installed plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->current_version = $this->get_current_version();
	}

	/**
	 * Register WordPress update hooks.
	 *
	 * Hooks are intended to be registered only in wp-admin because WordPress only
	 * checks, displays, and installs plugin updates from the admin update flow.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_private_package' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'ensure_plugin_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'otpa_after_main_tab_settings', array( $this, 'render_settings_tab_link' ), 20, 1 );
		add_action( 'otpa_after_main_settings', array( $this, 'render_settings_tab' ), 20, 1 );
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Register updater settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::TOKEN_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Add a direct settings link from the Plugins screen.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=otpa&tab=' . self::SETTINGS_TAB ) ),
			esc_html__( 'Update Settings', 'otpa' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Sanitize and save the GitHub token.
	 *
	 * Leaving the field blank keeps the existing token. Checking the clear box
	 * deletes the saved token. Updating or clearing the token clears update cache.
	 *
	 * @param string $token Submitted token.
	 * @return string
	 */
	public function sanitize_token( $token ) {
		$clear_token = isset( $_POST['clever_otp_authenticator_clear_github_token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $clear_token ) {
			$this->clear_cache();
			return '';
		}

		$token = trim( sanitize_text_field( wp_unslash( $token ) ) );

		if ( '' === $token ) {
			return (string) get_option( self::TOKEN_OPTION, '' );
		}

		$this->clear_cache();

		return $token;
	}

	/**
	 * Render the Updates settings tab link.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public function render_settings_tab_link( $active_tab ) {
		?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=otpa&tab=' . self::SETTINGS_TAB ) ); ?>" class="nav-tab<?php echo ( self::SETTINGS_TAB === $active_tab ) ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Updates', 'otpa' ); ?>
		</a>
		<?php
	}

	/**
	 * Render the Updates settings tab content.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public function render_settings_tab( $active_tab ) {
		if ( self::SETTINGS_TAB !== $active_tab ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$has_constant_token = $this->has_constant_token();
		$has_saved_token    = '' !== trim( (string) get_option( self::TOKEN_OPTION, '' ) );
		$has_token          = '' !== $this->get_token();
		?>
		<div class="stuffbox">
			<div class="inside">
				<p>
				<?php
				echo esc_html__(
					'Use this page to connect Clever OTP Authenticator to private GitHub Releases. Public repositories do not need a token.',
					'otpa'
				);
				?>
			</p>

			<?php if ( $has_token ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'A GitHub token is currently configured for plugin updates.', 'otpa' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No GitHub token is configured. Private repository updates will not work until a token is saved.', 'otpa' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $has_constant_token ) : ?>
				<div class="notice notice-info inline">
				<p>
					<?php
						printf(
							/* translators: %s: wp-config.php constant name. */
							esc_html__( 'The token is being loaded from the %s constant. To change it, update wp-config.php.', 'otpa' ),
							'<code>' . esc_html( self::TOKEN_CONSTANT ) . '</code>'
						);
					?>
				</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::TOKEN_OPTION ); ?>">
								<?php esc_html_e( 'GitHub Token', 'otpa' ); ?>
							</label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::TOKEN_OPTION ); ?>"
								id="<?php echo esc_attr( self::TOKEN_OPTION ); ?>"
								type="password"
								class="regular-text"
								value=""
								autocomplete="new-password"
								<?php disabled( $has_constant_token ); ?>
							>
							<p class="description">
								<?php
								if ( $has_constant_token ) {
									esc_html_e( 'This field is disabled because the token is defined in wp-config.php.', 'otpa' );
								} elseif ( $has_saved_token ) {
									esc_html_e( 'A token is saved. Leave this field blank to keep the current token, or enter a new token to replace it.', 'otpa' );
								} else {
									esc_html_e( 'Paste a GitHub fine-grained or classic token that can read this private repository and its releases.', 'otpa' );
								}
								?>
							</p>

							<?php if ( $has_saved_token && ! $has_constant_token ) : ?>
								<label>
									<input type="checkbox" name="clever_otp_authenticator_clear_github_token" value="1">
									<?php esc_html_e( 'Clear saved token', 'otpa' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Repository', 'otpa' ); ?></th>
						<td>
							<code><?php echo esc_html( self::OWNER . '/' . self::REPO ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Installed Version', 'otpa' ); ?></th>
						<td>
							<code><?php echo esc_html( $this->current_version ); ?></code>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Update Settings', 'otpa' ) ); ?>
			</form>
		</div>
	</div>
	<?php
	}

	/**
	 * Add update information to WordPress' plugin update transient.
	 *
	 * @param object $transient Plugin update transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();

		if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], $this->current_version, '>' ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'            => $this->plugin_basename,
			'slug'          => self::PLUGIN_SLUG,
			'plugin'        => $this->plugin_basename,
			'new_version'   => $release['version'],
			'url'           => $release['homepage'],
			'package'       => $release['package'],
			'tested'        => '',
			'requires_php'  => '',
			'compatibility' => new stdClass(),
		);

		return $transient;
	}

	/**
	 * Provide plugin details for the WordPress "View details" modal.
	 *
	 * @param false|object|array $result Current API result.
	 * @param string             $action Requested action.
	 * @param object             $args   API arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Clever OTP Authenticator',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://clevernwa.com">Clever</a>',
			'homepage'      => $release['homepage'],
			'download_link' => $release['package'],
			'tested'        => '',
			'requires'      => '',
			'requires_php'  => '',
			'sections'      => array(
				'description' => 'Clever OTP Authenticator updates are delivered from GitHub Releases.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : 'No changelog provided.',
			),
		);
	}

	/**
	 * Download private GitHub release assets using the configured token.
	 *
	 * WordPress' default downloader cannot attach the Authorization header to the
	 * package URL. This hook handles marked package URLs when a token is present.
	 *
	 * @param bool        $reply    Whether to bail without returning the package.
	 * @param string      $package  Package URL.
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $hook_extra Extra arguments passed to hooked filters.
	 * @return bool|string|WP_Error
	 */
	public function download_private_package( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );

		if ( ! $this->is_private_package_url( $package ) ) {
			return $reply;
		}

		$token = $this->get_token();

		if ( '' === $token ) {
			return $reply;
		}

		$url      = remove_query_arg( self::PRIVATE_PACKAGE_MARKER, $package );
		$tmp_file = wp_tempnam( self::PLUGIN_SLUG . '.zip' );

		if ( ! $tmp_file ) {
			return new WP_Error( 'clever_otp_authenticator_temp_file', __( 'Could not create a temporary file for the plugin update.', 'otpa' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp_file,
				'headers'  => $this->get_github_headers( true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $response_code ) {
			@unlink( $tmp_file );
			return new WP_Error(
				'clever_otp_authenticator_download_failed',
				sprintf(
					/* translators: %d: HTTP response code. */
					__( 'GitHub package download failed with HTTP status %d.', 'otpa' ),
					$response_code
				)
			);
		}

		return $tmp_file;
	}


	/**
	 * Ensure the extracted update installs into the otp-authenticator folder.
	 *
	 * GitHub source ZIPs extract into repository-generated folder names. WordPress
	 * plugin updates need the final folder to match the plugin slug so the main
	 * plugin file remains otp-authenticator/otp-authenticator.php.
	 *
	 * @param string      $source        Path to the extracted package source.
	 * @param string      $remote_source Path to the temporary upgrade directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments passed to hooked filters.
	 * @return string|WP_Error
	 */
	public function ensure_plugin_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		if ( self::PLUGIN_SLUG === basename( $source ) ) {
			return $source;
		}

		$destination = trailingslashit( $remote_source ) . self::PLUGIN_SLUG;

		if ( file_exists( $destination ) ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $destination ) ) {
			return new WP_Error(
				'clever_otp_authenticator_folder_rename_failed',
				__( 'Could not prepare the Clever OTP Authenticator update folder.', 'otpa' )
			);
		}

		return $destination;
	}

	/**
	 * Clear updater cache after plugin updates complete.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Update options.
	 * @return void
	 */
	public function clear_cache( $upgrader = null, $options = array() ) {
		unset( $upgrader, $options );

		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Get and normalize the latest GitHub release data.
	 *
	 * @return array|false
	 */
	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			return $cached;
		}

		$release = $this->request_json( self::RELEASE_ENDPOINT );

		if ( ! $release || empty( $release['tag_name'] ) ) {
			$release = $this->get_latest_tag_release();
		}

		if ( ! $release || empty( $release['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, false, HOUR_IN_SECONDS );
			return false;
		}

		$version = $this->normalize_version( $release['tag_name'] );
		$package = $this->get_release_package_url( $release );

		if ( '' === $version || '' === $package ) {
			set_site_transient( self::CACHE_KEY, false, HOUR_IN_SECONDS );
			return false;
		}

		$normalized = array(
			'version'  => $version,
			'package'  => $package,
			'homepage' => ! empty( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPO ),
			'body'     => ! empty( $release['body'] ) ? $release['body'] : '',
		);

		set_site_transient( self::CACHE_KEY, $normalized, 6 * HOUR_IN_SECONDS );

		return $normalized;
	}

	/**
	 * Build release-like data from the latest tag if no release exists.
	 *
	 * @return array|false
	 */
	private function get_latest_tag_release() {
		$tags = $this->request_json( self::TAGS_ENDPOINT );

		if ( empty( $tags ) || ! is_array( $tags ) || empty( $tags[0]['name'] ) ) {
			return false;
		}

		$tag = $tags[0]['name'];

		return array(
			'tag_name'    => $tag,
			'zipball_url' => sprintf( 'https://api.github.com/repos/%s/%s/zipball/%s', self::OWNER, self::REPO, rawurlencode( $tag ) ),
			'html_url'    => sprintf( 'https://github.com/%s/%s/releases/tag/%s', self::OWNER, self::REPO, rawurlencode( $tag ) ),
			'body'        => '',
		);
	}

	/**
	 * Request JSON from GitHub.
	 *
	 * @param string $url GitHub API URL.
	 * @return array|false
	 */
	private function request_json( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->get_github_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Get the best package URL from a GitHub release.
	 *
	 * A release asset named for the plugin slug is preferred because the ZIP must
	 * install into otp-authenticator/otp-authenticator.php. GitHub source archives
	 * are used as a fallback only when no matching release asset exists.
	 *
	 * @param array $release GitHub release data.
	 * @return string
	 */
	private function get_release_package_url( $release ) {
		$package = '';

		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
					continue;
				}

				$asset_name = strtolower( $asset['name'] );

				if ( false !== strpos( $asset_name, self::PLUGIN_SLUG ) && '.zip' === substr( $asset_name, -4 ) ) {
					$package = ! empty( $asset['url'] ) && '' !== $this->get_token() ? $asset['url'] : $asset['browser_download_url'];
					$package = esc_url_raw( $package );
					break;
				}
			}
		}

		if ( '' === $package && ! empty( $release['zipball_url'] ) ) {
			$package = esc_url_raw( $release['zipball_url'] );
		}

		if ( '' !== $package && '' !== $this->get_token() ) {
			$package = add_query_arg( self::PRIVATE_PACKAGE_MARKER, '1', $package );
		}

		return $package;
	}

	/**
	 * Build headers for GitHub API requests.
	 *
	 * @param bool $download Whether the request downloads a package file.
	 * @return array
	 */
	private function get_github_headers( $download = false ) {
		$headers = array(
			'Accept'               => $download ? 'application/octet-stream' : 'application/vnd.github+json',
			'User-Agent'           => self::USER_AGENT,
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = $this->get_token();

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Get the configured GitHub token.
	 *
	 * @return string
	 */
	private function get_token() {
		if ( $this->has_constant_token() ) {
			return trim( (string) constant( self::TOKEN_CONSTANT ) );
		}

		return trim( (string) get_option( self::TOKEN_OPTION, '' ) );
	}

	/**
	 * Determine whether a GitHub token is defined in wp-config.php.
	 *
	 * @return bool
	 */
	private function has_constant_token() {
		return defined( self::TOKEN_CONSTANT ) && '' !== trim( (string) constant( self::TOKEN_CONSTANT ) );
	}

	/**
	 * Determine whether a package URL should be downloaded by this class.
	 *
	 * @param string $package Package URL.
	 * @return bool
	 */
	private function is_private_package_url( $package ) {
		return is_string( $package ) && false !== strpos( $package, self::PRIVATE_PACKAGE_MARKER . '=1' );
	}

	/**
	 * Get the installed version from the plugin header.
	 *
	 * @return string
	 */
	private function get_current_version() {
		$plugin_data = get_file_data(
			$this->plugin_file,
			array(
				'Version' => 'Version',
			),
			'plugin'
		);

		return ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0';
	}

	/**
	 * Convert release tags like "v1.2.3" to WordPress-comparable versions.
	 *
	 * @param string $version Raw release tag.
	 * @return string
	 */
	private function normalize_version( $version ) {
		return ltrim( trim( (string) $version ), 'vV' );
	}
}
