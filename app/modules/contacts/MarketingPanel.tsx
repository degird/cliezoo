/**
 * Whether an address gets marketing email: unsubscribed by its own link,
 * or written down by somebody they asked. Putting it back needs to say how
 * they asked, because consent is something a studio may be asked to show.
 * Invoices, documents and receipts are not marketing and always go.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	api,
	formatDate,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import { Badge, Button, Dialog, TextField, toast } from '@cliezoo/ui';

interface Status {
	unsubscribed: boolean;
	since: string | null;
	how: string | null;
}

export function MarketingPanel( { email }: { email: string } ) {
	const client = useQueryClient();
	const key = [ 'email-marketing', email ];
	const status = useQuery( {
		queryKey: key,
		enabled: '' !== email,
		queryFn: async () =>
			( await api.get< Status >( '/email/marketing', { email } ) ).data,
	} );
	const [ asking, setAsking ] = useState< 'stop' | 'back' | null >( null );
	const [ reason, setReason ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	if ( '' === email || ! status.data ) {
		return null;
	}

	const act = async () => {
		setBusy( true );
		try {
			await api.post(
				'stop' === asking
					? '/email/marketing/unsubscribe'
					: '/email/marketing/resubscribe',
				'stop' === asking ? { email } : { email, reason }
			);
			setAsking( null );
			setReason( '' );
			client.invalidateQueries( { queryKey: key } );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const how = ( value: string | null ) =>
		'staff' === value
			? __( 'asked one of the team', 'cliezoo' )
			: __( 'used the link in an email', 'cliezoo' );

	return (
		<section className="rounded-lg border border-line bg-raised p-4">
			<h2 className="text-sm font-semibold text-fg">
				{ __( 'Marketing email', 'cliezoo' ) }
			</h2>
			{ status.data.unsubscribed ? (
				<p className="mt-2 text-sm text-fg">
					<Badge tone="warning">
						{ __( 'Unsubscribed', 'cliezoo' ) }
					</Badge>{ ' ' }
					{ sprintf(
						/* translators: 1: a date, 2: how they unsubscribed. */
						__( 'Since %1$s — they %2$s.', 'cliezoo' ),
						formatDate( String( status.data.since ?? '' ) ),
						how( status.data.how )
					) }
				</p>
			) : (
				<p className="mt-2 text-sm text-muted">
					{ __( 'Gets marketing email.', 'cliezoo' ) }
				</p>
			) }
			<p className="mt-1 text-xs text-muted">
				{ __(
					'Invoices, documents and receipts are not marketing and always go.',
					'cliezoo'
				) }
			</p>
			<Button
				size="sm"
				className="mt-3"
				onPress={ () =>
					setAsking( status.data?.unsubscribed ? 'back' : 'stop' )
				}
			>
				{ status.data.unsubscribed
					? __( 'They asked to be back on…', 'cliezoo' )
					: __( 'They asked to stop…', 'cliezoo' ) }
			</Button>
			<Dialog
				isOpen={ null !== asking }
				onOpenChange={ () => setAsking( null ) }
				title={
					'stop' === asking
						? __( 'Stop marketing email?', 'cliezoo' )
						: __( 'Put them back on the list?', 'cliezoo' )
				}
				description={
					'stop' === asking
						? __(
								'For when they asked one of you rather than using the link.',
								'cliezoo'
							)
						: __(
								'Only because they asked to be. Say how — it is kept with the record of when they left.',
								'cliezoo'
							)
				}
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ 'back' === asking && '' === reason.trim() }
						onPress={ act }
					>
						{ __( 'Confirm', 'cliezoo' ) }
					</Button>
				) }
			>
				{ 'back' === asking && (
					<TextField
						label={ __( 'How they asked', 'cliezoo' ) }
						value={ reason }
						onChange={ setReason }
						isRequired
					/>
				) }
			</Dialog>
		</section>
	);
}
