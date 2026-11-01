/**
 * Notes and to-dos beside any record.
 *
 * Both hang off the generic record services, so the same two panels work on
 * a client, a project, a deal or an invoice without either module knowing
 * about the other.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Pin, Plus, Trash2 } from 'lucide-react';
import {
	Button,
	Checkbox,
	ErrorState,
	IconButton,
	Skeleton,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import {
	api,
	formatDate,
	formatRelative,
	useQuery,
	useQueryClient,
} from '@cliezoo/data';
import { RailEmpty, RailPanel } from './RecordScreen';
import { optimistic, patchRow } from '../optimistic';
import { AiForText } from '../ai/AiMenu';
import { placeOf } from '../ai/useAi';

interface Note {
	uid: string;
	body: string;
	pinned?: boolean;
	created_at: string;
	author?: { name: string } | null;
}

interface Todo {
	uid: string;
	title: string;
	done?: boolean;
	due_date?: string | null;
}

/** How many notes or to-dos one panel holds. */
const PANEL_LIMIT = 100;

/**
 * Reads the records attached to one record.
 * @param kind
 * @param type
 * @param uid
 */
function useAttached< T >( kind: string, type: string, uid: string ) {
	return useQuery( {
		queryKey: [ kind, type, uid ],
		queryFn: async () =>
			(
				await api.get< T[] >( `/${ kind }`, {
					object_type: type,
					object: uid,
					limit: PANEL_LIMIT,
				} )
			).data,
	} );
}

export function NotesPanel( { type, uid }: { type: string; uid: string } ) {
	const client = useQueryClient();
	const notes = useAttached< Note >( 'notes', type, uid );
	const [ draft, setDraft ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const add = async () => {
		if ( '' === draft.trim() || busy ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/notes', {
				object_type: type,
				object: uid,
				body: draft.trim(),
			} );
			setDraft( '' );
			setOpen( false );
			await client.invalidateQueries( {
				queryKey: [ 'notes', type, uid ],
			} );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const remove = async ( note: Note ) => {
		try {
			await api.delete( `/notes/${ note.uid }` );
			await client.invalidateQueries( {
				queryKey: [ 'notes', type, uid ],
			} );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	const rows = notes.data ?? [];

	return (
		<RailPanel
			title={ __( 'Notes', 'cliezoo' ) }
			count={ rows.length }
			action={
				<IconButton
					size="sm"
					label={
						open
							? __( 'Stop writing this note', 'cliezoo' )
							: __( 'Add a note', 'cliezoo' )
					}
					icon={ <Plus size={ 15 } aria-hidden="true" /> }
					onPress={ () => setOpen( ! open ) }
				/>
			}
		>
			{ open && (
				<div className="relative mb-3 space-y-2">
					<div className="absolute end-1 top-1 z-10">
						<AiForText
							place={ placeOf( type ) }
							value={ draft }
							onChange={ setDraft }
						/>
					</div>
					<TextField
						aria-label={ __( 'Note', 'cliezoo' ) }
						multiline
						rows={ 3 }
						value={ draft }
						onChange={ setDraft }
						placeholder={ __(
							'Something worth remembering…',
							'cliezoo'
						) }
					/>
					<div className="flex justify-end gap-2">
						<Button
							size="sm"
							variant="ghost"
							onPress={ () => {
								setOpen( false );
								setDraft( '' );
							} }
						>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							size="sm"
							variant="primary"
							isPending={ busy }
							isDisabled={ '' === draft.trim() }
							onPress={ add }
						>
							{ __( 'Add note', 'cliezoo' ) }
						</Button>
					</div>
				</div>
			) }
			{ notes.isPending && <Skeleton className="h-16" /> }
			{ notes.isError && (
				<ErrorState
					error={ notes.error }
					onRetry={ () => notes.refetch() }
				/>
			) }
			{ ! notes.isPending && ! notes.isError && ! rows.length && (
				<RailEmpty
					onAdd={ () => setOpen( true ) }
					addLabel={ __( 'Write one', 'cliezoo' ) }
				>
					{ __(
						'Nothing noted yet — notes are internal and never shown to the client.',
						'cliezoo'
					) }
				</RailEmpty>
			) }
			{ rows.length >= PANEL_LIMIT && (
				<p className="mb-2 text-xs text-subtle">
					{ sprintf(
						/* translators: %d: how many are shown. */
						__( 'The most recent %d.', 'cliezoo' ),
						PANEL_LIMIT
					) }
				</p>
			) }
			<ul className="space-y-3">
				{ rows.map( ( note ) => (
					<li key={ note.uid } className="group">
						<p className="whitespace-pre-line text-fg">
							{ note.body }
						</p>
						<p className="mt-0.5 flex items-center gap-1.5 text-xs text-subtle">
							{ note.pinned && (
								<Pin
									size={ 12 }
									aria-hidden="true"
									className="text-accent-fg"
								/>
							) }
							{ note.author?.name
								? sprintf(
										/* translators: 1: person's name, 2: relative time. */
										__( '%1$s · %2$s', 'cliezoo' ),
										note.author.name,
										formatRelative( note.created_at )
									)
								: formatRelative( note.created_at ) }
							<IconButton
								className="ms-auto opacity-0 focus-visible:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100"
								size="sm"
								label={ __( 'Delete this note', 'cliezoo' ) }
								icon={
									<Trash2 size={ 13 } aria-hidden="true" />
								}
								onPress={ () => remove( note ) }
							/>
						</p>
					</li>
				) ) }
			</ul>
		</RailPanel>
	);
}

export function TodosPanel( { type, uid }: { type: string; uid: string } ) {
	const client = useQueryClient();
	const todos = useAttached< Todo >( 'todos', type, uid );
	const [ draft, setDraft ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'todos', type, uid ] } );

	const add = async () => {
		if ( '' === draft.trim() || busy ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/todos', {
				object_type: type,
				object: uid,
				title: draft.trim(),
			} );
			setDraft( '' );
			await refresh();
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const toggle = async ( todo: Todo ) => {
		try {
			// Ticked at once; a refusal unticks it again.
			await optimistic(
				client,
				[
					{
						key: [ 'todos', type, uid ],
						update: patchRow( todo.uid, { done: ! todo.done } ),
					},
				],
				() => api.patch( `/todos/${ todo.uid }`, { done: ! todo.done } )
			);
			await refresh();
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	const rows = todos.data ?? [];
	const openCount = rows.filter( ( row ) => ! row.done ).length;

	return (
		<RailPanel title={ __( 'To-dos', 'cliezoo' ) } count={ openCount }>
			<form
				className="mb-3 flex gap-2"
				onSubmit={ ( event ) => {
					event.preventDefault();
					add();
				} }
			>
				<TextField
					className="flex-1"
					aria-label={ __( 'What needs doing?', 'cliezoo' ) }
					placeholder={ __( 'What needs doing?', 'cliezoo' ) }
					value={ draft }
					onChange={ setDraft }
				/>
				<Button
					type="submit"
					size="sm"
					isPending={ busy }
					isDisabled={ '' === draft.trim() }
				>
					{ __( 'Add', 'cliezoo' ) }
				</Button>
			</form>
			{ todos.isPending && <Skeleton className="h-12" /> }
			{ todos.isError && (
				<ErrorState
					error={ todos.error }
					onRetry={ () => todos.refetch() }
				/>
			) }
			{ ! todos.isPending && ! todos.isError && ! rows.length && (
				<RailEmpty>
					{ __(
						'Nothing to do here yet.',
						'cliezoo'
					) }
				</RailEmpty>
			) }
			{ rows.length >= PANEL_LIMIT && (
				<p className="mb-2 text-xs text-subtle">
					{ sprintf(
						/* translators: %d: how many are shown. */
						__( 'The most recent %d.', 'cliezoo' ),
						PANEL_LIMIT
					) }
				</p>
			) }
			<ul className="space-y-1.5">
				{ rows.map( ( todo ) => (
					<li key={ todo.uid }>
						{ /* A real checkbox, labelled with the to-do itself:
						     "Mark as done" five times over tells a screen
						     reader nothing about which is which. */ }
						<Checkbox
							isSelected={ !! todo.done }
							onChange={ () => toggle( todo ) }
						>
							<span
								className={ cx(
									todo.done && 'text-muted line-through'
								) }
							>
								{ todo.title }
								{ todo.due_date && (
									<span className="ms-1.5 text-xs text-subtle">
										{ formatDate( todo.due_date ) }
									</span>
								) }
							</span>
						</Checkbox>
					</li>
				) ) }
			</ul>
			{ rows.length > openCount && (
				<p className="mt-2 text-xs text-subtle">
					{ sprintf(
						/* translators: %d: number of finished to-dos. */
						_n(
							'%d done',
							'%d done',
							rows.length - openCount,
							'cliezoo'
						),
						rows.length - openCount
					) }
				</p>
			) }
		</RailPanel>
	);
}
