/**
 * The "Powered by Cliezoo" credit on client-facing screens.
 *
 * Off unless the workspace turns it on in Settings → General: WordPress.org
 * guideline 10 does not allow a public credit without permission, and
 * clients should see the workspace's brand, not ours. It is plain words,
 * not a link, for the same reason. Emails read the same switch on the
 * server.
 */
import { __ } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';

export function PoweredBy( { className }: { className?: string } ) {
	if ( ! getBoot().branding.powered_by ) {
		return null;
	}
	return (
		<p className={ `text-center text-xs text-subtle ${ className ?? '' }` }>
			{ __( 'Powered by Cliezoo', 'cliezoo' ) }
		</p>
	);
}
