/**
 * A form for any record, built from the entity's own metadata.
 *
 * The caller says which fields belong in which section and in what order;
 * everything else — labels, types, options, what is required, what is
 * read-only, which errors belong to which field — comes from the server, so
 * a field added there appears here without being written twice.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import {
	api,
	formatDate,
	formatTime,
	getBoot,
	idempotencyKey,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import { Alert, Button, ErrorState, Skeleton, toast } from '@cliezoo/ui';
import {
	allFields,
	inScope,
	useEntityMeta,
	type FieldMeta,
} from '../list/meta';
import { placeOf } from '../ai/useAi';
import { FieldInput } from './FieldInput';
import {
	changedValues,
	clearDraft,
	conflictMessage,
	draftKey,
	isConflict,
	lastChoices,
	loadDraft,
	rememberChoices,
	saveDraft,
	worthRemembering,
} from './draft';

export interface FormSection {
	title?: string;
	description?: string;
	/** Field keys, in the order they should appear. */
	fields: string[];
	/** One column instead of two (long text, notes). */
	wide?: boolean;
}

export interface RecordFormProps {
	entity: string;
	/** The record being edited; omit to create a new one. */
	record?: Record< string, unknown > | null;
	sections: FormSection[];
	/** Values the caller sets itself (a parent record, say). */
	fixed?: Record< string, unknown >;
	/**
	 * Show the workspace's own fields for this kind of record, after the
	 * sections, when the sections do not place them. On unless told off.
	 */
	custom?: boolean;
	/**
	 * The board and task a form is for: a custom field kept for another
	 * board or task is left out.
	 */
	scopeFor?: { board?: string; task?: string };
	/** Long text fields written with formatting (markdown). */
	rich?: string[];
	/**
	 * Narrows a reference picker, by field key: `{ list: { filter: … } }`
	 * offers only the lists of this board rather than every list there is.
	 */
	refQueries?: Record< string, Record< string, unknown > >;
	/** Called with the saved record. */
	onSaved: ( record: Record< string, unknown > ) => void;
	onCancel?: () => void;
	submitLabel?: string;
	/** Rendered above the fields (a notice, a warning). */
	children?: ReactNode;
	/**
	 * Rendered above the fields with whatever has been typed so far — for a
	 * form that has something to say about its own draft, such as "a client
	 * with this name already exists".
	 */
	watch?: ( draft: Record< string, unknown > ) => ReactNode;
	/**
	 * Values read for the record from somewhere else (a receipt's amount
	 * and date), put into the draft when they arrive, for the person to
	 * check before saving.
	 */
	fill?: Record< string, unknown > | null;
}

/**
 * A record as the form edits it. Custom values arrive as
 * `{ custom: { budget: … } }` and are edited as "custom.budget", the key
 * the field has everywhere else.
 *
 * @param record The record, or nothing for a new one.
 */
function asDraft(
	record: Record< string, unknown > | null | undefined
): Record< string, unknown > {
	if ( ! record ) {
		return {};
	}
	const values = ( record.custom ?? {} ) as Record< string, unknown >;
	return {
		...record,
		...Object.fromEntries(
			Object.entries( values ).map( ( [ name, value ] ) => [
				`custom.${ name }`,
				value,
			] )
		),
	};
}

/**
 * Create or edit, in one component.
 *
 * @param root0
 * @param root0.entity
 * @param root0.record
 * @param root0.sections
 * @param root0.fixed
 * @param root0.fill
 * @param root0.onSaved
 * @param root0.onCancel
 * @param root0.submitLabel
 * @param root0.children
 * @param root0.watch
 * @param root0.refQueries
 * @param root0.custom
 * @param root0.scopeFor
 * @param root0.rich
 */
export function RecordForm( {
	entity,
	record,
	sections,
	fixed,
	fill,
	refQueries,
	onSaved,
	onCancel,
	submitLabel,
	children,
	watch,
	custom = true,
	scopeFor,
	rich = [],
}: RecordFormProps ) {
	const meta = useEntityMeta( entity );
	const client = useQueryClient();
	const [ draft, setDraft ] = useState< Record< string, unknown > >( {} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ failure, setFailure ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	// One key per form, so a lost response cannot become two records.
	const createKey = useRef( idempotencyKey() );

	const editing = record ? String( record.uid ) : '';
	// The version being edited, sent as If-Match so a save cannot quietly
	// overwrite what someone else saved meanwhile.
	const [ version, setVersion ] = useState( '' );
	const [ conflict, setConflict ] = useState< {
		message: string;
		latest: Record< string, unknown >;
	} | null >( null );
	// What was typed and not saved last time (the session ended, the tab
	// closed), offered back rather than restored behind anyone's back.
	const [ offer, setOffer ] = useState< Record< string, unknown > | null >(
		null
	);
	const baseline = useRef< Record< string, unknown > >( {} );
	const draftName = draftKey( entity, editing, getBoot().user?.uid ?? '' );

	useEffect( () => {
		const start = asDraft( record );
		baseline.current = start;
		// A new record starts where the last one did, for the fields that
		// say where it belongs: the second task of an afternoon is almost
		// always on the board the first one was.
		setDraft(
			record
				? start
				: {
						...lastChoices( entity, getBoot().user?.uid ?? '' ),
						...start,
					}
		);
		setVersion( 'string' === typeof record?.etag ? record.etag : '' );
		setConflict( null );
		setErrors( {} );
		setFailure( '' );
		const kept = loadDraft( draftName );
		setOffer(
			kept && Object.keys( changedValues( kept.values, start ) ).length
				? kept.values
				: null
		);
		// Keyed on which record, not on the object: the same record arrives
		// again every time the window regains focus, and throwing away what
		// somebody has typed because a timestamp changed is unforgivable.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ editing ] );

	// What was read arrives into what is being typed, not over the form.
	useEffect( () => {
		if ( fill && Object.keys( fill ).length ) {
			setDraft( ( now ) => ( { ...now, ...fill } ) );
		}
	}, [ fill ] );

	if ( meta.isError ) {
		return (
			<ErrorState error={ meta.error } onRetry={ () => meta.refetch() } />
		);
	}
	if ( ! meta.data ) {
		return (
			<div className="space-y-3" aria-busy="true">
				<Skeleton className="h-10" />
				<Skeleton className="h-10" />
				<Skeleton className="h-10" />
			</div>
		);
	}

	const fields = new Map< string, FieldMeta >(
		allFields( meta.data ).map( ( field ) => [ field.key, field ] )
	);
	const placed = new Set( sections.flatMap( ( section ) => section.fields ) );
	const extra = custom
		? meta.data.custom
				.filter( ( field ) => inScope( field, scopeFor ) )
				.map( ( field ) => field.key )
				.filter( ( key ) => ! placed.has( key ) )
		: [];
	const shown = extra.length
		? [
				...sections,
				{
					title: __( 'More fields', 'cliezoo' ),
					description: __(
						'The fields your workspace keeps for these.',
						'cliezoo'
					),
					fields: extra,
				},
			]
		: sections;

	/**
	 * AI writing help for a long text field. A task's description can be
	 * written from the task itself, saved or not.
	 *
	 * @param key Field key.
	 */
	const aiFor = ( key: string ) => {
		const field = fields.get( key );
		if (
			! field ||
			( 'longtext' !== field.type && 'html' !== field.type )
		) {
			return undefined;
		}
		const describing = 'tasks' === entity && 'description' === key;
		return {
			place: placeOf( entity ),
			record: editing ? { type: entity, uid: editing } : undefined,
			extras: describing ? [ 'task_description' as const ] : [],
			about: describing
				? () =>
						`${ __( 'Task:', 'cliezoo' ) } ${ String( draft.title ?? '' ) }`
				: undefined,
		};
	};

	const set = ( name: string, value: unknown ) => {
		setDraft( ( current ) => {
			const next = { ...current, [ name ]: value };
			saveDraft( draftName, changedValues( next, baseline.current ) );
			return next;
		} );
		setErrors( ( current ) => {
			const next = { ...current };
			delete next[ name ];
			return next;
		} );
	};

	/**
	 * After a refused save: who changed the record, and the record as it
	 * is now, so the person can choose between theirs and it.
	 */
	const explain = async () => {
		if ( ! record ) {
			return;
		}
		const uid = String( record.uid );
		const [ latest, last ] = await Promise.all( [
			api
				.get< Record< string, unknown > >( `/${ entity }/${ uid }` )
				.then( ( answer ) => answer.data )
				.catch( () => null ),
			api
				.get<
					Array< {
						actor?: { label?: string } | null;
						created_at?: string;
					} >
				>( '/activity', { object_type: entity, object: uid, limit: 1 } )
				.then( ( answer ) => answer.data[ 0 ] ?? null )
				.catch( () => null ),
		] );
		const when = String( latest?.updated_at ?? last?.created_at ?? '' );
		setConflict( {
			message: conflictMessage(
				last?.actor?.label ?? null,
				when ? `${ formatDate( when ) } ${ formatTime( when ) }` : ''
			),
			latest: latest ?? {},
		} );
	};

	const submit = async ( event?: React.FormEvent, against?: string ) => {
		event?.preventDefault();
		if ( saving ) {
			return;
		}
		setSaving( true );
		setErrors( {} );
		setFailure( '' );
		// Only what the form was asked to edit is sent, so a form that shows
		// half a record cannot blank the other half.
		const editable = shown.flatMap( ( section ) => section.fields );
		const body: Record< string, unknown > = { ...( fixed ?? {} ) };
		// What the caller fixed — the client a conversation is being
		// started from — is where the record belongs. A field nobody
		// filled in must not send null over it.
		const locked = new Set( Object.keys( fixed ?? {} ) );
		const was = ( record?.custom ?? {} ) as Record< string, unknown >;
		const customs: Record< string, unknown > = {};
		for ( const key of editable ) {
			const field = fields.get( key );
			if ( ! field || field.readonly ) {
				continue;
			}
			if ( key.startsWith( 'custom.' ) ) {
				const name = key.slice( 7 );
				// A new record sends only what was filled in, so the field's
				// own default stands for the rest.
				if ( record ? draft[ key ] !== was[ name ] : key in draft ) {
					customs[ name ] = draft[ key ] ?? null;
				}
				continue;
			}
			if (
				locked.has( key ) &&
				( undefined === draft[ key ] || null === draft[ key ] )
			) {
				continue;
			}
			if ( ! record || draft[ key ] !== record[ key ] ) {
				body[ key ] = draft[ key ] ?? null;
			}
		}
		if ( Object.keys( customs ).length ) {
			body.custom = customs;
		}
		const match = against ?? version;
		try {
			const saved = record
				? (
						await api.patch< Record< string, unknown > >(
							`/${ entity }/${ String( record.uid ) }`,
							body,
							match ? { ifMatch: `"${ match }"` } : {}
						)
					).data
				: (
						await api.post< Record< string, unknown > >(
							`/${ entity }`,
							body,
							{ idempotencyKey: createKey.current }
						)
					).data;
			clearDraft( draftName );
			if ( ! record ) {
				rememberChoices(
					entity,
					getBoot().user?.uid ?? '',
					Object.fromEntries(
						Object.entries( body ).filter( ( [ key, value ] ) => {
							const field = fields.get( key );
							return (
								!! field &&
								null !== value &&
								undefined !== value &&
								worthRemembering( key, field.type )
							);
						} )
					)
				);
			}
			setConflict( null );
			await client.invalidateQueries( { queryKey: [ entity ] } );
			onSaved( saved );
		} catch ( error ) {
			const problem = error as ApiError;
			if ( isConflict( problem ) ) {
				// Nothing typed is lost: it stays in the form (and in the
				// browser) while they choose.
				await explain();
				return;
			}
			setErrors( problem.fields ?? {} );
			// A message as well as the field marks: a form scrolled past its
			// first error would otherwise look like nothing happened.
			setFailure( problem.message );
			toast.error( problem.message );
		} finally {
			setSaving( false );
		}
	};

	return (
		<form onSubmit={ submit } className="space-y-6">
			{ children }
			{ watch?.( draft ) }
			{ '' !== failure && <Alert tone="error">{ failure }</Alert> }
			{ offer && (
				<Alert tone="info">
					<p>
						{ __(
							'You have changes here that were not saved last time.',
							'cliezoo'
						) }
					</p>
					<div className="mt-2 flex flex-wrap gap-2">
						<Button
							size="sm"
							variant="primary"
							onPress={ () => {
								setDraft( ( current ) => ( {
									...current,
									...offer,
								} ) );
								setOffer( null );
							} }
						>
							{ __( 'Restore them', 'cliezoo' ) }
						</Button>
						<Button
							size="sm"
							variant="ghost"
							onPress={ () => {
								clearDraft( draftName );
								setOffer( null );
							} }
						>
							{ __( 'Discard them', 'cliezoo' ) }
						</Button>
					</div>
				</Alert>
			) }
			{ conflict && (
				<Alert tone="warning">
					<p>{ conflict.message }</p>
					<p className="mt-1">
						{ __(
							'Your changes are still here. Load their version to see what they saved (yours are dropped), or keep yours and save over it.',
							'cliezoo'
						) }
					</p>
					<div className="mt-2 flex flex-wrap gap-2">
						<Button
							size="sm"
							onPress={ () => {
								const fresh = asDraft( conflict.latest );
								baseline.current = fresh;
								setDraft( fresh );
								setVersion(
									String( conflict.latest.etag ?? '' )
								);
								clearDraft( draftName );
								setConflict( null );
							} }
						>
							{ __( 'Load their version', 'cliezoo' ) }
						</Button>
						<Button
							size="sm"
							variant="primary"
							isPending={ saving }
							onPress={ () => {
								const latest = String(
									conflict.latest.etag ?? ''
								);
								setVersion( latest );
								submit( undefined, latest );
							} }
						>
							{ __( 'Keep mine and save', 'cliezoo' ) }
						</Button>
					</div>
				</Alert>
			) }
			{ shown.map( ( section, index ) => (
				<section key={ section.title ?? index } className="space-y-3">
					{ section.title && (
						<div>
							<h3 className="text-sm font-semibold text-fg">
								{ section.title }
							</h3>
							{ section.description && (
								<p className="mt-0.5 text-sm text-muted">
									{ section.description }
								</p>
							) }
						</div>
					) }
					<div
						className={
							section.wide
								? 'grid gap-4'
								: 'grid gap-4 sm:grid-cols-2'
						}
					>
						{ section.fields.map( ( key ) => {
							const field = fields.get( key );
							if ( ! field ) {
								return null;
							}
							return (
								<FieldInput
									key={ key }
									ai={ aiFor( key ) }
									field={ field }
									value={ draft[ key ] }
									draft={ draft }
									error={ errors[ key ] }
									refQuery={ refQueries?.[ key ] }
									rich={ rich.includes( key ) }
									onChange={ ( value ) => set( key, value ) }
								/>
							);
						} ) }
					</div>
				</section>
			) ) }
			{ /*
			    Kept against the bottom of whatever scrolls this form. A
			    record form is long — a phone shows a third of one, and
			    its Save sat the better part of a thousand pixels below
			    the fold — so the one button everybody is looking for is
			    the one they had to hunt for. Drawn on the raised surface
			    with a line above it, so the fields passing beneath stay
			    legible.
			 */ }
			<div className="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-line bg-raised py-3">
				{ onCancel && (
					<Button
						variant="ghost"
						onPress={ () => {
							// Cancelling is choosing not to keep it.
							clearDraft( draftName );
							onCancel();
						} }
					>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
				) }
				<Button type="submit" variant="primary" isPending={ saving }>
					{ submitLabel ??
						( record
							? __( 'Save changes', 'cliezoo' )
							: __( 'Create', 'cliezoo' ) ) }
				</Button>
			</div>
		</form>
	);
}
