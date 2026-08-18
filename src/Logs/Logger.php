<?php

namespace Lauzis\WpPackages\Logs;

/**
 * File-based logger shared by the plugins.
 *
 * One instance per plugin, obtained through WpPackages_Registry::logger(). Log
 * lines keep the format the plugins already use, so existing log files stay
 * readable after migrating:
 *
 *     [2026-07-31 09:14:02] [cron] Batch finished. | {"processed":12}
 *
 * Files are named "{channel}-YYYY-MM-DD.log" and the default channel is the
 * plugin slug, which reproduces the previous naming exactly.
 */
class Logger {

	/** @var string */
	private $slug;

	/** @var string Absolute path to the log directory, with trailing slash. */
	private $dir;

	/** @var callable|bool|null */
	private $enabled;

	/** @var bool Used when the schema has not been registered yet. */
	private $enabled_default;

	/** @var string */
	private $default_channel;

	/** @var callable|string|null */
	private $slack_webhook;

	/** @var callable|string|null */
	private $slack_level;

	/** @var bool Guards against a Slack failure logging its way back in here. */
	private $slack_sending = false;

	/**
	 * @param string $slug   Plugin slug.
	 * @param array  $config {
	 *     @type string        $dir      Absolute path to the log directory. Defaults
	 *                                   to uploads/{slug}-logs/.
	 *     @type callable|bool $enabled  Whether logging is on. A callable is
	 *                                   resolved per call, so a settings change
	 *                                   takes effect immediately. Omit it and the
	 *                                   component reads its own 'logs_enabled'
	 *                                   setting from the plugin's settings page.
	 *     @type bool          $enabled_default Value to assume before the schema
	 *                                   has been registered — logging can happen
	 *                                   during bootstrap or cron, earlier than
	 *                                   carbon_fields_register_fields. Default false.
	 *     @type string        $channel  Default channel name. Defaults to $slug.
	 *     @type callable|string $slack_webhook Incoming-webhook URL entries are
	 *                                   mirrored to. A callable is resolved per
	 *                                   call. Omit it and the component reads its
	 *                                   own 'logs_slack_webhook' setting.
	 *     @type callable|string $slack_level 'errors' (default) or 'all'. Omit it
	 *                                   and the component reads its own
	 *                                   'logs_slack_level' setting.
	 * }
	 */
	public function __construct( $slug, array $config = array() ) {
		$this->slug            = $this->sanitize_channel( $slug );
		$this->enabled         = isset( $config['enabled'] ) ? $config['enabled'] : null;
		$this->enabled_default = ! empty( $config['enabled_default'] );
		$this->default_channel = isset( $config['channel'] ) ? $this->sanitize_channel( $config['channel'] ) : $this->slug;
		$this->slack_webhook   = isset( $config['slack_webhook'] ) ? $config['slack_webhook'] : null;
		$this->slack_level     = isset( $config['slack_level'] ) ? $config['slack_level'] : null;

		if ( isset( $config['dir'] ) ) {
			$this->dir = rtrim( str_replace( '\\', '/', $config['dir'] ), '/' ) . '/';
		} else {
			$uploads   = wp_upload_dir();
			$this->dir = str_replace( '\\', '/', $uploads['basedir'] ) . '/' . $this->slug . '-logs/';
		}
	}

	/**
	 * Returns true when logging is currently switched on.
	 *
	 * With no explicit 'enabled' config the component reads the 'logs_enabled'
	 * field from its own schema, so a plugin that registers settings/logs.json
	 * does not have to wire the setting through by hand. The bare id is used,
	 * so this still works for a plugin that mapped the field onto a legacy
	 * option key. Before that page exists the answer is 'enabled_default' — see
	 * settings().
	 */
	public function isEnabled() {
		if ( null === $this->enabled ) {
			$settings = $this->settings();

			if ( ! $settings ) {
				return (bool) $this->enabled_default;
			}

			return (bool) $settings->get( 'logs_enabled', $this->enabled_default );
		}

		return is_callable( $this->enabled ) ? (bool) call_user_func( $this->enabled ) : (bool) $this->enabled;
	}

	/** Absolute path to the log directory, with trailing slash. */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Appends an entry to today's log file, if logging is enabled.
	 *
	 * Mirrored to Slack when a webhook is configured and its level is 'all'.
	 * Slack only ever sees what was actually recorded, so an entry dropped
	 * because logging is off is not posted either.
	 *
	 * @param string      $action  Short label.
	 * @param string      $message Human-readable message.
	 * @param array       $context Key-value context, appended as JSON.
	 * @param string|null $channel Alternate log stream. Defaults to the plugin's own.
	 * @return bool True on success; false if disabled or the write failed.
	 */
	public function add( $action, $message = '', array $context = array(), $channel = null ) {
		if ( ! $this->isEnabled() ) {
			return false;
		}

		$line    = $this->format( $action, $message, $context );
		$written = $this->write( $line, $channel );

		$this->slack( $line, $channel, false );

		return $written;
	}

	/**
	 * Logs a failure unconditionally: always to PHP's error_log, to Slack when a
	 * webhook is configured, and to the plugin's own file as well when logging
	 * is enabled.
	 *
	 * Use for failures that should never be silent.
	 *
	 * @param string $action  Short label.
	 * @param string $message Human-readable message.
	 * @param array  $context Key-value context, appended as JSON.
	 */
	public function error( $action, $message = '', array $context = array() ) {
		$line = $this->format( $action, $message, $context );

		error_log( $this->slug . ': ' . $line );

		$this->slack( $line, null, true );

		if ( $this->isEnabled() ) {
			$this->write( $line, null );
		}
	}

	/**
	 * Posts a test message to the configured Slack webhook.
	 *
	 * Unlike ordinary log traffic this waits for Slack's answer, so a settings
	 * screen can tell the user whether the URL actually works — a fire-and-forget
	 * post cannot.
	 *
	 * @return true|string True on success, otherwise the reason it failed.
	 */
	public function slackTest() {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return 'The WordPress HTTP API is not available.';
		}

		$url = $this->slackWebhook();

		if ( '' === $url ) {
			return __( 'No Slack webhook URL is configured.', 'wp-plugin-packages' );
		}

		$payload  = $this->payload( 'Test message from ' . $this->slug . '.', null, false );
		$response = wp_remote_post( $url, $this->slack_request( $payload, true ) );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			/* translators: 1: HTTP status code, 2: response body Slack returned. */
			return sprintf( __( 'Slack answered %1$d: %2$s', 'wp-plugin-packages' ), $code, wp_remote_retrieve_body( $response ) );
		}

		return true;
	}

	/**
	 * Deletes the daily log files for a channel.
	 *
	 * @param string|null $channel Defaults to the plugin's own channel. Pass '*'
	 *                             to clear every channel in the log directory.
	 * @return bool True if the directory was readable and deletion was attempted.
	 */
	public function clear( $channel = null ) {
		if ( ! is_dir( $this->dir ) ) {
			return false;
		}

		foreach ( $this->paths( $channel ) as $file ) {
			@unlink( $file );
		}

		return true;
	}

	/**
	 * Total number of log entries (lines) across a channel's daily files.
	 *
	 * @param string|null $channel Defaults to the plugin's own channel.
	 * @return int
	 */
	public function count( $channel = null ) {
		$total = 0;

		foreach ( $this->files( $channel ) as $file ) {
			$total += $file['count'];
		}

		return $total;
	}

	/**
	 * Lists a channel's daily log files, newest first.
	 *
	 * @param string|null $channel Defaults to the plugin's own channel.
	 * @return array[] Each entry: ['file' => string, 'name' => string, 'date' => string, 'count' => int]
	 */
	public function files( $channel = null ) {
		$result = array();

		foreach ( $this->paths( $channel ) as $file ) {
			$name  = basename( $file );
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			$result[] = array(
				'file'  => $file,
				'name'  => $name,
				'date'  => preg_replace( '/^.*?-(\d{4}-\d{2}-\d{2})\.log$/', '$1', $name ),
				'count' => count( $lines ? $lines : array() ),
			);
		}

		return $result;
	}

	/**
	 * Reads back a channel's entries, newest file first.
	 *
	 * @param string|null $channel Defaults to the plugin's own channel.
	 * @param int         $limit   Maximum lines to return; 0 for all.
	 * @return string[]
	 */
	public function read( $channel = null, $limit = 0 ) {
		$lines = array();

		foreach ( $this->paths( $channel ) as $file ) {
			$contents = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			if ( $contents ) {
				$lines = array_merge( $lines, array_reverse( $contents ) );
			}

			if ( $limit > 0 && count( $lines ) >= $limit ) {
				break;
			}
		}

		return $limit > 0 ? array_slice( $lines, 0, $limit ) : $lines;
	}

	/**
	 * Absolute paths of a channel's log files, newest first.
	 *
	 * @param string|null $channel Channel name, or '*' for every channel.
	 * @return string[]
	 */
	private function paths( $channel = null ) {
		if ( ! is_dir( $this->dir ) ) {
			return array();
		}

		$prefix = ( '*' === $channel ) ? '*' : $this->sanitize_channel( null === $channel ? $this->default_channel : $channel );
		$files  = glob( $this->dir . $prefix . '-*.log' );

		if ( ! $files ) {
			return array();
		}

		rsort( $files );

		return $files;
	}

	/**
	 * Builds a single log line.
	 *
	 * An empty $action omits the action segment entirely, which keeps the
	 * format of audit-style streams that only carry a message.
	 */
	private function format( $action, $message, array $context ) {
		$prefix = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ';
		$line   = '' === $action ? $prefix . $message : $prefix . '[' . $action . '] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}

		return $line;
	}

	/** Appends a line to a channel's file for today. */
	private function write( $line, $channel ) {
		if ( ! $this->ensure_dir() ) {
			return false;
		}

		$channel = $this->sanitize_channel( null === $channel ? $this->default_channel : $channel );
		$file    = $this->dir . $channel . '-' . gmdate( 'Y-m-d' ) . '.log';

		return (bool) file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Creates the log directory if needed and keeps it non-browsable.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	private function ensure_dir() {
		if ( ! is_dir( $this->dir ) ) {
			wp_mkdir_p( $this->dir );
		}

		if ( ! is_dir( $this->dir ) || ! is_writable( $this->dir ) ) {
			return false;
		}

		$index = $this->dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' );
		}

		// Defence in depth on Apache: the log directory usually lives under
		// uploads/, which is web-served. index.php stops directory listing but
		// not direct hits on a known filename.
		$htaccess = $this->dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		return true;
	}

	/**
	 * Mirrors one log line to the configured Slack incoming webhook.
	 *
	 * Fire-and-forget: the request is non-blocking, because a log call must not
	 * make the page wait on Slack, and because a page that logs repeatedly would
	 * otherwise pay that latency each time. The trade-off is that a webhook Slack
	 * rejects fails silently — slackTest() is the way to check one.
	 *
	 * @param string      $line     Formatted log line.
	 * @param string|null $channel  Channel the line was written to.
	 * @param bool        $is_error Whether this came from error().
	 * @return bool Whether the request was made.
	 */
	private function slack( $line, $channel, $is_error ) {
		// A failure below reports through error_log() directly rather than
		// through error(), but a filter on the HTTP request could still log, and
		// that log call would come straight back here.
		if ( $this->slack_sending || ! function_exists( 'wp_remote_post' ) ) {
			return false;
		}

		if ( ! $is_error && 'all' !== $this->slackLevel() ) {
			return false;
		}

		$url = $this->slackWebhook();

		if ( '' === $url ) {
			return false;
		}

		$this->slack_sending = true;

		$response = wp_remote_post( $url, $this->slack_request( $this->payload( $line, $channel, $is_error ), false ) );

		$this->slack_sending = false;

		if ( is_wp_error( $response ) ) {
			error_log( $this->slug . ': Slack webhook failed: ' . $response->get_error_message() );

			return false;
		}

		return true;
	}

	/**
	 * Arguments for a webhook request.
	 *
	 * @param array $payload  Slack message payload.
	 * @param bool  $blocking Whether to wait for the response.
	 * @return array
	 */
	private function slack_request( array $payload, $blocking ) {
		return array(
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
			'timeout'  => 10,
			'blocking' => (bool) $blocking,
		);
	}

	/**
	 * Builds the Slack message for a log line.
	 *
	 * The line goes in a code block so the timestamp and the JSON context survive
	 * intact. Slack offers no way to escape a fence inside one, so backticks in
	 * the line are replaced rather than allowed to break the block.
	 *
	 * @param string      $line
	 * @param string|null $channel
	 * @param bool        $is_error
	 * @return array
	 */
	private function payload( $line, $channel, $is_error ) {
		$channel = $this->sanitize_channel( null === $channel ? $this->default_channel : $channel );
		$site    = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$heading = ( $is_error ? ':rotating_light: ' : ':memo: ' ) . '*' . $this->slug . '*'
			. ( '' !== $site ? ' — ' . $site : '' ) . ' `' . $channel . '`';

		// Slack rejects a message beyond roughly 40k characters, and a log line
		// carrying a large context can get there. Truncated is more useful than
		// refused. Cut on characters where mbstring allows it: half a multibyte
		// character is not valid UTF-8, and json_encode() would refuse the lot.
		if ( strlen( $line ) > 3000 ) {
			$line = ( function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 3000, 'UTF-8' ) : substr( $line, 0, 3000 ) ) . ' [...]';
		}

		return array(
			'text'         => $heading . "\n```" . str_replace( '```', "'''", $line ) . '```',
			'mrkdwn'       => true,
			'unfurl_links' => false,
		);
	}

	/**
	 * The plugin's settings page, if it has been built.
	 *
	 * Deliberately does not go through WpPackages_Registry::settings(), which
	 * would construct one: a page built here, before the plugin passes its own
	 * title and parent menu, is the one the registry caches and later hands back
	 * to the plugin, and its settings screen loses that configuration. Logging
	 * happens during bootstrap and cron, long before a plugin registers its
	 * page, so the logger must be able to find nothing and carry on.
	 *
	 * @return \Lauzis\WpPackages\Settings\Settings|null
	 */
	private function settings() {
		return \Lauzis\WpPackages\Settings\Settings::existing( $this->slug );
	}

	/**
	 * The webhook URL currently configured, or '' when there is none.
	 *
	 * With no explicit 'slack_webhook' config the component reads the
	 * 'logs_slack_webhook' field from its own schema, exactly as isEnabled()
	 * reads 'logs_enabled'. Anything that is not an https URL counts as unset:
	 * the URL is itself the credential, and posting it over plain http would put
	 * it on the wire in clear.
	 *
	 * @return string
	 */
	private function slackWebhook() {
		$value = $this->slack_webhook;

		if ( null === $value ) {
			$settings = $this->settings();

			if ( ! $settings ) {
				return '';
			}

			$value = $settings->get( 'logs_slack_webhook', '' );
		} elseif ( ! is_string( $value ) && is_callable( $value ) ) {
			$value = call_user_func( $value );
		}

		$value = trim( (string) $value );

		return 0 === strpos( $value, 'https://' ) ? $value : '';
	}

	/**
	 * Which entries reach Slack: 'errors' (the default) or 'all'.
	 *
	 * @return string
	 */
	private function slackLevel() {
		$value = $this->slack_level;

		if ( null === $value ) {
			$settings = $this->settings();

			if ( ! $settings ) {
				return 'errors';
			}

			$value = $settings->get( 'logs_slack_level', 'errors' );
		} elseif ( ! is_string( $value ) && is_callable( $value ) ) {
			$value = call_user_func( $value );
		}

		return 'all' === $value ? 'all' : 'errors';
	}

	/**
	 * Restricts a channel name to characters that are safe in a filename,
	 * so a caller-supplied channel cannot escape the log directory.
	 *
	 * @param string $channel
	 * @return string
	 */
	private function sanitize_channel( $channel ) {
		$channel = strtolower( (string) $channel );
		$channel = preg_replace( '/[^a-z0-9_-]/', '', $channel );

		return '' !== $channel ? $channel : 'log';
	}
}
