/**
 * /cz/unsubscribe?e=…&s=…: the way out of marketing email, from the line at
 * the foot of one.
 *
 * It asks for a press rather than acting on opening, because mail scanners
 * open every link in an email to check it, and an unsubscribe that happened
 * on opening would take people off lists they never left.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';

export default function UnsubscribePage() {
	const query = new URLSearchParams( window.location.search );
	const email = query.get( 'e' ) ?? '';
	const signature = query.get( 's' ) ?? '';
	const [ state, setState ] = useState< 'ask' | 'busy' | 'done' | 'error' >(
		'ask'
	);
	const [ message, setMessage ] = useState( '' );

	const go = async () => {
		setState( 'busy' );
		try {
			const response = await fetch(
				`${ getBoot().rest.root }/public/unsubscribe`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { e: email, s: signature } ),
				}
			);
			const payload = await response.json().catch( () => null );
			if ( ! response.ok ) {
				throw new Error(
					payload?.message ??
						__( 'This link is not available.', 'cliezoo' )
				);
			}
			setState( 'done' );
		} catch ( failure ) {
			setMessage( ( failure as Error ).message );
			setState( 'error' );
		}
	};

	return (
		<div className="max-w-md rounded-xl border border-line bg-raised p-8 text-center shadow-md">
			<h1 className="text-lg font-semibold text-fg">
				{ 'done' === state
					? __( 'You are unsubscribed', 'cliezoo' )
					: __( 'Unsubscribe from marketing email', 'cliezoo' ) }
			</h1>
			<p className="mt-2 text-sm text-muted">
				{ 'done' === state
					? __(
							'No more marketing email will go to this address. Invoices, documents to sign and receipts still come: those are not marketing.',
							'cliezoo'
						)
					: sprintf(
							/* translators: %s: an email address. */
							__(
								'Stop marketing email to %s? Invoices, documents to sign and receipts still come.',
								'cliezoo'
							),
							email
						) }
			</p>
			{ 'error' === state && (
				<p role="alert" className="mt-3 text-sm text-danger">
					{ message }
				</p>
			) }
			{ 'done' !== state && (
				<button
					type="button"
					disabled={ 'busy' === state || '' === email }
					onClick={ go }
					className="mt-5 rounded bg-accent px-4 py-2 text-sm font-medium text-accent-contrast"
				>
					{ __( 'Unsubscribe', 'cliezoo' ) }
				</button>
			) }
		</div>
	);
}
