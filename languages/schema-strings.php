<?php
/**
 * Translation manifest — GENERATED, do not edit.
 *
 * Produced by bin/schema-i18n from the settings schema JSON. Exists so
 * `wp i18n make-pot` can see strings that live in JSON rather than in PHP.
 * Never loaded at runtime.
 *
 * Regenerate with:
 *   bin/schema-i18n --domain=wp-plugin-packages --out=languages/schema-strings.php settings/logs.json settings/llm.json
 */

return;

__( '@callback:logs_slack_test', 'wp-plugin-packages' );
__( 'AI Provider', 'wp-plugin-packages' );
__( 'API token for the selected provider. Not needed, and not stored, for the commandline provider.', 'wp-plugin-packages' );
__( 'Access Key', 'wp-plugin-packages' );
__( 'Claude', 'wp-plugin-packages' );
__( 'Command Timeout (seconds)', 'wp-plugin-packages' );
__( 'Commandline - local model or CLI', 'wp-plugin-packages' );
__( 'Commandline Command', 'wp-plugin-packages' );
__( 'Enable logging', 'wp-plugin-packages' );
__( 'Endpoint', 'wp-plugin-packages' );
__( 'Errors are posted even when file logging is off. Every log entry means one HTTP request per entry, and Slack rate-limits a webhook to about one message per second — only worth it on a quiet site or while chasing a specific problem.', 'wp-plugin-packages' );
__( 'Errors only', 'wp-plugin-packages' );
__( 'Every log entry', 'wp-plugin-packages' );
__( 'Gemini', 'wp-plugin-packages' );
__( 'How long a single call may take before it is killed. The server\'s own limits (max_execution_time, proxy read timeouts) must all exceed this for a longer value to have any effect.', 'wp-plugin-packages' );
__( 'How the request is made. The commandline option keeps API keys out of WordPress: the script owns its own credentials.', 'wp-plugin-packages' );
__( 'Logging', 'wp-plugin-packages' );
__( 'OpenAI', 'wp-plugin-packages' );
__( 'Optional: an incoming webhook URL (https://hooks.slack.com/services/...) that log entries are also posted to. Leave empty to send nothing to Slack. Anyone holding this URL can post to the channel, so treat it as a credential. The test button posts to whatever is in this field, saved or not.', 'wp-plugin-packages' );
__( 'Optional: override the default API endpoint for the selected provider.', 'wp-plugin-packages' );
__( 'Provider', 'wp-plugin-packages' );
__( 'Records plugin actions to daily log files in the uploads directory. Useful when diagnosing a problem; leave it off otherwise.', 'wp-plugin-packages' );
__( 'Send to Slack', 'wp-plugin-packages' );
__( 'Shell command to run. A single JSON argument is appended containing the prompt and the content to process; the command must print the model\'s response on stdout.', 'wp-plugin-packages' );
__( 'Slack webhook URL', 'wp-plugin-packages' );
__( 'Which language model service to call, and how to reach it.', 'wp-plugin-packages' );
__( 'Write plugin actions to a daily log file. Errors are always recorded in PHP\'s error log regardless of this setting.', 'wp-plugin-packages' );
