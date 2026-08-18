<?php

namespace Lauzis\WpPackages\Logs;

/**
 * Shows what the logger has written.
 *
 * The logging setting has always been a checkbox with nothing behind it: a site
 * could switch logging on and then had to reach the files over FTP to read a
 * single line. Every plugin here has the same gap, and the reading, the listing
 * and the clearing are identical in all of them, so they live here.
 *
 * What is deliberately NOT here is whether to show any of it. That is the
 * plugin's decision — it owns its settings page, its capability and its menu —
 * so this renders markup and nothing else. It registers no hooks, adds no page
 * and reads no request of its own.
 *
 * The one exception is clearing, which has to be a link to somewhere. The link
 * is rendered here and the action that answers it belongs to the plugin, whose
 * name is passed in; without one the button is simply not drawn.
 */
class Viewer {

	/** Lines shown by default. Enough to see a sequence, short enough to scan. */
	const LINES = 200;

	/** @var Logger */
	private $logger;

	/** @var int */
	private $lines;

	/** @var string admin-post action that clears the log, or '' for no button. */
	private $clear_action;

	/** @var string|null */
	private $channel;

	/**
	 * @param Logger $logger The plugin's logger.
	 * @param array  $config {
	 *     @type int         $lines   Lines to show. Default self::LINES.
	 *     @type string      $clear   admin-post action name for the clear link.
	 *     @type string|null $channel Channel to read. Default the plugin's own.
	 * }
	 */
	public function __construct( Logger $logger, array $config = array() ) {
		$this->logger       = $logger;
		$this->lines        = isset( $config['lines'] ) ? (int) $config['lines'] : self::LINES;
		$this->clear_action = isset( $config['clear'] ) ? (string) $config['clear'] : '';
		$this->channel      = isset( $config['channel'] ) ? $config['channel'] : null;
	}

	/**
	 * The panel, as markup.
	 *
	 * @return string
	 */
	public function render() {
		$files   = $this->logger->files( $this->channel );
		$entries = $this->logger->read( $this->channel, $this->lines );
		$total   = 0;

		foreach ( $files as $file ) {
			$total += $file['count'];
		}

		ob_start();
		?>
		<div class="wp-packages-logs">
			<p class="description">
				<?php
				if ( $this->logger->isEnabled() ) {
					printf(
						/* translators: %s: directory path */
						esc_html__( 'Recording to %s, one file per day.', 'wp-plugin-packages' ),
						'<code>' . esc_html( $this->relative( $this->logger->dir() ) ) . '</code>'
					);
				} else {
					// Said plainly rather than by showing an empty box: a log
					// that stopped days ago and a log that was never on look
					// exactly alike from here.
					esc_html_e( 'Logging is off, so nothing new is being recorded. Anything below was written while it was on.', 'wp-plugin-packages' );
				}
				?>
			</p>

			<?php if ( ! $files ) : ?>
				<p><em><?php esc_html_e( 'Nothing has been logged yet.', 'wp-plugin-packages' ); ?></em></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped" style="max-width:520px;margin-bottom:14px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Day', 'wp-plugin-packages' ); ?></th>
							<th><?php esc_html_e( 'Entries', 'wp-plugin-packages' ); ?></th>
							<th><?php esc_html_e( 'Size', 'wp-plugin-packages' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $file ) : ?>
							<tr>
								<td><code><?php echo esc_html( $file['date'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $file['count'] ) ); ?></td>
								<td><?php echo esc_html( size_format( (int) @filesize( $file['file'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php
					printf(
						/* translators: 1: number of lines shown, 2: total entries */
						esc_html__( 'Showing the most recent %1$s of %2$s, newest first.', 'wp-plugin-packages' ),
						esc_html( number_format_i18n( min( $this->lines, $total ) ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</p>

				<?php // Scrolled rather than paged: reading a log is scanning it. ?>
				<pre class="wp-packages-log" style="max-height:420px;overflow:auto;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;"><?php
					echo esc_html( implode( "\n", $entries ) );
				?></pre>

				<?php echo $this->clearButton(); // Escaped in the method. ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A link that asks the plugin to empty the log.
	 *
	 * A link and not a button: this panel is drawn inside the settings page's
	 * own form, and a form inside a form is neither valid nor recoverable.
	 *
	 * @return string
	 */
	private function clearButton() {
		if ( '' === $this->clear_action ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg( 'action', $this->clear_action, admin_url( 'admin-post.php' ) ),
			$this->clear_action
		);

		return '<p><a class="button" href="' . esc_url( $url ) . '" onclick="return confirm(\''
			. esc_js( __( 'Delete every log file for this plugin?', 'wp-plugin-packages' ) )
			. '\');">' . esc_html__( 'Clear the log', 'wp-plugin-packages' ) . '</a></p>';
	}

	/**
	 * A path as it reads to somebody who knows where WordPress is installed.
	 *
	 * @param string $path
	 * @return string
	 */
	private function relative( $path ) {
		return defined( 'ABSPATH' ) ? str_replace( ABSPATH, '', $path ) : $path;
	}
}
