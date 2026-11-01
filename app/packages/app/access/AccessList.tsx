/**
 * Everything shared with one person or one client in particular, and a way
 * to take any of it back — on the day somebody leaves, or a client moves on.
 *
 * Used by Settings → Access and on a client's or a contact's own page.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ShieldOff } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import {
	Button,
	Checkbox,
	Dialog,
	ErrorState,
	EmptyState,
	Skeleton,
	toast,
} from '@cliezoo/ui';

interface Item {
	type: string;
	uid: string;
	label: string;
	role: string;
	number: string | null;
}

/**
 * The list for one principal.
 *
 * @param root0           Props.
 * @param root0.kind      A person ("user") or a client company ("client").
 * @param root0.principal Their uid.
 */
export function AccessList( {
	kind,
	principal,
}: {
	kind: 'user' | 'client';
	principal: string;
} ) {
	const client = useQueryClient();
	const [ chosen, setChosen ] = useState< string[] >( [] );
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const inventory = useQuery( {
		queryKey: [ 'access', 'inventory', kind, principal ],
		enabled: !! principal,
		queryFn: async () =>
			(
				await api.get< Item[] >( '/access/inventory', {
					principal_type: kind,
					principal,
				} )
			).data,
	} );

	const items = inventory.data ?? [];
	const key = ( item: Item ) => `${ item.type }:${ item.uid }`;
	const allChosen = items.length > 0 && chosen.length === items.length;

	const revoke = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< { revoked: number } >(
				'/access/inventory/revoke',
				{
					principal_type: kind,
					principal,
					items: items
						.filter( ( item ) => chosen.includes( key( item ) ) )
						.map( ( item ) => ( {
							type: item.type,
							uid: item.uid,
						} ) ),
				}
			);
			setConfirming( false );
			setChosen( [] );
			await client.invalidateQueries( { queryKey: [ 'access' ] } );
			toast.success(
				sprintf(
					/* translators: %d: how many records access was removed from. */
					_n(
						'Access removed from %d record.',
						'Access removed from %d records.',
						data.revoked,
						'cliezoo'
					),
					data.revoked
				)
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="space-y-4">
			{ !! principal && inventory.isPending && (
				<Skeleton className="h-32" />
			) }

			{ inventory.isError && (
				<ErrorState
					error={ inventory.error }
					onRetry={ () => inventory.refetch() }
				/>
			) }
			{ !! principal &&
				! inventory.isPending &&
				! inventory.isError &&
				! items.length && (
					<EmptyState
						icon={ <ShieldOff size={ 20 } aria-hidden="true" /> }
						title={ __( 'Nothing shared directly', 'cliezoo' ) }
					>
						{ __(
							'They may still see things through their role or their projects; this list is only what was shared with them in particular.',
							'cliezoo'
						) }
					</EmptyState>
				) }

			{ !! items.length && (
				<>
					<div className="flex flex-wrap items-center gap-3">
						<Checkbox
							isSelected={ allChosen }
							onChange={ ( on ) =>
								setChosen( on ? items.map( key ) : [] )
							}
						>
							{ sprintf(
								/* translators: %d: how many records. */
								__( 'Select all %d', 'cliezoo' ),
								items.length
							) }
						</Checkbox>
						<Button
							className="ms-auto"
							variant="danger"
							size="sm"
							isDisabled={ ! chosen.length }
							onPress={ () => setConfirming( true ) }
						>
							{ __( 'Remove access', 'cliezoo' ) }
						</Button>
					</div>
					<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
						{ items.map( ( item ) => (
							<li
								key={ key( item ) }
								className="flex flex-wrap items-center gap-3 px-4 py-2.5"
							>
								<Checkbox
									isSelected={ chosen.includes(
										key( item )
									) }
									onChange={ ( on ) =>
										setChosen( ( current ) =>
											on
												? [ ...current, key( item ) ]
												: current.filter(
														( id ) =>
															id !== key( item )
													)
										)
									}
								>
									<span className="min-w-0 truncate text-sm text-fg">
										{ item.number
											? `${ item.number } · ${ item.label }`
											: item.label }
									</span>
								</Checkbox>
								<span className="ms-auto text-xs text-muted">
									{ item.type }
								</span>
								<span className="text-xs text-muted">
									{ item.role }
								</span>
							</li>
						) ) }
					</ul>
				</>
			) }

			<Dialog
				isOpen={ confirming }
				onOpenChange={ setConfirming }
				role="alertdialog"
				title={ __( 'Remove this access?', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %d: how many records. */
					__(
						'They lose access to %d records they were shared on. Anything they can see through their role or their projects is unaffected.',
						'cliezoo'
					),
					chosen.length
				) }
				footer={ () => (
					<Button
						variant="danger"
						isPending={ busy }
						onPress={ revoke }
					>
						{ __( 'Remove access', 'cliezoo' ) }
					</Button>
				) }
			/>
		</div>
	);
}
