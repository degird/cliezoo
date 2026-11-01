/**
 * What a record form keeps in the browser until it is saved, so a session
 * that ends while someone types (and the sign-in that follows) loses
 * nothing; and what a form says when someone else saved first.
 *
 * Only this browser holds a draft, and only until the record is saved or
 * the draft is thrown away. Storage can be missing (private windows,
 * blocked site data); every read and write is guarded, and a form works the
 * same without it, just without the safety net.
 */
import { __, sprintf } from '@wordpress/i18n';

/** How long a draft is offered back before it is forgotten. */
export const DRAFT_DAYS = 7;

const PREFIX = 'cliezoo:draft:';

interface Stored {
	at: number;
	values: Record< string, unknown >;
}

/**
 * Where one form's draft is kept: per record type, per record (or "new"),
 * and per person, so a shared computer does not hand one person's typing
 * to the next.
 *
 * @param entity Record type.
 * @param uid    Record uid, or '' while it is being made.
 * @param user   Who is typing.
 */
export function draftKey( entity: string, uid: string, user: string ): string {
	return `${ PREFIX }${ user || 'anon' }:${ entity }:${ uid || 'new' }`;
}

/**
 * The browser's storage, or null where it cannot be used.
 */
function storage(): Storage | null {
	try {
		return window.localStorage;
	} catch {
		return null;
	}
}

/**
 * Keeps what has been typed.
 *
 * @param key    Draft key.
 * @param values Changed values only.
 * @param now    The time (for tests).
 */
export function saveDraft(
	key: string,
	values: Record< string, unknown >,
	now = Date.now()
): void {
	const store = storage();
	if ( ! store ) {
		return;
	}
	try {
		if ( ! Object.keys( values ).length ) {
			store.removeItem( key );
			return;
		}
		const kept: Stored = { at: now, values };
		store.setItem( key, JSON.stringify( kept ) );
	} catch {
		// Full or blocked: the form still works, without the net.
	}
}

/**
 * A kept draft, or null when there is none or it is too old.
 *
 * @param key Draft key.
 * @param now The time (for tests).
 */
export function loadDraft(
	key: string,
	now = Date.now()
): { at: number; values: Record< string, unknown > } | null {
	const store = storage();
	if ( ! store ) {
		return null;
	}
	try {
		const raw = store.getItem( key );
		if ( ! raw ) {
			return null;
		}
		const kept = JSON.parse( raw ) as Stored;
		if (
			! kept ||
			'object' !== typeof kept.values ||
			now - Number( kept.at ) > DRAFT_DAYS * 86_400_000
		) {
			store.removeItem( key );
			return null;
		}
		return { at: Number( kept.at ), values: kept.values };
	} catch {
		return null;
	}
}

/**
 * Forgets a draft (after saving, or when thrown away).
 *
 * @param key Draft key.
 */
export function clearDraft( key: string ): void {
	try {
		storage()?.removeItem( key );
	} catch {
		// Nothing to do.
	}
}

/**
 * Forgets every draft and remembered choice this browser holds.
 *
 * A draft is what somebody had typed and not yet sent — a client's
 * details, a note about a person — and it is kept for seven days so a
 * session that ends mid-sentence loses nothing. Signing out is saying the
 * work here is done, and on a shared computer the next person should not
 * find the last one's typing in devtools. The key names carry the person's
 * uid, so it is every draft that goes, not only the one signing out: the
 * point is to leave nothing behind.
 */
export function forgetDrafts(): void {
	const store = storage();
	if ( ! store ) {
		return;
	}
	try {
		const keys: string[] = [];
		for ( let at = 0; at < store.length; at++ ) {
			const key = store.key( at );
			if (
				key &&
				( key.startsWith( PREFIX ) || key.startsWith( LAST ) )
			) {
				keys.push( key );
			}
		}
		keys.forEach( ( key ) => store.removeItem( key ) );
	} catch {
		// Blocked storage held nothing to forget.
	}
}

/**
 * The values in a draft that differ from the record as it is now.
 *
 * @param draft  What was typed.
 * @param record The record (or {} for a new one).
 */
export function changedValues(
	draft: Record< string, unknown >,
	record: Record< string, unknown >
): Record< string, unknown > {
	const out: Record< string, unknown > = {};
	for ( const [ key, value ] of Object.entries( draft ) ) {
		if ( JSON.stringify( value ) !== JSON.stringify( record[ key ] ) ) {
			out[ key ] = value;
		}
	}
	return out;
}

/**
 * What a form says when someone saved the record after it was opened.
 *
 * @param who  Who saved it last, when known.
 * @param when When (already formatted), when known.
 */
export function conflictMessage( who?: string | null, when?: string ): string {
	if ( who && when ) {
		return sprintf(
			/* translators: 1: a person's name, 2: a time. */
			__(
				'This was changed by %1$s at %2$s, after you opened it.',
				'cliezoo'
			),
			who,
			when
		);
	}
	if ( who ) {
		return sprintf(
			/* translators: %s: a person's name. */
			__( 'This was changed by %s after you opened it.', 'cliezoo' ),
			who
		);
	}
	return __(
		'This was changed by someone else after you opened it.',
		'cliezoo'
	);
}

/** Where the last choices are kept, per person and record type. */
const LAST = 'cliezoo:last:';

/**
 * The fields worth remembering between one record and the next: the ones
 * that say where a record belongs or what kind it is. Never a name, a
 * date or anything somebody types once — a form that helpfully fills in
 * last week's title is worse than an empty one.
 *
 * @param key  Field key.
 * @param type Field type.
 */
export function worthRemembering( key: string, type: string ): boolean {
	if ( key.startsWith( 'custom.' ) ) {
		return false;
	}
	return (
		[ 'ref', 'user', 'list', 'enum' ].includes( type ) &&
		! [ 'status', 'stage' ].includes( key )
	);
}

/**
 * What this person chose last time they made one of these.
 *
 * @param entity Record type.
 * @param user   Who is making it.
 */
export function lastChoices(
	entity: string,
	user: string
): Record< string, unknown > {
	const store = storage();
	if ( ! store ) {
		return {};
	}
	try {
		const raw = store.getItem( LAST + ( user || 'anon' ) + ':' + entity );
		const kept = raw ? JSON.parse( raw ) : null;
		return kept && 'object' === typeof kept ? kept : {};
	} catch {
		return {};
	}
}

/**
 * Remembers what was chosen, for the next one.
 *
 * @param entity Record type.
 * @param user   Who made it.
 * @param values What was sent, already narrowed to the fields worth keeping.
 */
export function rememberChoices(
	entity: string,
	user: string,
	values: Record< string, unknown >
): void {
	const store = storage();
	if ( ! store || ! Object.keys( values ).length ) {
		return;
	}
	try {
		store.setItem(
			LAST + ( user || 'anon' ) + ':' + entity,
			JSON.stringify( values )
		);
	} catch {
		// Remembering is a convenience; not being able to is not a failure.
	}
}

/**
 * Whether a failed save was refused because the record changed meanwhile.
 *
 * @param error The failure.
 */
export function isConflict( error: unknown ): boolean {
	const problem = error as { code?: string; status?: number } | null;
	return (
		!! problem &&
		( 'cliezoo_stale' === problem.code || 412 === problem.status )
	);
}
