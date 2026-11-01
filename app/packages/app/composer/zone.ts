/**
 * Where the person writing is, for "tomorrow at 9:00".
 *
 * Their own timezone when they set one; otherwise the workspace's, which is
 * where most of a team works; otherwise the one their computer is in. Never
 * UTC by default — a member who never opened their profile would have
 * every "tomorrow morning" arrive in the middle of somebody's night.
 */
import { getBoot } from '@cliezoo/data';

export function senderZone(): string {
	const boot = getBoot();
	const own = boot.user?.timezone ?? '';
	if ( own ) {
		return own;
	}
	const workspace = boot.workspace?.timezone ?? '';
	if ( workspace ) {
		return workspace;
	}
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
	} catch {
		return 'UTC';
	}
}
