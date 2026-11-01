/**
 * Everything that has happened to a client, most recent first: narrowed to
 * one kind of thing or one person, with a to-do jotted down from where you
 * read it.
 */
import { Suspense, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, getBoot, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Button,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { ActivityFeed, Slot } from '../../packages/app';

/** What the feed can be narrowed to, named as the rest of the app names it. */
export const KINDS = (): Array< { id: string; label: string } > => [
	{ id: '', label: __( 'Everything', 'cliezoo' ) },
	{ id: 'clients', label: __( 'The client itself', 'cliezoo' ) },
	{ id: 'contacts', label: __( 'Contacts', 'cliezoo' ) },
	{ id: 'projects', label: __( 'Projects', 'cliezoo' ) },
	{ id: 'tasks', label: __( 'Tasks', 'cliezoo' ) },
	{ id: 'files', label: __( 'Files', 'cliezoo' ) },
	{ id: 'conversations', label: __( 'Conversations', 'cliezoo' ) },
	{ id: 'requests', label: __( 'Requests', 'cliezoo' ) },
	{ id: 'docs', label: __( 'Docs', 'cliezoo' ) },
];

export function ActivityTab( {
	record,
}: {
	record: Record< string, unknown >;
} ) {
	const uid = String( record.uid );
	const me = getBoot().user?.uid ?? '';
	const queries = useQueryClient();
	const [ kind, setKind ] = useState( '' );
	const [ mine, setMine ] = useState( false );
	const [ todo, setTodo ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const addTodo = async () => {
		if ( ! todo.trim() ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/todos', {
				object_type: 'clients',
				object: uid,
				title: todo.trim(),
			} );
			setTodo( '' );
			await queries.invalidateQueries( { queryKey: [ 'todos' ] } );
			toast.success( __( 'To-do added.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="space-y-4">
			<form
				className="flex flex-wrap items-end gap-2"
				onSubmit={ ( event ) => {
					event.preventDefault();
					addTodo();
				} }
			>
				<TextField
					label={ __( 'Add a to-do', 'cliezoo' ) }
					placeholder={ __(
						'Call them about the renewal…',
						'cliezoo'
					) }
					value={ todo }
					onChange={ setTodo }
					className="min-w-60 flex-1"
					maxLength={ 255 }
				/>
				<Button
					type="submit"
					isPending={ busy }
					isDisabled={ ! todo.trim() }
				>
					{ __( 'Add', 'cliezoo' ) }
				</Button>
			</form>

			<div className="flex flex-wrap items-end gap-2">
				<Select
					label={ __( 'Show', 'cliezoo' ) }
					className="w-48"
					items={ KINDS().map( ( one ) => ( {
						id: one.id || 'all',
						label: one.label,
					} ) ) }
					selectedKey={ kind || 'all' }
					onSelectionChange={ ( key: unknown ) =>
						setKind( 'all' === key ? '' : String( key ) )
					}
				/>
				{ !! me && (
					<Select
						label={ __( 'By', 'cliezoo' ) }
						className="w-36"
						items={ [
							{ id: 'anyone', label: __( 'Anyone', 'cliezoo' ) },
							{ id: 'me', label: __( 'Me', 'cliezoo' ) },
						] }
						selectedKey={ mine ? 'me' : 'anyone' }
						onSelectionChange={ ( key: unknown ) =>
							setMine( 'me' === key )
						}
					/>
				) }
				{ /* An add-on can put more on this timeline. */ }
				<div className="ms-auto self-center">
					<Slot
						name="client.activity"
						props={ { record } }
						badge={ false }
					/>
				</div>
			</div>

			<Suspense fallback={ <Skeleton className="h-64" /> }>
				<ActivityFeed
					key={ `${ kind }:${ mine }` }
					client={ uid }
					kind={ kind || undefined }
					member={ mine ? me : undefined }
					showObject
				/>
			</Suspense>
		</div>
	);
}

export default ActivityTab;
