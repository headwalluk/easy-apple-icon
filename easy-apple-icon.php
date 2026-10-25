<?php
/**
 * Plugin Name:       Easy Apple Icon
 * Plugin URI:        https://headwall-hosting.com/plugins/easy-apple-icon/
 * Description:       Answers Apple touch-icon requests with the Site Icon, and writes them as static files, so they stop rendering the theme's 404 page.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Paul Faulkner
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Easy_Apple_Icon
 */

namespace Easy_Apple_Icon;

defined( 'ABSPATH' ) || die();

// Declarations sit inside the guard so a second include is a no-op (see CLAUDE.md).
if ( ! class_exists( __NAMESPACE__ . '\\Plugin' ) ) {

	/**
	 * Serves, writes and cleans up the Apple touch-icon files.
	 */
	class Plugin {


		public const VERSION = '1.1.0';

		private const OPTION_STATE          = 'easy_apple_icon_state';
		private const FILTER_ENABLED        = 'easy_apple_icon_enabled';
		private const FILTER_SOURCE_FILE    = 'easy_apple_icon_source_file';
		private const FILTER_SIZES          = 'easy_apple_icon_sizes';
		private const FILTER_CACHE_MAX_AGE  = 'easy_apple_icon_cache_max_age';
		private const CONSTANT_STATIC_FILES = 'EASY_APPLE_ICON_STATIC_FILES';
		private const CLI_COMMAND           = 'easy-apple-icon';

		private const DEFAULT_SIZES         = [ 57, 60, 72, 76, 114, 120, 144, 152, 167, 180, 240 ];
		private const BARE_SIZE             = 180;
		private const MIN_SIZE              = 16;
		private const MAX_SIZE              = 1024;
		private const DEFAULT_CACHE_MAX_AGE = 604800;
		private const NOT_FOUND_MAX_AGE     = 3600;
		private const FILE_MODE             = 0644;

		private const PATH_PREFIX       = '/apple-touch-icon';
		private const PROBE_PATTERN     = '#^/apple-touch-icon(?:-([0-9]{1,4})x([0-9]{1,4}))?(-precomposed)?\.png$#D';
		private const FILE_NAME_PATTERN = '#^apple-touch-icon(?:-[0-9]{1,4}x[0-9]{1,4})?(?:-precomposed)?\.png$#D';
		private const FILE_NAME_STEM    = 'apple-touch-icon';
		private const BARE_FILE_NAMES   = [ 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png' ];
		private const PNG_SIGNATURE     = "\x89PNG\r\n\x1a\n";
		private const LOG_PREFIX        = 'easy-apple-icon: ';

		private const STATUS_OURS    = 'ours';
		private const STATUS_FOREIGN = 'foreign';
		private const STATUS_MISSING = 'missing';

		private const WRITE_WRITTEN = 'written';
		private const WRITE_CURRENT = 'current';
		private const WRITE_FOREIGN = 'foreign';
		private const WRITE_FAILED  = 'failed';
		private const WRITE_STALE   = 'stale';

		/**
		 * Open handle on this file while the state lock is held, otherwise null.
		 *
		 * @var resource|null
		 */
		private mixed $lock_handle = null;

		/**
		 * Nesting depth of lock_state() calls; the lock is released when it returns to 0.
		 *
		 * @var int
		 */
		private int $lock_depth = 0;

		/**
		 * Registers every hook; runs from the file body in all three load contexts.
		 */
		public function run(): void {
			add_action( 'add_option_site_icon', [ $this, 'handle_site_icon_change' ], 10, 0 );
			add_action( 'update_option_site_icon', [ $this, 'handle_site_icon_change' ], 10, 0 );
			add_action( 'delete_option_site_icon', [ $this, 'handle_site_icon_change' ], 10, 0 );

			if ( $this->is_plugin_context() ) {
				register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$this->register_cli_commands();
			}

			if ( $this->is_probe_request() ) {
				add_action( 'parse_request', [ $this, 'handle_probe' ], 0, 0 );
			}
		}

		/**
		 * Serves the icon when the request is an allow-listed touch-icon name.
		 */
		public function handle_probe(): void {
			$probe = $this->parse_probe( $this->get_request_path() );

			if ( null === $probe ) {
				// Not an allow-listed icon name; WordPress handles it as normal.
			} elseif ( ! $this->is_enabled() ) {
				// Switched off by the filter; WordPress handles it as if the plugin weren't installed.
			} elseif ( $this->home_has_path() ) {
				// The probes go to the domain root, which this WordPress doesn't own.
			} else {
				$this->serve_icon( $probe['file_name'], $probe['size'] );
			}
		}

		/**
		 * Deletes the plugin's files when the Site Icon changes, unless the plugin is switched off.
		 */
		public function handle_site_icon_change(): void {
			if ( ! $this->is_enabled() ) {
				// Switched off: the saved files are the site owner's now.
			} else {
				$this->delete_owned_files();
			}
		}

		/**
		 * Deletes every file the plugin wrote; returns false if any could not be deleted.
		 */
		public function delete_owned_files(): bool {
			$this->lock_state();

			$state       = $this->get_state();
			$site_root   = $this->get_site_root();
			$failed      = [];
			$is_writable = '' !== $site_root && wp_is_writable( $site_root );

			// A probe still rendering from the old source sees the new generation and doesn't write.
			++$state['generation'];

			if ( '' === $site_root ) {
				$failed = array_keys( $state['files'] );
			} else {
				foreach ( array_keys( $state['files'] ) as $file_name ) {
					$status = $this->get_file_status( $file_name, $state );

					if ( self::STATUS_MISSING === $status ) {
						unset( $state['files'][ $file_name ] );
					} elseif ( self::STATUS_FOREIGN === $status ) {
						unset( $state['files'][ $file_name ] );
						$state = $this->add_foreign_notice( $state, $file_name );
					} elseif ( ! $is_writable ) {
						$failed[] = $file_name;
					} elseif ( ! unlink( $site_root . $file_name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value is needed, wp_delete_file() hides it
						$failed[] = $file_name;
					} else {
						unset( $state['files'][ $file_name ] );
						$this->log( 'deleted ' . $site_root . $file_name );
					}
				}
			}

			$this->save_state( $state );

			if ( [] === $failed ) {
				// Every recorded file is gone.
			} elseif ( '' === $site_root ) {
				$this->log_error( 'could not delete ' . implode( ', ', $failed ) . ': the site root could not be determined' );
			} elseif ( ! $is_writable ) {
				$this->log_error( sprintf( 'could not delete %s from %s, which is not writable by %s; the files are stale until they are removed', implode( ', ', $failed ), $site_root, $this->describe_user( $this->get_current_user_id() ) ) );
			} else {
				$this->log_error( sprintf( 'could not delete %s from %s; the files are stale until they are removed', implode( ', ', $failed ), $site_root ) );
			}

			$this->unlock_state();

			return [] === $failed;
		}

		/**
		 * Deletes the plugin's files, and its state once they are all gone.
		 */
		public function deactivate(): void {
			if ( $this->delete_files_and_state() ) {
				// Nothing is left behind.
			} elseif ( defined( 'WP_CLI' ) && WP_CLI && '' !== $this->get_site_root() ) {
				// Deactivation can't be refused, so say how to finish the clean-up.
				\WP_CLI::warning( 'The plugin\'s icon files were left in place: ' . $this->get_wrong_user_message( $this->get_site_root(), 'remove' ) . PHP_EOL . 'Reactivate the plugin to run that command, then deactivate it again.' );
			} else {
				// The failure is logged and recorded; the next activation sees the stale files in status.
			}
		}

		/**
		 * Deletes the plugin's files and then, under the same lock, its state; returns false if any file remains.
		 */
		private function delete_files_and_state(): bool {
			global $wpdb;

			$this->lock_state();

			$is_deleted = $this->delete_owned_files();

			if ( $is_deleted ) {
				$wpdb->delete( $wpdb->options, [ 'option_name' => self::OPTION_STATE ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see get_state()
				wp_cache_delete( self::OPTION_STATE, 'options' );
			}

			$this->unlock_state();

			return $is_deleted;
		}

		/**
		 * Shows the source file, each icon file's ownership, and the last error.
		 */
		public function cli_status(): void {
			$state     = $this->get_state();
			$site_root = $this->get_site_root();
			$source    = $this->get_source_file();
			$file_rows = [];

			\WP_CLI::log( 'Enabled:      ' . ( $this->is_enabled() ? 'yes' : 'no, switched off by the ' . self::FILTER_ENABLED . ' filter' ) );
			\WP_CLI::log( 'Source:       ' . $this->describe_source( $source ) );
			\WP_CLI::log( 'Site root:    ' . ( '' === $site_root ? 'unknown: the siteurl path does not match the directory WordPress is in' : $site_root . ( wp_is_writable( $site_root ) ? '' : ' (not writable by ' . $this->describe_user( $this->get_current_user_id() ) . ')' ) ) );
			\WP_CLI::log( 'Static files: ' . $this->describe_static_mode() );

			$file_names = array_unique( array_merge( self::BARE_FILE_NAMES, array_keys( $state['files'] ) ) );

			foreach ( $file_names as $file_name ) {
				$record      = $state['files'][ $file_name ] ?? null;
				$status      = $this->get_file_status( $file_name, $state );
				$file_rows[] = [
					'file'    => $file_name,
					'status'  => self::STATUS_OURS === $status && $this->is_stale( $record, $source ) ? 'ours (stale)' : $status,
					'size'    => null === $record ? '' : $record['size'] . 'x' . $record['size'],
					'bytes'   => null === $record ? '' : (string) $record['bytes'],
					'written' => null === $record ? '' : gmdate( 'Y-m-d H:i:s', $record['written_at'] ) . ' UTC',
				];
			}

			\WP_CLI\Utils\format_items( 'table', $file_rows, [ 'file', 'status', 'size', 'bytes', 'written' ] );

			if ( null === $state['last_error'] ) {
				\WP_CLI::log( 'Last error:   none' );
			} else {
				\WP_CLI::log( 'Last error:   ' . gmdate( 'Y-m-d H:i:s', $state['last_error']['at'] ) . ' UTC: ' . $state['last_error']['message'] );
			}
		}

		/**
		 * Removes the plugin's files, then writes both bare icon names from the current source.
		 */
		public function cli_regenerate(): void {
			$site_root = $this->get_site_root();
			$source    = $this->get_source_file();

			if ( ! $this->is_enabled() ) {
				\WP_CLI::error( 'The plugin is switched off by the ' . self::FILTER_ENABLED . ' filter. Nothing was changed.' );
			} elseif ( ! $this->is_static_enabled() ) {
				\WP_CLI::error( 'Static files are switched off (' . self::CONSTANT_STATIC_FILES . ' is false). Icons are served dynamically; there is nothing to write.' );
			} elseif ( is_multisite() ) {
				\WP_CLI::error( 'Static files are not written on multisite: every site shares one site root. Icons are served dynamically.' );
			} elseif ( $this->home_has_path() ) {
				\WP_CLI::error( 'The home URL has a path (' . get_option( 'home' ) . '). Apple devices request icons from the domain root, which this WordPress does not serve.' );
			} elseif ( '' === $site_root ) {
				\WP_CLI::error( 'Could not determine the site root: the siteurl path does not match the directory WordPress is in. Static files are not written.' );
			} elseif ( ! wp_is_writable( $site_root ) ) {
				\WP_CLI::error( $this->get_wrong_user_message( $site_root, 'regenerate' ) . PHP_EOL . 'Nothing was changed.' );
			} elseif ( ! $this->delete_owned_files() ) {
				\WP_CLI::error( 'Could not delete the existing icon files. See the last error in "wp ' . self::CLI_COMMAND . ' status".' );
			} elseif ( '' === $source ) {
				\WP_CLI::error( 'No Site Icon is set, so there is nothing to write. Any files the plugin wrote have been removed.' );
			} else {
				$this->write_bare_files();
			}
		}

		/**
		 * Deletes the plugin's files and clears its state.
		 */
		public function cli_remove(): void {
			$site_root = $this->get_site_root();

			if ( '' === $site_root ) {
				\WP_CLI::error( 'Could not determine the site root: the siteurl path does not match the directory WordPress is in.' );
			} elseif ( ! wp_is_writable( $site_root ) ) {
				\WP_CLI::error( $this->get_wrong_user_message( $site_root, 'remove' ) . PHP_EOL . 'Nothing was changed.' );
			} elseif ( ! $this->delete_files_and_state() ) {
				\WP_CLI::error( 'Could not delete every icon file. See the last error in "wp ' . self::CLI_COMMAND . ' status".' );
			} else {
				\WP_CLI::success( 'Removed the plugin\'s icon files and cleared its state.' );
			}
		}

		/**
		 * Registers the WP-CLI subcommands.
		 */
		private function register_cli_commands(): void {
			\WP_CLI::add_command( self::CLI_COMMAND . ' status', [ $this, 'cli_status' ], [ 'shortdesc' => 'Shows the icon source, each icon file and the last error.' ] );
			\WP_CLI::add_command( self::CLI_COMMAND . ' regenerate', [ $this, 'cli_regenerate' ], [ 'shortdesc' => 'Removes the plugin\'s icon files and writes the two bare names now.' ] );
			\WP_CLI::add_command( self::CLI_COMMAND . ' remove', [ $this, 'cli_remove' ], [ 'shortdesc' => 'Deletes the plugin\'s icon files and clears its state.' ] );
		}

		/**
		 * Writes both bare icon names for WP-CLI and reports the outcome.
		 */
		private function write_bare_files(): void {
			$generation = $this->get_state()['generation'];
			$source     = $this->get_source_file();
			$png        = '' === $source ? '' : $this->render_png( $source, self::BARE_SIZE );
			$written    = [];
			$foreign    = [];
			$failures   = [];

			if ( '' === $png ) {
				\WP_CLI::error( 'Could not convert the source image to PNG. See the last error in "wp ' . self::CLI_COMMAND . ' status".' );
			} else {
				foreach ( self::BARE_FILE_NAMES as $file_name ) {
					$outcome = $this->write_icon_file( $file_name, self::BARE_SIZE, $png, $source, $generation );

					if ( self::WRITE_FOREIGN === $outcome ) {
						$foreign[] = $file_name;
					} elseif ( self::WRITE_FAILED === $outcome || self::WRITE_STALE === $outcome ) {
						$failures[] = $file_name;
					} else {
						$written[] = $file_name;
					}
				}
			}

			if ( [] !== $foreign ) {
				\WP_CLI::warning( 'Left alone, not written by this plugin: ' . implode( ', ', $foreign ) );
			}

			if ( [] !== $failures ) {
				\WP_CLI::error( 'Could not write ' . implode( ', ', $failures ) . '. See the last error in "wp ' . self::CLI_COMMAND . ' status".' );
			} elseif ( [] === $written ) {
				\WP_CLI::success( 'Nothing written: every bare icon name is held by a file this plugin did not write.' );
			} else {
				\WP_CLI::success( 'Wrote ' . implode( ', ', $written ) . ' to ' . $this->get_site_root() );
			}
		}

		/**
		 * Builds the error shown when WP-CLI runs as a user who can't write the site root.
		 *
		 * @param string $site_root Site root, with a trailing slash.
		 * @param string $subcommand Subcommand to suggest.
		 */
		private function get_wrong_user_message( string $site_root, string $subcommand ): string {
			$owner_id   = fileowner( $site_root );
			$owner_name = $this->get_user_name( $owner_id );
			$run_as     = '' === $owner_name ? '<site owner>' : $owner_name;

			return implode(
				PHP_EOL,
				[
					'Cannot write to the site root, ' . $site_root,
					'You are running as ' . $this->describe_user( $this->get_current_user_id() ) . '; the directory is owned by ' . $this->describe_user( $owner_id ) . '.',
					'Run the command as the owner instead:',
					'  sudo -u ' . $run_as . ' wp --path=' . untrailingslashit( ABSPATH ) . ' ' . self::CLI_COMMAND . ' ' . $subcommand,
					'If that user is jailed (chroot), use the site path as that user sees it.',
				]
			);
		}

		/**
		 * Returns the effective user ID of this process, or false without the POSIX extension.
		 */
		private function get_current_user_id(): int|false {
			return function_exists( 'posix_geteuid' ) ? posix_geteuid() : false;
		}

		/**
		 * Returns the login name for a user ID, or '' when it can't be looked up.
		 *
		 * @param int|false $user_id User ID, or false when unknown.
		 */
		private function get_user_name( int|false $user_id ): string {
			$user_name = '';

			if ( false === $user_id ) {
				// The ID itself is unknown.
			} elseif ( ! function_exists( 'posix_getpwuid' ) ) {
				// Without the POSIX extension only the numeric ID is known.
			} else {
				$user_info = posix_getpwuid( $user_id );
				$user_name = is_array( $user_info ) && isset( $user_info['name'] ) ? (string) $user_info['name'] : '';
			}

			return $user_name;
		}

		/**
		 * Describes a user ID for messages: the name, the numeric ID, or "an unknown user".
		 *
		 * @param int|false $user_id User ID, or false when unknown.
		 */
		private function describe_user( int|false $user_id ): string {
			$user_name   = $this->get_user_name( $user_id );
			$description = 'an unknown user';

			if ( false === $user_id ) {
				// Without the POSIX extension the user can't be identified.
			} elseif ( '' === $user_name ) {
				$description = 'uid ' . $user_id;
			} else {
				$description = $user_name;
			}

			return $description;
		}

		/**
		 * Describes where the source image comes from, for status.
		 *
		 * @param string $source Source image path.
		 */
		private function describe_source( string $source ): string {
			$site_icon_id = $this->get_site_icon_id();
			$description  = $source . ' (' . self::FILTER_SOURCE_FILE . ' filter)';

			if ( '' === $source && $site_icon_id > 0 ) {
				$description = 'none: the Site Icon (attachment ' . $site_icon_id . ') has no readable file';
			} elseif ( '' === $source ) {
				$description = 'none: no Site Icon is set';
			} elseif ( $this->get_site_icon_file() === $source ) {
				$description = $source . ' (Site Icon, attachment ' . $site_icon_id . ')';
			} else {
				// Supplied by the filter; the default description applies.
			}

			return $description;
		}

		/**
		 * Describes whether static files are written, and why not, for status.
		 */
		private function describe_static_mode(): string {
			$description = 'enabled';

			if ( ! $this->is_static_enabled() ) {
				$description = 'disabled by ' . self::CONSTANT_STATIC_FILES;
			} elseif ( is_multisite() ) {
				$description = 'not used on multisite';
			} elseif ( $this->home_has_path() ) {
				$description = 'not used: the home URL has a path, so probes are not handled';
			} elseif ( '' === $this->get_site_root() ) {
				$description = 'not used: the site root could not be determined';
			} else {
				// Static files are written on the first probe for each name.
			}

			return $description;
		}

		/**
		 * Sends the icon (or an empty 404), writes the static file, and ends the request.
		 *
		 * @param string $file_name Allow-listed icon file name.
		 * @param int    $size Icon width and height in pixels.
		 */
		private function serve_icon( string $file_name, int $size ): never {
			// Read before the source: a Site Icon change after this point bumps the generation.
			$generation = $this->get_state()['generation'];
			$source     = $this->get_source_file();
			$png        = '';

			if ( '' === $source && $this->get_site_icon_id() > 0 ) {
				$this->log_error( 'the Site Icon (attachment ' . $this->get_site_icon_id() . ') has no readable file' );
			} elseif ( '' === $source ) {
				// No Site Icon is set; the empty 404 below is the answer.
			} else {
				$png = $this->render_png( $source, $size );
			}

			if ( '' === $png ) {
				$this->send_not_found();
			} else {
				$this->send_png( $png );
				$this->maybe_write_static( $file_name, $size, $png, $source, $generation );
			}

			exit;
		}

		/**
		 * Writes the static file when the configuration and the site root allow it.
		 *
		 * @param string $file_name Allow-listed icon file name.
		 * @param int    $size Icon width and height in pixels.
		 * @param string $png PNG bytes.
		 * @param string $source Source image path.
		 * @param int    $generation State generation read before the source.
		 */
		private function maybe_write_static( string $file_name, int $size, string $png, string $source, int $generation ): void {
			$site_root = $this->get_site_root();

			if ( ! $this->is_static_enabled() ) {
				// Static files are switched off; serving dynamically is enough.
			} elseif ( is_multisite() ) {
				// Sites share one docroot, so a file written here would serve every site's icon.
			} elseif ( '' === $site_root ) {
				$this->log_error( 'could not determine the site root: the siteurl path does not match the directory WordPress is in; icons are served dynamically' );
			} elseif ( ! wp_is_writable( $site_root ) ) {
				$this->record_unwritable( $site_root );
			} else {
				$this->finish_response();
				$this->write_icon_file( $file_name, $size, $png, $source, $generation );
			}
		}

		/**
		 * Writes one icon file atomically and records it; returns a WRITE_* outcome.
		 *
		 * @param string $file_name Allow-listed icon file name.
		 * @param int    $size Icon width and height in pixels.
		 * @param string $png PNG bytes.
		 * @param string $source Source image path.
		 * @param int    $generation State generation read before the source.
		 */
		private function write_icon_file( string $file_name, int $size, string $png, string $source, int $generation ): string {
			$this->lock_state();

			$state     = $this->get_state();
			$site_root = $this->get_site_root();
			$target    = $site_root . $file_name;
			$status    = $this->get_file_status( $file_name, $state );
			$sha256    = hash( 'sha256', $png );
			$temp_path = $site_root . '.' . $file_name . '.' . wp_generate_password( 12, false ) . '.tmp';
			$outcome   = self::WRITE_FAILED;

			if ( $state['generation'] !== $generation ) {
				// The files were reset while this PNG was made from the previous source.
				$outcome = self::WRITE_STALE;
			} elseif ( self::STATUS_FOREIGN === $status ) {
				$this->save_state( $this->add_foreign_notice( $state, $file_name ) );
				$outcome = self::WRITE_FOREIGN;
			} elseif ( self::STATUS_OURS === $status && $state['files'][ $file_name ]['sha256'] === $sha256 ) {
				// The file on disk already holds these bytes.
				$outcome = self::WRITE_CURRENT;
			} elseif ( strlen( $png ) !== file_put_contents( $temp_path, $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem may prompt for credentials (see CLAUDE.md)
				$this->delete_temp_file( $temp_path );
				$this->log_error( 'could not write a temporary file for ' . $target );
			} elseif ( ! chmod( $temp_path, self::FILE_MODE ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- see file_put_contents above
				$this->delete_temp_file( $temp_path );
				$this->log_error( 'could not set the mode of the temporary file for ' . $target );
			} elseif ( ! $this->move_into_place( $temp_path, $target, self::STATUS_MISSING === $status ) ) {
				$this->delete_temp_file( $temp_path );
				$this->log_error( 'could not move the temporary file into place as ' . $target );
			} else {
				$state['files'][ $file_name ] = [
					'sha256'       => $sha256,
					'size'         => $size,
					'bytes'        => strlen( $png ),
					'source_path'  => $this->get_portable_path( $source ),
					'source_mtime' => (int) filemtime( $source ),
					'written_at'   => time(),
				];

				$state['notices']['unwritable'] = false;
				$state['notices']['foreign']    = array_values( array_diff( $state['notices']['foreign'], [ $file_name ] ) );

				$this->save_state( $state );
				$this->log( 'wrote ' . $target );
				$outcome = self::WRITE_WRITTEN;
			}

			$this->unlock_state();

			return $outcome;
		}

		/**
		 * Moves the temporary file to the target; a new target is hard-linked, so a file created meanwhile is never replaced.
		 *
		 * @param string $temp_path Temporary file path.
		 * @param string $target Final path in the site root.
		 * @param bool   $is_new Whether the target was missing when checked.
		 */
		private function move_into_place( string $temp_path, string $target, bool $is_new ): bool {
			$is_moved = false;

			if ( ! $is_new ) {
				$is_moved = rename( $temp_path, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem may prompt for credentials (see CLAUDE.md)
			} elseif ( link( $temp_path, $target ) ) {
				$is_moved = true;
				$this->delete_temp_file( $temp_path );
			} elseif ( file_exists( $target ) || is_link( $target ) ) {
				// Another process created the target after the check; it isn't ours to replace.
			} else {
				// The filesystem doesn't support hard links.
				$is_moved = rename( $temp_path, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- see above
			}

			return $is_moved;
		}

		/**
		 * Deletes a leftover temporary file, logging if that fails too.
		 *
		 * @param string $temp_path Temporary file path.
		 */
		private function delete_temp_file( string $temp_path ): void {
			if ( ! file_exists( $temp_path ) ) {
				// Nothing was created.
			} elseif ( ! unlink( $temp_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value is needed, wp_delete_file() hides it
				$this->log_error( 'could not delete the temporary file ' . $temp_path );
			} else {
				$this->log( 'deleted the temporary file ' . $temp_path );
			}
		}

		/**
		 * Records an unwritable site root, logging only when the condition first appears.
		 *
		 * @param string $site_root Site root, with a trailing slash.
		 */
		private function record_unwritable( string $site_root ): void {
			$this->lock_state();

			$state = $this->get_state();

			if ( ! $state['notices']['unwritable'] ) {
				$state['notices']['unwritable'] = true;
				$this->save_state( $state );
				$this->log_error( 'the site root ' . $site_root . ' is not writable by ' . $this->describe_user( $this->get_current_user_id() ) . '; icons are served dynamically' );
			}

			$this->unlock_state();
		}

		/**
		 * Adds a file name to the foreign notices, logging only the first time it is seen.
		 *
		 * @param array  $state Normalised state.
		 * @param string $file_name Allow-listed icon file name.
		 */
		private function add_foreign_notice( array $state, string $file_name ): array {
			if ( ! in_array( $file_name, $state['notices']['foreign'], true ) ) {
				$state['notices']['foreign'][] = $file_name;
				$this->log_warning( 'left ' . $this->get_site_root() . $file_name . ' alone: this plugin did not write it' );
			}

			return $state;
		}

		/**
		 * Returns whether a file is ours, foreign or missing.
		 *
		 * @param string $file_name Allow-listed icon file name.
		 * @param array  $state Normalised state.
		 */
		private function get_file_status( string $file_name, array $state ): string {
			$site_root = $this->get_site_root();
			$path      = $site_root . $file_name;
			$record    = $state['files'][ $file_name ] ?? null;
			$status    = self::STATUS_FOREIGN;

			if ( '' === $site_root ) {
				// Without a site root there is no file to look at; writers and deleters check first.
				$status = self::STATUS_MISSING;
			} elseif ( is_link( $path ) ) {
				// A symlink is never ours, whatever it points at.
			} elseif ( ! file_exists( $path ) ) {
				$status = self::STATUS_MISSING;
			} elseif ( ! is_file( $path ) || null === $record ) {
				// Something else holds the name.
			} elseif ( hash_file( 'sha256', $path ) !== $record['sha256'] ) {
				// The bytes changed since the plugin wrote them.
			} else {
				$status = self::STATUS_OURS;
			}

			return $status;
		}

		/**
		 * Returns whether a recorded file was made from a source other than the current one.
		 *
		 * @param ?array $record File record, or null when the file isn't recorded.
		 * @param string $source Source image path.
		 */
		private function is_stale( ?array $record, string $source ): bool {
			$is_stale = false;

			if ( null === $record ) {
				// Not ours, so it can't be stale.
			} elseif ( '' === $source || $record['source_path'] !== $this->get_portable_path( $source ) ) {
				$is_stale = true;
			} else {
				$is_stale = (int) filemtime( $source ) !== $record['source_mtime'];
			}

			return $is_stale;
		}

		/**
		 * Converts the source image to a square PNG of the given size; returns '' on failure.
		 *
		 * @param string $source Source image path.
		 * @param int    $size Icon width and height in pixels.
		 */
		private function render_png( string $source, int $size ): string {
			$png    = '';
			$editor = wp_get_image_editor( $source );

			if ( is_wp_error( $editor ) ) {
				$this->log_error( 'no image editor can read ' . $source . ': ' . $editor->get_error_message() );
			} else {
				$dimensions = $editor->get_size();
				$side       = min( (int) $dimensions['width'], (int) $dimensions['height'] );
				$offset_x   = intdiv( (int) $dimensions['width'] - $side, 2 );
				$offset_y   = intdiv( (int) $dimensions['height'] - $side, 2 );

				// crop() with a destination size scales up as well as down; resize() never upscales.
				$cropped = $editor->crop( $offset_x, $offset_y, $side, $side, $size, $size );

				if ( is_wp_error( $cropped ) ) {
					$this->log_error( 'could not resize ' . $source . ': ' . $cropped->get_error_message() );
				} else {
					$png = $this->save_png( $editor, $source );
				}
			}

			return $png;
		}

		/**
		 * Saves the editor's image as PNG to a temporary file and returns its bytes, or '' on failure.
		 *
		 * @param \WP_Image_Editor $editor Editor holding the resized image.
		 * @param string           $source Source image path.
		 */
		private function save_png( \WP_Image_Editor $editor, string $source ): string {
			$png       = '';
			$temp_path = trailingslashit( get_temp_dir() ) . 'easy-apple-icon-' . wp_generate_password( 12, false ) . '.png';
			$saved     = $editor->save( $temp_path, 'image/png' );

			if ( is_wp_error( $saved ) ) {
				$this->log_error( 'could not save ' . $source . ' as PNG: ' . $saved->get_error_message() );
			} elseif ( 'image/png' !== ( $saved['mime-type'] ?? '' ) ) {
				// An image_editor_output_format filter can swap the format.
				$this->delete_temp_file( (string) ( $saved['path'] ?? '' ) );
				$this->log_error( 'the image editor saved ' . $source . ' as ' . ( $saved['mime-type'] ?? 'an unknown type' ) . ', not PNG' );
			} else {
				$contents = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file
				$this->delete_temp_file( $saved['path'] );

				if ( ! is_string( $contents ) || ! str_starts_with( $contents, self::PNG_SIGNATURE ) ) {
					$this->log_error( 'the PNG made from ' . $source . ' could not be read back' );
				} else {
					$png = $contents;
				}
			}

			return $png;
		}

		/**
		 * Sends the PNG with its headers; the body is omitted for HEAD.
		 *
		 * @param string $png PNG bytes.
		 */
		private function send_png( string $png ): void {
			status_header( 200 );
			header( 'Content-Type: image/png' );
			header( 'Content-Length: ' . strlen( $png ) );
			header( 'Cache-Control: public, max-age=' . $this->get_cache_max_age() );

			if ( ! $this->is_head_request() ) {
				echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PNG data
			}

			$this->log( 'served ' . strlen( $png ) . ' bytes dynamically for ' . $this->get_request_path() );
		}

		/**
		 * Sends an empty 404 that clients may cache for an hour.
		 */
		private function send_not_found(): void {
			status_header( 404 );
			header( 'Content-Length: 0' );
			header( 'Cache-Control: public, max-age=' . self::NOT_FOUND_MAX_AGE );
		}

		/**
		 * Hands the response to the client so the file write doesn't delay it.
		 */
		private function finish_response(): void {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} else {
				flush();
			}
		}

		/**
		 * Returns whether the request URI starts with the touch-icon prefix; the only check every request pays.
		 */
		private function is_probe_request(): bool {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared only; never output or stored
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

			return str_starts_with( $request_uri, self::PATH_PREFIX );
		}

		/**
		 * Returns the request path without its query string.
		 */
		private function get_request_path(): string {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- matched against an allow-list; the file name is rebuilt, never copied
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
			$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

			return is_string( $path ) ? $path : '';
		}

		/**
		 * Returns whether this is a HEAD request.
		 */
		private function is_head_request(): bool {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared only
			return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'];
		}

		/**
		 * Parses a request path into the file name to write and the icon size, or null when not allow-listed.
		 *
		 * @param string $path Request path.
		 */
		private function parse_probe( string $path ): ?array {
			$matches        = [];
			$is_match       = 1 === preg_match( self::PROBE_PATTERN, $path, $matches );
			$size           = 0;
			$size_in_name   = 0;
			$file_name      = '';
			$probe          = null;
			$width_in_name  = $matches[1] ?? '';
			$height_in_name = $matches[2] ?? '';
			$is_precomposed = '' !== ( $matches[3] ?? '' );

			if ( ! $is_match ) {
				// Not a touch-icon name.
			} elseif ( '' === $width_in_name ) {
				$size = self::BARE_SIZE;
			} elseif ( $width_in_name !== $height_in_name ) {
				// Touch icons are square; -120x152 is not one.
			} elseif ( ! in_array( (int) $width_in_name, $this->get_sizes(), true ) ) {
				// Not in the size allow-list.
			} else {
				$size         = (int) $width_in_name;
				$size_in_name = $size;
			}

			if ( $size > 0 ) {
				$file_name = $this->build_file_name( $size_in_name, $is_precomposed );
			}

			// The rebuilt name must equal the request, which rejects forms such as -0120x0120.
			if ( '' !== $file_name && '/' . $file_name === $path ) {
				$probe = [
					'file_name' => $file_name,
					'size'      => $size,
				];
			}

			return $probe;
		}

		/**
		 * Builds an icon file name; a size of 0 gives the bare name.
		 *
		 * @param int  $size_in_name Size to put in the name, 0 for none.
		 * @param bool $is_precomposed Whether to add -precomposed.
		 */
		private function build_file_name( int $size_in_name, bool $is_precomposed ): string {
			return self::FILE_NAME_STEM
				. ( $size_in_name > 0 ? '-' . $size_in_name . 'x' . $size_in_name : '' )
				. ( $is_precomposed ? '-precomposed' : '' )
				. '.png';
		}

		/**
		 * Returns the size allow-list, falling back to the default when the filter returns anything unusable.
		 */
		private function get_sizes(): array {
			$filtered = apply_filters( self::FILTER_SIZES, self::DEFAULT_SIZES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name
			$sizes    = self::DEFAULT_SIZES;

			if ( ! is_array( $filtered ) || [] === $filtered ) {
				// Not a list; keep the default.
			} else {
				$validated = array_map(
					fn( mixed $size ): int|false => filter_var(
						$size,
						FILTER_VALIDATE_INT,
						[
							'options' => [
								'min_range' => self::MIN_SIZE,
								'max_range' => self::MAX_SIZE,
							],
						]
					),
					$filtered
				);
				$sizes     = in_array( false, $validated, true ) ? self::DEFAULT_SIZES : array_values( array_unique( $validated ) );
			}

			return $sizes;
		}

		/**
		 * Returns the Cache-Control max-age for a served icon.
		 */
		private function get_cache_max_age(): int {
			$filtered = filter_var( apply_filters( self::FILTER_CACHE_MAX_AGE, self::DEFAULT_CACHE_MAX_AGE ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name

			return false === $filtered ? self::DEFAULT_CACHE_MAX_AGE : $filtered;
		}

		/**
		 * Returns the source image path, or '' when there is no usable source.
		 */
		private function get_source_file(): string {
			$default  = $this->get_site_icon_file();
			$filtered = apply_filters( self::FILTER_SOURCE_FILE, $default, $this->get_site_icon_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name

			return is_string( $filtered ) && '' !== $filtered && is_file( $filtered ) && is_readable( $filtered ) ? $filtered : $default;
		}

		/**
		 * Returns the Site Icon's full-size file, or '' when unset or unreadable.
		 */
		private function get_site_icon_file(): string {
			$site_icon_id = $this->get_site_icon_id();
			$path         = $site_icon_id > 0 ? get_attached_file( $site_icon_id ) : false;

			return is_string( $path ) && is_file( $path ) && is_readable( $path ) ? $path : '';
		}

		/**
		 * Returns a path relative to the site root, or ABSPATH, when it lies under one, so PHP-FPM in a chroot and WP-CLI outside it agree.
		 *
		 * @param string $path Absolute path.
		 */
		private function get_portable_path( string $path ): string {
			$normalised_path = wp_normalize_path( $path );
			$site_root       = $this->get_site_root();
			$wordpress_path  = trailingslashit( wp_normalize_path( ABSPATH ) );
			$portable_path   = $normalised_path;

			if ( '' !== $site_root && str_starts_with( $normalised_path, $site_root ) ) {
				$portable_path = substr( $normalised_path, strlen( $site_root ) );
			} elseif ( str_starts_with( $normalised_path, $wordpress_path ) ) {
				$portable_path = substr( $normalised_path, strlen( $wordpress_path ) );
			} else {
				// Outside both; only the absolute path is available.
			}

			return $portable_path;
		}

		/**
		 * Returns the Site Icon attachment ID, 0 when unset.
		 */
		private function get_site_icon_id(): int {
			return (int) get_option( 'site_icon', 0 );
		}

		/**
		 * Returns whether the easy_apple_icon_enabled filter leaves the plugin on (default true).
		 */
		private function is_enabled(): bool {
			$filtered = filter_var( apply_filters( self::FILTER_ENABLED, true ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name

			return $filtered ?? true;
		}

		/**
		 * Returns whether static files are enabled by EASY_APPLE_ICON_STATIC_FILES (default true).
		 */
		private function is_static_enabled(): bool {
			return ! defined( self::CONSTANT_STATIC_FILES ) || filter_var( constant( self::CONSTANT_STATIC_FILES ), FILTER_VALIDATE_BOOLEAN );
		}

		/**
		 * Returns whether the home URL has a path component, such as /blog.
		 */
		private function home_has_path(): bool {
			// The option, not home_url(): multilingual plugins filter home_url() with a language path.
			$home_path = wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );

			return is_string( $home_path ) && '' !== trim( $home_path, '/' );
		}

		/**
		 * Returns whether this file is loaded as a regular plugin.
		 */
		private function is_plugin_context(): bool {
			return defined( 'WP_PLUGIN_DIR' ) && str_starts_with( wp_normalize_path( __FILE__ ), trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) );
		}

		/**
		 * Returns the site root (the directory serving the home URL) with a trailing slash, or '' when it can't be derived.
		 */
		private function get_site_root(): string {
			// Not get_home_path(): it reads SCRIPT_FILENAME, which is only right in wp-admin (see docs/contributing.md, How it works).
			return $this->derive_site_root( ABSPATH, (string) get_option( 'home' ), (string) get_option( 'siteurl' ) );
		}

		/**
		 * Strips the WordPress directory (siteurl less home) from ABSPATH; returns '' when the layout doesn't match.
		 *
		 * @param string $abspath  ABSPATH.
		 * @param string $home_url The home option.
		 * @param string $site_url The siteurl option.
		 */
		private function derive_site_root( string $abspath, string $home_url, string $site_url ): string {
			$home_url      = untrailingslashit( set_url_scheme( $home_url, 'http' ) );
			$site_url      = untrailingslashit( set_url_scheme( $site_url, 'http' ) );
			$abspath       = trailingslashit( wp_normalize_path( $abspath ) );
			$wordpress_dir = 0 === stripos( $site_url, $home_url ) ? trim( substr( $site_url, strlen( $home_url ) ), '/' ) : '';
			$site_root     = '';

			if ( '' === $home_url || 0 === strcasecmp( $home_url, $site_url ) ) {
				$site_root = $abspath;
			} elseif ( '' === $wordpress_dir ) {
				// siteurl isn't under home, so the directory can't be derived.
			} elseif ( ! str_ends_with( strtolower( $abspath ), '/' . strtolower( $wordpress_dir ) . '/' ) ) {
				// The URL path doesn't match the directory layout (an alias or symlink).
			} else {
				$site_root = substr( $abspath, 0, -strlen( $wordpress_dir . '/' ) );
			}

			return $site_root;
		}

		/**
		 * Returns the state option, read from the database, in its normalised shape whatever is stored.
		 */
		private function get_state(): array {
			global $wpdb;

			// The row is read and written directly: an object cache can serve a stale copy, or ignore sets.
			$raw_value  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION_STATE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately uncached, see above
			$stored     = is_string( $raw_value ) ? maybe_unserialize( $raw_value ) : [];
			$stored     = is_array( $stored ) ? $stored : [];
			$files      = [];
			$last_error = null;
			$notices    = is_array( $stored['notices'] ?? null ) ? $stored['notices'] : [];
			$foreign    = is_array( $notices['foreign'] ?? null ) ? $notices['foreign'] : [];

			foreach ( ( is_array( $stored['files'] ?? null ) ? $stored['files'] : [] ) as $file_name => $record ) {
				if ( ! is_string( $file_name ) || 1 !== preg_match( self::FILE_NAME_PATTERN, $file_name ) || ! is_array( $record ) || ! is_string( $record['sha256'] ?? null ) ) {
					continue;
				}

				$files[ $file_name ] = [
					'sha256'       => $record['sha256'],
					'size'         => (int) ( $record['size'] ?? 0 ),
					'bytes'        => (int) ( $record['bytes'] ?? 0 ),
					'source_path'  => (string) ( $record['source_path'] ?? '' ),
					'source_mtime' => (int) ( $record['source_mtime'] ?? 0 ),
					'written_at'   => (int) ( $record['written_at'] ?? 0 ),
				];
			}

			if ( is_array( $stored['last_error'] ?? null ) && is_string( $stored['last_error']['message'] ?? null ) ) {
				$last_error = [
					'message' => $stored['last_error']['message'],
					'at'      => (int) ( $stored['last_error']['at'] ?? 0 ),
				];
			}

			return [
				'generation' => (int) ( $stored['generation'] ?? 0 ),
				'files'      => $files,
				'last_error' => $last_error,
				'notices'    => [
					'foreign'    => array_values( array_filter( $foreign, 'is_string' ) ),
					'unwritable' => filter_var( $notices['unwritable'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				],
			];
		}

		/**
		 * Stores the state option, autoload off, when it differs from what is stored.
		 *
		 * @param array $state Normalised state.
		 */
		private function save_state( array $state ): void {
			global $wpdb;

			$is_saved = true;

			if ( $state !== $this->get_state() ) {
				// The same upsert as add_option(); see get_state() for why the Options API is bypassed.
				$is_saved = false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::OPTION_STATE, maybe_serialize( $state ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see get_state()
				wp_cache_delete( self::OPTION_STATE, 'options' );
			}

			// log_error() saves state itself, so a failed save can only go to the log.
			if ( ! $is_saved ) {
				$this->log_warning( 'could not save the ' . self::OPTION_STATE . ' option: ' . $wpdb->last_error );
			}
		}

		/**
		 * Logs a failure unconditionally and records it as the last error, once per distinct message.
		 *
		 * @param string $message Message, without the prefix.
		 */
		private function log_error( string $message ): void {
			$this->lock_state();

			$state = $this->get_state();

			if ( null === $state['last_error'] || $state['last_error']['message'] !== $message ) {
				error_log( self::LOG_PREFIX . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failures must reach the log in production
				$state['last_error'] = [
					'message' => $message,
					'at'      => time(),
				];
				$this->save_state( $state );
			}

			$this->unlock_state();
		}

		/**
		 * Takes the exclusive state lock, re-entrantly.
		 */
		private function lock_state(): void {
			if ( 0 === $this->lock_depth ) {
				// flock() on this file works across PHP-FPM workers and WP-CLI, chrooted or not.
				$handle = fopen( __FILE__, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a lock handle, not file access

				if ( false === $handle ) {
					$this->log_warning( 'could not open ' . __FILE__ . ' to lock the state; updating it unlocked' );
				} elseif ( ! flock( $handle, LOCK_EX ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above
					$this->log_warning( 'could not lock ' . __FILE__ . '; updating the state unlocked' );
				} else {
					$this->lock_handle = $handle;
				}
			}

			++$this->lock_depth;
		}

		/**
		 * Releases the state lock when the outermost lock_state() call ends.
		 */
		private function unlock_state(): void {
			--$this->lock_depth;

			if ( $this->lock_depth > 0 ) {
				// An outer caller still holds the lock.
			} elseif ( null === $this->lock_handle ) {
				// The lock was never acquired.
			} else {
				flock( $this->lock_handle, LOCK_UN );
				fclose( $this->lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see lock_state()
				$this->lock_handle = null;
			}
		}

		/**
		 * Logs a warning unconditionally; the caller records it in the state.
		 *
		 * @param string $message Message, without the prefix.
		 */
		private function log_warning( string $message ): void {
			error_log( self::LOG_PREFIX . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logged once, when the condition first appears
		}

		/**
		 * Logs routine tracing when WP_DEBUG is on.
		 *
		 * @param string $message Message, without the prefix.
		 */
		private function log( string $message ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( self::LOG_PREFIX . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug tracing
			}
		}
	}

	global $easy_apple_icon;

	$easy_apple_icon = new Plugin();
	$easy_apple_icon->run();
}
