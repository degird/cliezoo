/**
 * In-app 404 (branded, no vendor name).
 */
import { __ } from '@wordpress/i18n';
import { Compass } from 'lucide-react';
import { EmptyState, Link } from '@cliezoo/ui';

export function NotFound() {
	return (
		<EmptyState
			icon={ <Compass size={ 22 } aria-hidden="true" /> }
			title={ __( 'Page not found', 'cliezoo' ) }
			actions={
				<Link href="/" variant="primary">
					{ __( 'Go to Home', 'cliezoo' ) }
				</Link>
			}
		>
			{ __(
				'This page does not exist or you do not have access to it.',
				'cliezoo'
			) }
		</EmptyState>
	);
}

export default NotFound;
