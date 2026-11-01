<?php
/**
 * What each column is called, in words a person would use.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A field's label has to be a translated string, and turning a column name
 * into "Due date" at runtime produces English no translator ever sees. Most
 * columns are the same handful of names across every entity, so they are
 * named once here, and a field only needs its own `label()` when it is
 * genuinely particular.
 *
 * Anything not named here still falls back to the humanised column name, so
 * a new field works before anyone has got round to it — it is simply not
 * translated until they do.
 */
final class FieldLabels {

	/**
	 * Column name to label.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'accepted_at'           => __( 'Accepted', 'cliezoo' ),
			'action'                => __( 'Action', 'cliezoo' ),
			'active'                => __( 'Active', 'cliezoo' ),
			'activity_id'           => __( 'Activity', 'cliezoo' ),
			'actor_id'              => __( 'Who did it', 'cliezoo' ),
			'ai_generated'          => __( 'Written by AI', 'cliezoo' ),
			'allow_download'        => __( 'Downloads allowed', 'cliezoo' ),
			'anchor'                => __( 'Before or after', 'cliezoo' ),
			'answer'                => __( 'Answer', 'cliezoo' ),
			'approved_at'           => __( 'Approved', 'cliezoo' ),
			'approved_by'           => __( 'Approved by', 'cliezoo' ),
			'archived'              => __( 'Archived', 'cliezoo' ),
			'assignee_id'           => __( 'Assigned to', 'cliezoo' ),
			'at_time'               => __( 'Time of day', 'cliezoo' ),
			'attempts'              => __( 'Attempts', 'cliezoo' ),
			'avatar_file_id'        => __( 'Avatar', 'cliezoo' ),
			'bill_rate'             => __( 'Billing rate', 'cliezoo' ),
			'body'                  => __( 'Message', 'cliezoo' ),
			'body_text'             => __( 'Plain text', 'cliezoo' ),
			'bounced_at'            => __( 'Bounced', 'cliezoo' ),
			'builtin_key'           => __( 'Built-in key', 'cliezoo' ),
			'capacity_minutes'      => __( 'Capacity (minutes)', 'cliezoo' ),
			'category'              => __( 'Category', 'cliezoo' ),
			'changes'               => __( 'What changed', 'cliezoo' ),
			'channel'               => __( 'Channel', 'cliezoo' ),
			'channels'              => __( 'Channels', 'cliezoo' ),
			'clicked_at'            => __( 'Clicked', 'cliezoo' ),
			'client_id'             => __( 'Client', 'cliezoo' ),
			'color'                 => __( 'Colour', 'cliezoo' ),
			'config'                => __( 'Settings', 'cliezoo' ),
			'content'               => __( 'Content', 'cliezoo' ),
			'date_field'            => __( 'Date to use', 'cliezoo' ),
			'date_format'           => __( 'Date format', 'cliezoo' ),
			'deactivated_at'        => __( 'Deactivated', 'cliezoo' ),
			'default_value'         => __( 'Default', 'cliezoo' ),
			'description'           => __( 'Description', 'cliezoo' ),
			'digest'                => __( 'Digest', 'cliezoo' ),
			'digest_hour'           => __( 'Digest hour', 'cliezoo' ),
			'done'                  => __( 'Done', 'cliezoo' ),
			'done_at'               => __( 'Done', 'cliezoo' ),
			'download_count'        => __( 'Downloads', 'cliezoo' ),
			'due_at'                => __( 'Due', 'cliezoo' ),
			'due_date'              => __( 'Due date', 'cliezoo' ),
			'duration'              => __( 'Duration', 'cliezoo' ),
			'edited_at'             => __( 'Edited', 'cliezoo' ),
			'email'                 => __( 'Email', 'cliezoo' ),
			'email_template'        => __( 'Email template', 'cliezoo' ),
			'emailed'               => __( 'Emailed', 'cliezoo' ),
			'enabled_at'            => __( 'Turned on', 'cliezoo' ),
			'entity'                => __( 'Record type', 'cliezoo' ),
			'error'                 => __( 'Error', 'cliezoo' ),
			'error_kind'            => __( 'Kind of failure', 'cliezoo' ),
			'error_message'         => __( 'Failure', 'cliezoo' ),
			'escalate'              => __( 'Escalate', 'cliezoo' ),
			'estimated'             => __( 'Estimated', 'cliezoo' ),
			'event'                 => __( 'Event', 'cliezoo' ),
			'expires_at'            => __( 'Expires', 'cliezoo' ),
			'extension'             => __( 'Extension', 'cliezoo' ),
			'field_id'              => __( 'Field', 'cliezoo' ),
			'field_key'             => __( 'Field key', 'cliezoo' ),
			'folder_id'             => __( 'Folder', 'cliezoo' ),
			'handle'                => __( 'Handle', 'cliezoo' ),
			'height'                => __( 'Height', 'cliezoo' ),
			'help'                  => __( 'Help text', 'cliezoo' ),
			'icon'                  => __( 'Icon', 'cliezoo' ),
			'input_tokens'          => __( 'Tokens in', 'cliezoo' ),
			'internal'              => __( 'Internal', 'cliezoo' ),
			'invited_at'            => __( 'Invited', 'cliezoo' ),
			'invited_by'            => __( 'Invited by', 'cliezoo' ),
			'ip'                    => __( 'IP address', 'cliezoo' ),
			'is_builtin'            => __( 'Built in', 'cliezoo' ),
			'is_default'            => __( 'Default', 'cliezoo' ),
			'keywords'              => __( 'Keywords', 'cliezoo' ),
			'kind'                  => __( 'Kind', 'cliezoo' ),
			'label'                 => __( 'Label', 'cliezoo' ),
			'last_digest_at'        => __( 'Last digest', 'cliezoo' ),
			'last_login_at'         => __( 'Last signed in', 'cliezoo' ),
			'last_step'             => __( 'Last step', 'cliezoo' ),
			'last_viewed_at'        => __( 'Last viewed', 'cliezoo' ),
			'list'                  => __( 'List', 'cliezoo' ),
			'locale'                => __( 'Language', 'cliezoo' ),
			'locked'                => __( 'Locked', 'cliezoo' ),
			'matrix'                => __( 'Permissions', 'cliezoo' ),
			'max_count'             => __( 'Maximum', 'cliezoo' ),
			'member_id'             => __( 'Member', 'cliezoo' ),
			'message'               => __( 'Message', 'cliezoo' ),
			'meta'                  => __( 'Details', 'cliezoo' ),
			'mime'                  => __( 'File type', 'cliezoo' ),
			'model'                 => __( 'Model', 'cliezoo' ),
			'ms'                    => __( 'Time taken (ms)', 'cliezoo' ),
			'name'                  => __( 'Name', 'cliezoo' ),
			'next_value'            => __( 'Next number', 'cliezoo' ),
			'object_id'             => __( 'Record', 'cliezoo' ),
			'object_key'            => __( 'Object key', 'cliezoo' ),
			'object_type'           => __( 'Record type', 'cliezoo' ),
			'occurrence'            => __( 'Occurrence', 'cliezoo' ),
			'offset_minutes'        => __( 'Offset (minutes)', 'cliezoo' ),
			'opened_at'             => __( 'Opened', 'cliezoo' ),
			'options'               => __( 'Options', 'cliezoo' ),
			'output_tokens'         => __( 'Tokens out', 'cliezoo' ),
			'parent_id'             => __( 'Parent', 'cliezoo' ),
			'passcode_hash'         => __( 'Passcode', 'cliezoo' ),
			'passkeys'              => __( 'Passkeys', 'cliezoo' ),
			'path'                  => __( 'Path', 'cliezoo' ),
			'pattern'               => __( 'Pattern', 'cliezoo' ),
			'period'                => __( 'Period', 'cliezoo' ),
			'phone'                 => __( 'Phone', 'cliezoo' ),
			'pinned'                => __( 'Pinned', 'cliezoo' ),
			'position'              => __( 'Order', 'cliezoo' ),
			'prefix'                => __( 'Prefix', 'cliezoo' ),
			'preset'                => __( 'Preset', 'cliezoo' ),
			'principal_id'          => __( 'Who', 'cliezoo' ),
			'principal_type'        => __( 'Who', 'cliezoo' ),
			'project_id'            => __( 'Project', 'cliezoo' ),
			'prompt'                => __( 'Prompt', 'cliezoo' ),
			'provider'              => __( 'Provider', 'cliezoo' ),
			'purpose'               => __( 'Purpose', 'cliezoo' ),
			'quiet_end'             => __( 'Quiet hours end', 'cliezoo' ),
			'quiet_start'           => __( 'Quiet hours start', 'cliezoo' ),
			'quiet_weekends'        => __( 'Quiet at weekends', 'cliezoo' ),
			'rate_currency'         => __( 'Rate currency', 'cliezoo' ),
			'read_at'               => __( 'Read', 'cliezoo' ),
			'recipients'            => __( 'Recipients', 'cliezoo' ),
			'recovery_codes'        => __( 'Recovery codes', 'cliezoo' ),
			'repeat_minutes'        => __( 'Repeat (minutes)', 'cliezoo' ),
			'require_email'         => __( 'Ask for an email address', 'cliezoo' ),
			'required'              => __( 'Required', 'cliezoo' ),
			'resolved'              => __( 'Resolved', 'cliezoo' ),
			'resolved_at'           => __( 'Resolved', 'cliezoo' ),
			'resolved_by'           => __( 'Resolved by', 'cliezoo' ),
			'result'                => __( 'Result', 'cliezoo' ),
			'revoked_at'            => __( 'Revoked', 'cliezoo' ),
			'role'                  => __( 'Role', 'cliezoo' ),
			'rule_id'               => __( 'Rule', 'cliezoo' ),
			'schedule_name'         => __( 'Schedule', 'cliezoo' ),
			'scope_id'              => __( 'Applies to', 'cliezoo' ),
			'scope_type'            => __( 'Applies to', 'cliezoo' ),
			'sent_at'               => __( 'Sent', 'cliezoo' ),
			'series'                => __( 'Series', 'cliezoo' ),
			'settings'              => __( 'Settings', 'cliezoo' ),
			'shared'                => __( 'Shared', 'cliezoo' ),
			'show_icon'             => __( 'Show the icon', 'cliezoo' ),
			'size'                  => __( 'Size', 'cliezoo' ),
			'snoozed_until'         => __( 'Snoozed until', 'cliezoo' ),
			'status'                => __( 'Status', 'cliezoo' ),
			'stop_statuses'         => __( 'Stop at', 'cliezoo' ),
			'storage_connection_id' => __( 'Storage connection', 'cliezoo' ),
			'storage_driver'        => __( 'Storage', 'cliezoo' ),
			'subject'               => __( 'Subject', 'cliezoo' ),
			'summary'               => __( 'Summary', 'cliezoo' ),
			'tab'                   => __( 'Tab', 'cliezoo' ),
			'tags'                  => __( 'Tags', 'cliezoo' ),
			'target_type'           => __( 'Record type', 'cliezoo' ),
			'task'                  => __( 'Task', 'cliezoo' ),
			'template'              => __( 'Template', 'cliezoo' ),
			'thumbs'                => __( 'Thumbnails', 'cliezoo' ),
			'time_format'           => __( 'Time format', 'cliezoo' ),
			'timezone'              => __( 'Time zone', 'cliezoo' ),
			'title'                 => __( 'Title', 'cliezoo' ),
			'to_email'              => __( 'To', 'cliezoo' ),
			'token_hash'            => __( 'Token', 'cliezoo' ),
			'totp_enabled'          => __( 'Authenticator', 'cliezoo' ),
			'totp_secret'           => __( 'Authenticator secret', 'cliezoo' ),
			'type'                  => __( 'Type', 'cliezoo' ),
			'upload_parts'          => __( 'Upload parts', 'cliezoo' ),
			'url'                   => __( 'Address', 'cliezoo' ),
			'used_at'               => __( 'Used', 'cliezoo' ),
			'user_agent'            => __( 'Browser', 'cliezoo' ),
			'user_id'               => __( 'User', 'cliezoo' ),
			'value'                 => __( 'Value', 'cliezoo' ),
			'value_date'            => __( 'Value', 'cliezoo' ),
			'value_number'          => __( 'Value', 'cliezoo' ),
			'value_short'           => __( 'Value', 'cliezoo' ),
			'value_text'            => __( 'Value', 'cliezoo' ),
			'version'               => __( 'Version', 'cliezoo' ),
			'view_count'            => __( 'Views', 'cliezoo' ),
			'visible_to_client'     => __( 'Visible to the client', 'cliezoo' ),
			'visible_to_clients'    => __( 'Visible to clients', 'cliezoo' ),
			'watching'              => __( 'Following', 'cliezoo' ),
			'week_start'            => __( 'Week starts on', 'cliezoo' ),
			'width'                 => __( 'Width', 'cliezoo' ),
			'yearly_reset'          => __( 'Resets each year', 'cliezoo' ),
		);
	}

	/**
	 * What to call a column.
	 *
	 * @param string $column Column name, with or without an `_id` suffix.
	 * @return string
	 */
	public static function for_column( string $column ): string {
		$labels = self::all();
		if ( isset( $labels[ $column ] ) ) {
			return $labels[ $column ];
		}
		if ( isset( $labels[ $column . '_id' ] ) ) {
			return $labels[ $column . '_id' ];
		}
		return ucfirst( str_replace( '_', ' ', $column ) );
	}
}
