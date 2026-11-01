/**
 * Activity timeline for a record (who changed what, old → new) or for a
 * client / project (everything that happened inside it).
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowRight, History, ListPlus } from 'lucide-react';
import {
	api,
	formatDate,
	formatRelative,
	formatTime,
	getBoot,
	useList,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Avatar,
	Button,
	EmptyState,
	ErrorState,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { humanise } from '../list/cells';

interface Change {
	from: unknown;
	to: unknown;
}

export interface Activity {
	uid: string;
	action: string;
	summary: string;
	channel: string;
	actor: { uid: string; label: string } | null;
	object: { type: string; uid: string | null; label: string };
	changes: Record< string, Change > | null;
	created_at: string;
}

export interface ActivityFeedProps {
	/** Entity name of the record. */
	objectType?: string;
	/** Record uid. */
	uid?: string;
	/** Everything inside a client. */
	client?: string;
	/** Everything inside a project. */
	project?: string;
	/** Show which record each entry belongs to (for client/project feeds). */
	showObject?: boolean;
	/** Only one kind of record (inside a client's or project's feed). */
	kind?: string;
	/** Only what one person did (member uid). */
	member?: string;
}

function show( value: unknown ): string {
	if ( value === null || value === undefined || value === '' ) {
		return __( '(empty)', 'cliezoo' );
	}
	if ( typeof value === 'boolean' ) {
		return value ? __( 'Yes', 'cliezoo' ) : __( 'No', 'cliezoo' );
	}
	if (
		typeof value === 'string' &&
		/^\d{4}-\d{2}-\d{2}( |T)\d{2}:\d{2}/.test( value )
	) {
		return `${ formatDate( value ) } ${ formatTime( value ) }`;
	}
	if ( typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
		return formatDate( value );
	}
	return typeof value === 'object'
		? JSON.stringify( value )
		: String( value );
}

export function sentence( item: Activity ): string {
	const said = plainSentence( item );
	// Done by an agent acting as somebody: said so, after whose it was, so
	// "who changed this" has an honest answer.
	return 'ability' === item.channel
		? sprintf(
				/* translators: %s: what happened, e.g. "Ada created this". */
				__( '%s, via an AI agent', 'cliezoo' ),
				said
			)
		: said;
}

function plainSentence( item: Activity ): string {
	const who = item.actor?.label ?? __( 'Someone', 'cliezoo' );
	// Nobody signed in did it (a client on a public page, a job): the
	// summary already says who, as far as anybody knows.
	if (
		item.channel === 'system' ||
		item.channel === 'automation' ||
		item.channel === 'public'
	) {
		return item.summary;
	}
	switch ( item.action ) {
		case 'created':
			/* translators: %s: person. */
			return sprintf( __( '%s created this', 'cliezoo' ), who );
		case 'trashed':
			return sprintf(
				/* translators: %s: person. */
				__( '%s moved this to the trash', 'cliezoo' ),
				who
			);
		case 'restored':
			/* translators: %s: person. */
			return sprintf( __( '%s restored this', 'cliezoo' ), who );
		case 'archived':
			/* translators: %s: person. */
			return sprintf( __( '%s archived this', 'cliezoo' ), who );
		case 'updated':
		case 'status_changed':
			/* translators: %s: person. */
			return sprintf( __( '%s made changes', 'cliezoo' ), who );
		default:
			return item.summary ? `${ who }: ${ item.summary }` : who;
	}
}

/**
 * Which record a to-do made from an activity item belongs to: the record
 * the item is about, so it turns up on that record's to-do panel; failing
 * that (a deleted record, a system entry), the record whose feed this is.
 *
 * @param item  Activity item.
 * @param where The feed's own record.
 */
export function todoTarget(
	item: Pick< Activity, 'object' >,
	where: Pick<
		ActivityFeedProps,
		'objectType' | 'uid' | 'client' | 'project'
	>
): { object_type: string; object: string } | null {
	if ( item.object?.uid && item.object.type ) {
		return { object_type: item.object.type, object: item.object.uid };
	}
	if ( where.uid && where.objectType ) {
		return { object_type: where.objectType, object: where.uid };
	}
	if ( where.project ) {
		return { object_type: 'projects', object: where.project };
	}
	if ( where.client ) {
		return { object_type: 'clients', object: where.client };
	}
	return null;
}

/**
 * "Add to-do" on one activity item: a follow-up written where the thing
 * that prompted it is, rather than after a trip to the record's panel.
 *
 * @param root0
 * @param root0.item
 * @param root0.target
 * @param root0.target.object_type
 * @param root0.target.object
 */
function ActivityTodo( {
	item,
	target,
}: {
	item: Activity;
	target: { object_type: string; object: string };
} ) {
	const queries = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const [ title, setTitle ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const add = async () => {
		if ( ! title.trim() || busy ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/todos', { ...target, title: title.trim() } );
			queries.invalidateQueries( { queryKey: [ 'todos' ] } );
			toast.success( __( 'To-do added.', 'cliezoo' ) );
			setTitle( '' );
			setOpen( false );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	if ( ! open ) {
		return (
			<Button
				size="sm"
				variant="ghost"
				icon={ <ListPlus size={ 14 } aria-hidden="true" /> }
				aria-label={ sprintf(
					/* translators: %s: what happened, e.g. "Maya made changes". */
					__( 'Add to-do about: %s', 'cliezoo' ),
					sentence( item )
				) }
				onPress={ () => setOpen( true ) }
			>
				{ __( 'Add to-do', 'cliezoo' ) }
			</Button>
		);
	}
	return (
		/* The key listener is for keys pressed in this form's own fields,
		   so Escape puts it away without losing the feed's place. */
		/* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */
		<form
			className="mt-1.5 flex flex-wrap items-end gap-2"
			onSubmit={ ( event ) => {
				event.preventDefault();
				add();
			} }
			onKeyDown={ ( event ) => {
				// Escape puts the form away without losing the feed's place.
				if ( 'Escape' === event.key ) {
					event.stopPropagation();
					setOpen( false );
				}
			} }
		>
			<TextField
				aria-label={ __( 'What needs doing?', 'cliezoo' ) }
				placeholder={ __( 'What needs doing?', 'cliezoo' ) }
				// The person just asked for this box; typing should go in it.
				// eslint-disable-next-line jsx-a11y/no-autofocus
				autoFocus
				value={ title }
				onChange={ setTitle }
				maxLength={ 255 }
				className="min-w-52 flex-1"
			/>
			<Button
				type="submit"
				size="sm"
				isPending={ busy }
				isDisabled={ ! title.trim() }
			>
				{ __( 'Add', 'cliezoo' ) }
			</Button>
			<Button
				size="sm"
				variant="ghost"
				onPress={ () => setOpen( false ) }
			>
				{ __( 'Cancel', 'cliezoo' ) }
			</Button>
		</form>
	);
}

export default function ActivityFeed( {
	objectType,
	uid,
	client,
	project,
	showObject = false,
	kind,
	member,
}: ActivityFeedProps ) {
	const list = useList< Activity >(
		'/activity',
		{
			object_type: uid ? objectType : kind || undefined,
			object: uid,
			client,
			project,
			member: member || undefined,
		},
		{ limit: 30 }
	);
	const items = list.data?.pages.flatMap( ( page ) => page.data ) ?? [];
	// To-dos are the team's: a client reading a feed in the portal is not
	// offered one.
	const user = getBoot().user;
	const canTodo = !! user && ! user.client;
	if ( list.isPending ) {
		return (
			<div className="space-y-3" aria-busy="true">
				<Skeleton className="h-12" />
				<Skeleton className="h-12" />
			</div>
		);
	}
	if ( list.isError ) {
		return (
			<ErrorState error={ list.error } onRetry={ () => list.refetch() } />
		);
	}
	if ( ! items.length ) {
		return (
			<EmptyState
				icon={ <History size={ 22 } aria-hidden="true" /> }
				title={ __( 'No activity yet', 'cliezoo' ) }
			/>
		);
	}
	return (
		<div>
			<ol className="relative space-y-4 border-s border-line ps-6">
				{ items.map( ( item ) => (
					<li key={ item.uid } className="relative">
						<span className="absolute -start-[2.05rem] top-0">
							<Avatar
								name={ item.actor?.label ?? '•' }
								size="sm"
							/>
						</span>
						<p className="text-sm text-fg">
							{ sentence( item ) }
							{ showObject && item.object.label && (
								<>
									{ ' · ' }
									<span className="text-muted">
										{ item.object.label }
									</span>
								</>
							) }
						</p>
						{ item.changes &&
							Object.keys( item.changes ).length > 0 && (
								<ul className="mt-1.5 space-y-1 text-sm">
									{ Object.entries( item.changes ).map(
										( [ key, change ] ) => (
											<li
												key={ key }
												className="flex flex-wrap items-center gap-1.5 text-muted"
											>
												<span className="font-medium text-fg">
													{ humanise( key ) }
												</span>
												<span className="line-through decoration-subtle">
													{ show( change.from ) }
												</span>
												<ArrowRight
													size={ 12 }
													aria-label={ __(
														'changed to',
														'cliezoo'
													) }
													className="rtl:rotate-180"
												/>
												<span className="text-fg">
													{ show( change.to ) }
												</span>
											</li>
										)
									) }
								</ul>
							) }
						<p className="mt-0.5 text-xs text-subtle">
							<time
								dateTime={ item.created_at }
								title={ `${ formatDate( item.created_at ) } ${ formatTime( item.created_at ) }` }
							>
								{ formatRelative( item.created_at ) }
							</time>
						</p>
						{ canTodo &&
							( () => {
								const target = todoTarget( item, {
									objectType,
									uid,
									client,
									project,
								} );
								return target ? (
									<ActivityTodo
										item={ item }
										target={ target }
									/>
								) : null;
							} )() }
					</li>
				) ) }
			</ol>
			{ list.hasNextPage && (
				<Button
					className="mt-4"
					size="sm"
					onPress={ () => list.fetchNextPage() }
					isPending={ list.isFetchingNextPage }
				>
					{ __( 'Show older activity', 'cliezoo' ) }
				</Button>
			) }
		</div>
	);
}
