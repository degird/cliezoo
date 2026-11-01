/**
 * Settings → Numbering: how each numbered record type counts — prefix,
 * number length, date, next number and a yearly restart — with the next
 * number shown as it will look. One card per type, saved on its own, so
 * changing clients never touches projects.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Alert,
	Button,
	ErrorState,
	Select,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import {
	DATE_PARTS,
	buildPattern,
	parsePattern,
	previewNumber,
	validPattern,
} from '../numbering';

interface Series {
	series: string;
	label: string;
	prefix: string;
	pattern: string;
	next_value: number;
	yearly_reset: boolean;
	preview: string;
}

function dateLabel( part: string ): string {
	const labels: Record< string, string > = {
		'': __( 'No date', 'cliezoo' ),
		'{YYYY}': __( 'Year (2026)', 'cliezoo' ),
		'{YY}': __( 'Short year (26)', 'cliezoo' ),
		'{YYYY}{MM}': __( 'Year and month (202608)', 'cliezoo' ),
		'{YY}{MM}': __( 'Short year and month (2608)', 'cliezoo' ),
	};
	return labels[ part ] ?? part;
}

function SeriesCard( { row }: { row: Series } ) {
	const client = useQueryClient();
	const [ draft, setDraft ] = useState( row );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setDraft( row ), [ row ] );

	const parts = parsePattern( draft.pattern );
	const dirty =
		draft.prefix !== row.prefix ||
		draft.pattern !== row.pattern ||
		draft.next_value !== row.next_value ||
		draft.yearly_reset !== row.yearly_reset;
	const valid =
		validPattern( draft.pattern ) &&
		! /[{}]/.test( draft.prefix ) &&
		draft.next_value >= 1;
	const set = ( values: Partial< Series > ) =>
		setDraft( ( current ) => ( { ...current, ...values } ) );

	const save = async () => {
		setBusy( true );
		setErrors( {} );
		try {
			await api.patch( `/numbering/${ row.series }`, {
				prefix: draft.prefix,
				pattern: draft.pattern,
				next_value: draft.next_value,
				yearly_reset: draft.yearly_reset,
			} );
			await client.invalidateQueries( { queryKey: [ 'numbering' ] } );
			toast.success( __( 'Numbering saved.', 'cliezoo' ) );
		} catch ( error ) {
			setErrors( ( error as ApiError ).fields ?? {} );
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section
			aria-labelledby={ `numbering-${ row.series }` }
			className="rounded-lg border border-line bg-raised p-5"
		>
			<div className="flex flex-wrap items-baseline justify-between gap-2">
				<h2
					id={ `numbering-${ row.series }` }
					className="text-base font-semibold"
				>
					{ row.label }
				</h2>
				<p className="text-sm text-muted" aria-live="polite">
					{ sprintf(
						/* translators: %s: the next record number, e.g. "CLI-00012". */
						__( 'Next: %s', 'cliezoo' ),
						valid
							? previewNumber(
									draft.prefix,
									draft.pattern,
									draft.next_value
								)
							: '—'
					) }
				</p>
			</div>
			<div className="mt-4 grid gap-4 sm:grid-cols-2">
				<TextField
					label={ __( 'Prefix', 'cliezoo' ) }
					value={ draft.prefix }
					onChange={ ( prefix ) => set( { prefix } ) }
					errorMessage={ errors.prefix }
				/>
				{ parts && (
					<>
						<Select
							label={ __( 'Date in the number', 'cliezoo' ) }
							items={ DATE_PARTS.map( ( part ) => ( {
								id: part || 'none',
								label: dateLabel( part ),
							} ) ) }
							selectedKey={ parts.date || 'none' }
							onSelectionChange={ ( key ) =>
								set( {
									pattern: buildPattern( {
										...parts,
										date:
											'none' === key ? '' : String( key ),
									} ),
								} )
							}
						/>
						<Select
							label={ __( 'Number length', 'cliezoo' ) }
							items={ [ 1, 2, 3, 4, 5, 6, 7, 8 ].map(
								( digits ) => ( {
									id: String( digits ),
									label: sprintf(
										/* translators: %d: how many digits, e.g. 5. */
										__( '%d digits', 'cliezoo' ),
										digits
									),
								} )
							) }
							selectedKey={ String( parts.digits ) }
							onSelectionChange={ ( key ) =>
								set( {
									pattern: buildPattern( {
										...parts,
										digits: Number( key ),
									} ),
								} )
							}
						/>
					</>
				) }
				<TextField
					label={ __( 'Next number', 'cliezoo' ) }
					type="number"
					value={ String( draft.next_value ) }
					onChange={ ( x ) =>
						set( { next_value: Math.max( 0, Number( x ) || 0 ) } )
					}
					description={ __(
						'It never goes back below a number already used.',
						'cliezoo'
					) }
					errorMessage={ errors.next_value }
				/>
				<TextField
					label={ __( 'Pattern', 'cliezoo' ) }
					description={ __(
						'Advanced. {PREFIX}, {YYYY}, {YY}, {MM} and {DD}, and one {#…} for the number, one # per digit.',
						'cliezoo'
					) }
					value={ draft.pattern }
					onChange={ ( pattern ) => set( { pattern } ) }
					errorMessage={
						errors.pattern ??
						( validPattern( draft.pattern )
							? undefined
							: __(
									'This pattern cannot make numbers.',
									'cliezoo'
								) )
					}
				/>
				<div className="flex items-center sm:col-span-2">
					<Switch
						isSelected={ draft.yearly_reset }
						onChange={ ( on ) => set( { yearly_reset: on } ) }
					>
						{ __( 'Start again from 1 each year', 'cliezoo' ) }
					</Switch>
				</div>
			</div>
			<div className="mt-4 flex justify-end gap-2">
				<Button
					variant="ghost"
					isDisabled={ ! dirty }
					onPress={ () => setDraft( row ) }
				>
					{ __( 'Discard', 'cliezoo' ) }
				</Button>
				<Button
					variant="primary"
					isPending={ busy }
					isDisabled={ ! dirty || ! valid }
					onPress={ save }
				>
					{ __( 'Save', 'cliezoo' ) }
				</Button>
			</div>
		</section>
	);
}

export default function Numbering() {
	const list = useQuery( {
		queryKey: [ 'numbering' ],
		queryFn: async () => ( await api.get< Series[] >( '/numbering' ) ).data,
	} );
	if ( list.isPending ) {
		return <Skeleton className="h-48" />;
	}
	if ( list.isError ) {
		return (
			<ErrorState error={ list.error } onRetry={ () => list.refetch() } />
		);
	}
	return (
		<div className="space-y-6">
			<Alert tone="info">
				{ __(
					'Changes apply to new records. Numbers already given out stay as they are.',
					'cliezoo'
				) }
			</Alert>
			{ list.data.map( ( row ) => (
				<SeriesCard key={ row.series } row={ row } />
			) ) }
		</div>
	);
}
