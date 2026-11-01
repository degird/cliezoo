/**
 * Deleting a client asks what should happen to what belongs to it: kept,
 * or put in the trash with it. Either way it can all come back.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, useQuery, type ApiError } from '@cliezoo/data';
import {
	Button,
	Checkbox,
	Dialog,
	ErrorState,
	Skeleton,
	toast,
} from '@cliezoo/ui';

interface Related {
	kind: string;
	label: string;
	count: number;
}

export function DeleteClientDialog( {
	uid,
	name,
	onClose,
	onDeleted,
}: {
	uid: string;
	name: string;
	onClose: () => void;
	onDeleted: () => void;
} ) {
	const [ with_, setWith ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const related = useQuery( {
		queryKey: [ 'clients', uid, 'related' ],
		queryFn: async () =>
			( await api.get< Related[] >( `/clients/${ uid }/related` ) ).data,
	} );

	const go = async () => {
		setBusy( true );
		try {
			const result = (
				await api.post< { kept: Record< string, number > } >(
					`/clients/${ uid }/trash`,
					{ with: with_ }
				)
			).data;
			toast.success(
				__(
					'In the trash. It can be brought back from the Library.',
					'cliezoo'
				)
			);
			const kept = Object.values( result.kept ?? {} ).reduce(
				( sum, n ) => sum + n,
				0
			);
			if ( kept > 0 ) {
				toast.info(
					sprintf(
						/* translators: %d: how many records stayed. */
						_n(
							'%d thing stayed where it is: you may not delete it, or something still holds it.',
							'%d things stayed where they are: you may not delete them, or something still holds them.',
							kept,
							'cliezoo'
						),
						kept
					)
				);
			}
			onDeleted();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen
			role="alertdialog"
			onOpenChange={ ( open ) => ! open && onClose() }
			title={ sprintf(
				/* translators: %s: a client's name. */
				__( 'Delete %s?', 'cliezoo' ),
				name
			) }
			description={ __(
				'The client goes to the trash. Choose what goes with it; anything not chosen stays where it is, and comes back with the client if you bring it back.',
				'cliezoo'
			) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button variant="danger" isPending={ busy } onPress={ go }>
						{ __( 'Delete', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			{ related.isPending && <Skeleton className="h-20" /> }
			{ /* Nobody should arm a red Delete over an empty body: what
			     goes with the client is the whole question, and a failed
			     ask is not an answer of "nothing". */ }
			{ related.isError && (
				<ErrorState
					error={ related.error }
					title={ __(
						'What belongs to this client could not be listed.',
						'cliezoo'
					) }
					onRetry={ () => related.refetch() }
				/>
			) }
			{ !! related.data && ! related.data.length && (
				<p className="text-sm text-muted">
					{ __( 'Nothing else belongs to this client.', 'cliezoo' ) }
				</p>
			) }
			{ !! related.data?.length && (
				<fieldset className="space-y-2">
					<legend className="mb-1 text-sm font-medium text-fg">
						{ __( 'Put these in the trash too', 'cliezoo' ) }
					</legend>
					{ related.data.map( ( one ) => (
						<Checkbox
							key={ one.kind }
							isSelected={ with_.includes( one.kind ) }
							onChange={ ( on: boolean ) =>
								setWith( ( now ) =>
									on
										? [ ...now, one.kind ]
										: now.filter(
												( kind ) => kind !== one.kind
											)
								)
							}
						>
							{ sprintf(
								/* translators: 1: a kind of record, 2: how many. */
								_n(
									'%1$s (%2$d)',
									'%1$s (%2$d)',
									one.count,
									'cliezoo'
								),
								one.label,
								one.count
							) }
						</Checkbox>
					) ) }
				</fieldset>
			) }
		</Dialog>
	);
}
