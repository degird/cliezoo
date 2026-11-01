/**
 * Settings → Editable lists: the values behind statuses, stages, priorities,
 * types and sources — rename, recolour, reorder, add and remove (records
 * using a removed value move to another one).
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import {
	Button,
	Dialog,
	EmptyState,
	ErrorState,
	IconButton,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';

interface TermValue {
	uid: string;
	value: string;
	label: string;
	color: string | null;
	is_default: boolean;
	position: number;
}

type Lists = Record< string, { label: string; values: TermValue[] } >;

export default function EditableLists() {
	const client = useQueryClient();
	const [ list, setList ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ label, setLabel ] = useState( '' );
	const [ color, setColor ] = useState( '#0458fb' );
	const [ removing, setRemoving ] = useState< TermValue | null >( null );
	const [ replacement, setReplacement ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const lists = useQuery( {
		queryKey: [ 'lists' ],
		queryFn: async () => ( await api.get< Lists >( '/lists' ) ).data,
	} );
	const keys = Object.keys( lists.data ?? {} );
	const current = list || keys[ 0 ] || '';
	const values = lists.data?.[ current ]?.values ?? [];
	const refresh = () => client.invalidateQueries( { queryKey: [ 'lists' ] } );

	const run = async ( fn: () => Promise< unknown >, message: string ) => {
		setBusy( true );
		try {
			await fn();
			refresh();
			toast.success( message );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const add = () =>
		run(
			async () => {
				await api.post( '/taxonomy_terms', {
					list: current,
					label,
					value: label
						.toLowerCase()
						.replace( /[^a-z0-9]+/g, '_' )
						.slice( 0, 60 ),
					color,
					position: values.length,
				} );
				setAdding( false );
				setLabel( '' );
			},
			__( 'Value added.', 'cliezoo' )
		);

	const move = ( term: TermValue, delta: number ) => {
		const order = values.map( ( v ) => v.uid );
		const index = order.indexOf( term.uid );
		const target = index + delta;
		if ( target < 0 || target >= order.length ) {
			return;
		}
		[ order[ index ], order[ target ] ] = [
			order[ target ],
			order[ index ],
		];
		run(
			() =>
				api.post( `/lists/${ current }/order`, {
					list: current,
					uids: order,
				} ),
			__( 'Order saved.', 'cliezoo' )
		);
	};

	if ( lists.isPending ) {
		return <Skeleton className="h-48" />;
	}
	if ( lists.isError ) {
		return (
			<ErrorState
				error={ lists.error }
				onRetry={ () => lists.refetch() }
			/>
		);
	}
	if ( ! keys.length ) {
		return (
			<EmptyState title={ __( 'No editable lists yet', 'cliezoo' ) }>
				{ __(
					'Statuses, stages and priorities appear here once their modules are active.',
					'cliezoo'
				) }
			</EmptyState>
		);
	}

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-end gap-3">
				<Select
					label={ __( 'List', 'cliezoo' ) }
					className="w-60"
					items={ keys.map( ( key ) => ( {
						id: key,
						label: lists.data?.[ key ].label ?? key,
					} ) ) }
					selectedKey={ current }
					onSelectionChange={ ( key ) => setList( String( key ) ) }
				/>
				<Button
					className="ms-auto"
					variant="primary"
					icon={ <Plus size={ 14 } aria-hidden="true" /> }
					onPress={ () => setAdding( true ) }
				>
					{ __( 'Add value', 'cliezoo' ) }
				</Button>
			</div>
			<ul className="divide-y divide-line rounded-lg border border-line bg-raised">
				{ values.map( ( term, index ) => (
					<li
						key={ term.uid }
						className="flex flex-wrap items-center gap-3 px-4 py-2.5"
					>
						<span
							aria-hidden="true"
							className="size-3 shrink-0 rounded-full border border-line"
							style={ {
								backgroundColor: term.color ?? 'transparent',
							} }
						/>
						<TextField
							key={ term.uid + term.label }
							aria-label={ __( 'Label', 'cliezoo' ) }
							className="min-w-40 flex-1"
							defaultValue={ term.label }
							onBlur={ ( event ) => {
								const next = (
									event.target as HTMLInputElement
								 ).value;
								if ( next && next !== term.label ) {
									run(
										() =>
											api.patch(
												`/taxonomy_terms/${ term.uid }`,
												{ label: next }
											),
										__( 'Value renamed.', 'cliezoo' )
									);
								}
							} }
						/>
						<TextField
							key={ term.uid + String( term.color ) }
							aria-label={ __( 'Colour', 'cliezoo' ) }
							className="w-24"
							type="color"
							defaultValue={ term.color ?? '#0458fb' }
							// On blur, not on change: a colour picker reports
							// every shade the pointer passes over, and each one
							// would have been a save.
							onBlur={ ( event ) => {
								const next = (
									event.target as HTMLInputElement
								 ).value;
								if ( next && next !== term.color ) {
									run(
										() =>
											api.patch(
												`/taxonomy_terms/${ term.uid }`,
												{ color: next }
											),
										__( 'Colour saved.', 'cliezoo' )
									);
								}
							} }
						/>
						<IconButton
							size="sm"
							label={ __( 'Move up', 'cliezoo' ) }
							tooltip={ false }
							icon={ <ArrowUp size={ 14 } aria-hidden="true" /> }
							isDisabled={ index === 0 }
							onPress={ () => move( term, -1 ) }
						/>
						<IconButton
							size="sm"
							label={ __( 'Move down', 'cliezoo' ) }
							tooltip={ false }
							icon={
								<ArrowDown size={ 14 } aria-hidden="true" />
							}
							isDisabled={ index === values.length - 1 }
							onPress={ () => move( term, 1 ) }
						/>
						<IconButton
							size="sm"
							label={ __( 'Remove value', 'cliezoo' ) }
							icon={ <Trash2 size={ 14 } aria-hidden="true" /> }
							isDisabled={ values.length < 2 }
							onPress={ () => {
								setReplacement(
									values.find( ( v ) => v.uid !== term.uid )
										?.value ?? ''
								);
								setRemoving( term );
							} }
						/>
					</li>
				) ) }
			</ul>

			<Dialog
				isOpen={ adding }
				onOpenChange={ setAdding }
				title={ __( 'Add a value', 'cliezoo' ) }
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ ! label.trim() }
						onPress={ add }
					>
						{ __( 'Add', 'cliezoo' ) }
					</Button>
				) }
			>
				<div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
					<TextField
						label={ __( 'Label', 'cliezoo' ) }
						value={ label }
						onChange={ setLabel }
						isRequired
					/>
					<TextField
						label={ __( 'Colour', 'cliezoo' ) }
						type="color"
						value={ color }
						onChange={ setColor }
					/>
				</div>
			</Dialog>

			<Dialog
				isOpen={ !! removing }
				onOpenChange={ () => setRemoving( null ) }
				role="alertdialog"
				title={ __( 'Remove this value?', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: value label. */
					__(
						'Records that use “%s” move to the value you choose.',
						'cliezoo'
					),
					String( removing?.label ?? '' )
				) }
				footer={ () => (
					<Button
						variant="danger"
						isPending={ busy }
						onPress={ () => {
							const term = removing;
							setRemoving( null );
							if ( term ) {
								run(
									() =>
										api.post(
											`/lists/terms/${ term.uid }/remove`,
											{
												uid: term.uid,
												replace: replacement || null,
											}
										),
									__( 'Value removed.', 'cliezoo' )
								);
							}
						} }
					>
						{ __( 'Remove value', 'cliezoo' ) }
					</Button>
				) }
			>
				<Select
					label={ __( 'Move records to', 'cliezoo' ) }
					items={ [
						{
							id: '',
							label: __( 'Leave the field empty', 'cliezoo' ),
						},
						...values
							.filter( ( v ) => v.uid !== removing?.uid )
							.map( ( v ) => ( {
								id: v.value,
								label: v.label,
							} ) ),
					] }
					selectedKey={ replacement }
					onSelectionChange={ ( key ) =>
						setReplacement( String( key ) )
					}
				/>
			</Dialog>
		</div>
	);
}
