/**
 * The same person, written down twice.
 *
 * Which happens constantly: they fill in the form, then ring up, then email,
 * and three people in the studio write three leads. Merging is folding one
 * into the other — the one being kept wins every field it has an answer for,
 * the other fills the gaps, and both sets of notes and the whole history go
 * with it. Nothing is overwritten and nothing is lost, which is the only way
 * a merge is safe enough to offer.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { api, useList, type ApiError } from '@cliezoo/data';
import {
	Alert,
	Button,
	Dialog,
	SearchField,
	Skeleton,
	toast,
} from '@cliezoo/ui';

type Row = { uid: string; name: string; business_name?: string | null };

/**
 * The dialog.
 *
 * @param root0          Props.
 * @param root0.keep     The lead being kept.
 * @param root0.isOpen   Whether it is showing.
 * @param root0.onClose  Called when it should close.
 * @param root0.onMerged Called after the merge.
 */
export function MergeLead( {
	keep,
	isOpen,
	onClose,
	onMerged,
}: {
	keep: Record< string, unknown >;
	isOpen: boolean;
	onClose: () => void;
	onMerged: () => void;
} ) {
	const [ looking, setLooking ] = useState( '' );
	const [ chosen, setChosen ] = useState< Row | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( isOpen ) {
			setLooking( '' );
			setChosen( null );
		}
	}, [ isOpen ] );

	const others = useList< Row >(
		'/leads',
		{
			sort: '-created_at',
			fields: 'uid,name,business_name',
			...( looking ? { q: looking } : {} ),
		},
		{ limit: 20, enabled: isOpen }
	);
	const rows = ( others.data?.pages ?? [] )
		.flatMap( ( page ) => page.data )
		.filter( ( one ) => one.uid !== String( keep.uid ) );

	const merge = async () => {
		if ( ! chosen ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/leads/merge', {
				uid: chosen.uid,
				into: String( keep.uid ),
			} );
			toast.success( __( 'Folded in.', 'cliezoo' ) );
			onMerged();
			onClose();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ ( open: boolean ) => ! open && onClose() }
			title={ __( 'Fold another lead into this one', 'cliezoo' ) }
			description={ sprintf(
				/* translators: %s: the lead being kept. */
				__(
					'%s is kept. The one you choose fills in anything it is missing and is then put in the trash.',
					'cliezoo'
				),
				String( keep.name ?? '' )
			) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isDisabled={ busy || ! chosen }
						onPress={ merge }
					>
						{ chosen
							? sprintf(
									/* translators: %s: the lead being folded in. */
									__( 'Fold in %s', 'cliezoo' ),
									chosen.name
								)
							: __( 'Fold it in', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			<div className="flex flex-col gap-3">
				<SearchField
					label={ __( 'Which lead', 'cliezoo' ) }
					value={ looking }
					onChange={ setLooking }
				/>
				{ others.isPending && <Skeleton className="h-24" /> }
				{ ! others.isPending && ! rows.length && (
					<p className="text-sm text-muted">
						{ __( 'No other lead matches that.', 'cliezoo' ) }
					</p>
				) }
				{ !! rows.length && (
					<ul className="max-h-56 overflow-y-auto rounded border border-line">
						{ rows.map( ( one ) => (
							<li
								key={ one.uid }
								className="border-b border-line last:border-b-0"
							>
								<button
									type="button"
									onClick={ () => setChosen( one ) }
									aria-pressed={ chosen?.uid === one.uid }
									className={ `flex w-full items-baseline gap-2 px-2 py-1.5 text-start text-sm hover:bg-raised ${
										chosen?.uid === one.uid
											? 'bg-raised font-medium'
											: ''
									}` }
								>
									<span className="flex-1">{ one.name }</span>
									{ !! one.business_name && (
										<span className="text-xs text-muted">
											{ one.business_name }
										</span>
									) }
								</button>
							</li>
						) ) }
					</ul>
				) }
				<Alert tone="info">
					{ __(
						'Nothing is overwritten: this lead wins every field it has an answer for, and both sets of notes are kept.',
						'cliezoo'
					) }
				</Alert>
			</div>
		</Dialog>
	);
}
