<?php

namespace Lauzis\WpPackages\Logs;

/**
 * The "Send a test message" button beside the Slack webhook setting.
 *
 * Ordinary log traffic is fire-and-forget, so a webhook Slack rejects fails
 * quietly by design — which leaves somebody who has just pasted a URL with no
 * way of knowing whether it works short of causing an error on purpose. This is
 * that way: it posts, waits, and says what came back.
 *
 * Like Viewer, it renders markup and answers a request; whether the button is
 * on the page is the plugin's decision, taken by registering the schema's
 * "@callback:logs_slack_test" against render(). Unlike Viewer it does own a
 * request, because there is nothing plugin-specific about posting to Slack and
 * asking every plugin to write the same handler is what this package exists to
 * avoid.
 *
 * The button posts the URL currently in the field rather than the stored one:
 * pasting a webhook and pressing Test before saving is the obvious thing to do,
 * and testing the previous value there would answer a question nobody asked.
 */
class SlackTester {

	/** @var Logger */
	private $logger;

	/** @var string */
	private $capability;

	/** @var bool */
	private $booted = false;

	/**
	 * @param Logger $logger The plugin's logger.
	 * @param array  $config {
	 *     @type string $capability Capability required to send a test message.
	 *                              Default 'manage_options' — the same bar as
	 *                              editing the setting it tests.
	 * }
	 */
	public function __construct( Logger $logger, array $config = array() ) {
		$this->logger     = $logger;
		$this->capability = isset( $config['capability'] ) ? (string) $config['capability'] : 'manage_options';
	}

	/**
	 * Registers the AJAX endpoint. Idempotent, and safe to call on every
	 * request — the handler only runs for its own action.
	 *
	 * @return $this
	 */
	public function boot() {
		if ( $this->booted ) {
			return $this;
		}

		$this->booted = true;

		add_action( 'wp_ajax_' . $this->action(), array( $this, 'handle' ) );

		return $this;
	}

	/** The AJAX action, namespaced per plugin. */
	public function action() {
		return $this->logger->slug() . '_slack_test';
	}

	/**
	 * The button, as markup.
	 *
	 * A button and not a link or a submit: this is drawn inside the settings
	 * page's own form, where a submit would save the page and a form of its own
	 * would not be valid markup.
	 *
	 * @return string
	 */
	public function render() {
		$action = $this->action();

		ob_start();
		?>
		<p class="wp-packages-slack-test">
			<button type="button" class="button" data-wp-packages-slack-test="<?php echo esc_attr( $action ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( $action ) ); ?>"
				data-sending="<?php echo esc_attr__( 'Sending…', 'wp-plugin-packages' ); ?>">
				<?php echo esc_html__( 'Send a test message', 'wp-plugin-packages' ); ?>
			</button>
			<span class="wp-packages-slack-test-result" role="status" aria-live="polite" style="margin-left:8px;"></span>
		</p>
		<?php
		// Inline rather than an enqueued file: the markup is only ever rendered
		// by this method, so a script that outlives it has nothing to bind to.
		?>
		<script>
		( function () {
			if ( window.wpPackagesSlackTest ) {
				return;
			}

			window.wpPackagesSlackTest = true;

			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-wp-packages-slack-test]' );

				if ( ! button ) {
					return;
				}

				event.preventDefault();

				var result = button.parentNode.querySelector( '.wp-packages-slack-test-result' );
				// Whatever is in the field right now, saved or not. Matched on a
				// suffix because the id carries the plugin's own prefix.
				var field  = document.querySelector( 'input[name*="logs_slack_webhook"]' );
				var body   = new URLSearchParams();

				body.append( 'action', button.getAttribute( 'data-wp-packages-slack-test' ) );
				body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
				body.append( 'url', field ? field.value : '' );

				button.disabled  = true;
				result.className = 'wp-packages-slack-test-result';
				result.textContent = button.getAttribute( 'data-sending' );

				fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( payload ) {
						result.className = 'wp-packages-slack-test-result notice-' + ( payload.success ? 'success' : 'error' );
						result.style.color = payload.success ? '#008a20' : '#d63638';
						result.textContent = payload.data;
					} )
					.catch( function ( error ) {
						result.style.color = '#d63638';
						result.textContent = String( error );
					} )
					.then( function () { button.disabled = false; } );
			} );
		}() );
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/** Answers the button: posts to Slack and reports what came back. */
	public function handle() {
		check_ajax_referer( $this->action(), 'nonce' );

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( __( 'You are not allowed to do that.', 'wp-plugin-packages' ), 403 );
		}

		// Unslashed, not sanitized: a URL is checked by being an https one,
		// which Logger does, and sanitize_text_field() would quietly mangle
		// rather than reject anything that is not.
		$url    = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '';
		$result = $this->logger->slackTest( is_string( $url ) ? $url : '' );

		if ( true === $result ) {
			wp_send_json_success( __( 'Sent — check the Slack channel.', 'wp-plugin-packages' ) );
		}

		wp_send_json_error( $result );
	}
}
