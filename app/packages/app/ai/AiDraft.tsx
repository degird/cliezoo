/**
 * An AI draft, before anything is put in: what it wrote, what changed from
 * what was there, and the choice to use it, change it or drop it.
 *
 * When the call fails, the draft says which way forward fits (Blueprint
 * §13.8): set up a provider, fix the key, load the models again, wait a
 * few seconds (it tries again by itself), or change what was asked. An
 * answer that ran out of room can be continued. Closing it while it is
 * still writing cancels the call, and a cancel is not an error.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copyText } from '../copy';
import { Link } from '@tanstack/react-router';
import { Copy, RefreshCw } from 'lucide-react';
import { api } from '@cliezoo/data';
import {
	Alert,
	Button,
	Dialog,
	Skeleton,
	Switch,
	TextField,
	cx,
} from '@cliezoo/ui';
import { useUnsavedGuard } from '../form/unsaved';
import { wordDiff } from './diff';
import { tell, type AiRun } from './useAi';
import { getAiStreamer } from '../registry';
import { Slot } from '../slot';
import ProviderPick from './ProviderPick';
import {
	aiFailure,
	fixedInSettings,
	retriesItself,
	type AiFailure,
} from './errors';

export interface AiJob {
	task: string;
	label: string;
	place: string;
	options: Record< string, string >;
	text: string;
	selected: boolean;
	record?: { type: string; uid: string };
	/**
	 * What a screen says the task is about (a report and its dates), for a
	 * task whose material the server gathers.
	 */
	about?: Record< string, string | boolean >;
	/** Whether the draft stands in for the text it was made from. */
	transforms: boolean;
	/** Whether it may replace what is there even so. */
	replaceable?: boolean;
}

/** How many times a busy provider is waited for before giving up. */
const WAITS = 2;

/** What a request left behind by a newer one throws instead of its abort. */
const SUPERSEDED = Symbol( 'superseded' );

function Changes( { before, after }: { before: string; after: string } ) {
	const parts = useMemo( () => wordDiff( before, after ), [ before, after ] );
	if ( ! parts ) {
		return (
			<p className="text-sm text-muted">
				{ __(
					'This is too long to show word by word; the draft is below.',
					'cliezoo'
				) }
			</p>
		);
	}
	return (
		<p className="whitespace-pre-wrap rounded-md border border-line bg-sunken p-3 text-sm leading-relaxed">
			{ parts.map( ( part, i ) => {
				if ( part.kind === 'same' ) {
					return <span key={ i }>{ part.text }</span>;
				}
				return part.kind === 'added' ? (
					<ins
						key={ i }
						className="rounded-sm bg-success/15 text-fg no-underline"
					>
						{ part.text }
					</ins>
				) : (
					<del
						key={ i }
						className="rounded-sm bg-danger/15 text-muted"
					>
						{ part.text }
					</del>
				);
			} ) }
		</p>
	);
}

/**
 * What a failure says, whether or not the provider gave a reason.
 *
 * @param failure What went wrong.
 */
export function failureText( failure: AiFailure ): string {
	if ( failure.message ) {
		return failure.message;
	}
	return 'NO_PROVIDER' === failure.code
		? __(
				'AI is not set up in this workspace yet. Everything still works by hand.',
				'cliezoo'
			)
		: __( 'The AI request could not be completed.', 'cliezoo' );
}

/**
 * The one line that says out loud what the draft is doing.
 *
 * The visible states are a skeleton, a coloured panel and a filled-in text
 * box, and none of those announce themselves. The line that does has to be
 * on the page the whole time the dialog is: a live region that arrives
 * together with its words is usually read as empty and then never again,
 * which is how "Writing…" was the only thing anybody ever heard.
 *
 * @param state         Where the draft has got to.
 * @param state.busy    Whether it is still writing.
 * @param state.failure What went wrong, if anything.
 * @param state.draft   What came back.
 */
export function draftStatus( state: {
	busy: boolean;
	failure: AiFailure | null;
	draft: string;
} ): string {
	if ( state.busy ) {
		return __( 'Writing a draft.', 'cliezoo' );
	}
	if ( state.failure ) {
		return failureText( state.failure );
	}
	return state.draft ? __( 'The draft is ready.', 'cliezoo' ) : '';
}

/**
 * The way forward for one failure.
 *
 * @param root0         Props.
 * @param root0.failure What went wrong.
 * @param root0.wait    Seconds until it tries again by itself, or 0.
 * @param root0.admin   Whether this person can change Settings → AI.
 * @param root0.onLeave Called when a link takes them to Settings.
 */
function Failure( {
	failure,
	wait,
	admin,
	onLeave,
}: {
	failure: AiFailure;
	wait: number;
	admin: boolean;
	onLeave: () => void;
} ) {
	if ( wait > 0 ) {
		return (
			<Alert tone="info">
				<span role="status">
					{ sprintf(
						/* translators: %d: seconds until the AI request is sent again. */
						_n(
							'The AI service is busy. Retrying in %d second…',
							'The AI service is busy. Retrying in %d seconds…',
							wait,
							'cliezoo'
						),
						wait
					) }
				</span>
			</Alert>
		);
	}
	const links: Partial< Record< AiFailure[ 'code' ], string > > = {
		NO_PROVIDER: __( 'Set up AI', 'cliezoo' ),
		AUTH: __( 'Edit the key', 'cliezoo' ),
		MODEL_NOT_FOUND: __( '↻ Load models', 'cliezoo' ),
		PAYMENT: __( 'Check the AI settings', 'cliezoo' ),
		BUDGET: __( 'Change the budget', 'cliezoo' ),
	};
	return (
		<Alert tone={ failure.code === 'NO_PROVIDER' ? 'info' : 'error' }>
			<p>{ failureText( failure ) }</p>
			{ fixedInSettings( failure ) &&
				( admin ? (
					<Link
						to="/settings/$section"
						params={ { section: 'ai' } as never }
						hash="ai-providers"
						onClick={ onLeave }
						className="mt-2 inline-block font-medium text-accent-fg hover:underline"
					>
						{ links[ failure.code ] }
					</Link>
				) : (
					<p className="mt-1 text-muted">
						{ __(
							'An administrator can fix this in Settings → AI.',
							'cliezoo'
						) }
					</p>
				) ) }
		</Alert>
	);
}

export default function AiDraft( {
	job,
	admin = false,
	onClose,
	onUse,
}: {
	job: AiJob;
	/** Whether this person can change Settings → AI. */
	admin?: boolean;
	onClose: () => void;
	/**
	 * Puts the draft in. Without it the draft is only read and copied (an
	 * AI action about a record, which has nowhere to put it).
	 */
	onUse?: ( text: string, how: 'replace' | 'insert' ) => void;
} ) {
	const [ run, setRun ] = useState< AiRun | null >( null );
	const [ draft, setDraft ] = useState( '' );
	const [ failure, setFailure ] = useState< AiFailure | null >( null );
	const [ busy, setBusy ] = useState( true );
	// What a streamed answer has said so far, while it is written.
	const [ live, setLive ] = useState( '' );
	const [ changes, setChanges ] = useState( job.transforms );
	// An answer that stopped short cannot stand in for the whole text.
	const [ short, setShort ] = useState( false );
	const [ wait, setWait ] = useState( 0 );
	const settled = useRef( false );
	// Every run behind this draft: a continued answer is more than one.
	const runs = useRef< string[] >( [] );
	const waited = useRef( 0 );
	const call = useRef< AbortController | null >( null );
	const alive = useRef( true );
	// Set when the person cancels, so what the cancel throws is not an error.
	const cancelled = useRef( false );
	// Which request is the newest: one asked for again, or the screen
	// mounted twice, leaves an older one behind whose abort is nobody's
	// failure and whose answer is nobody's draft.
	const newest = useRef( 0 );

	const payload = () => ( {
		task: job.task,
		place: job.place,
		...( job.text.trim() ? { text: job.text } : {} ),
		...( job.record
			? {
					object_type: job.record.type,
					object: job.record.uid,
				}
			: {} ),
		...( job.about ? { about: job.about } : {} ),
		...job.options,
	} );

	const send = async ( extra: Record< string, string > = {} ) => {
		call.current?.abort();
		const controller = new AbortController();
		call.current = controller;
		const mine = ++newest.current;
		setLive( '' );
		try {
			// Written as it arrives, where an add-on can stream it.
			const streamer = getAiStreamer();
			if ( streamer ) {
				const streamed = await streamer(
					{ ...payload(), ...extra },
					{
						signal: controller.signal,
						onDelta: ( text ) =>
							alive.current && setLive( ( was ) => was + text ),
					}
				);
				if ( streamed ) {
					return streamed as AiRun;
				}
			}
			return (
				await api.post< AiRun >(
					'/ai/run',
					{ ...payload(), ...extra },
					{ signal: controller.signal }
				)
			).data;
		} catch ( error ) {
			throw controller.signal.aborted || mine !== newest.current
				? SUPERSEDED
				: error;
		} finally {
			if ( call.current === controller ) {
				call.current = null;
			}
		}
	};

	const fail = ( error: unknown ) => {
		const read = aiFailure(
			error,
			cancelled.current || SUPERSEDED === error
		);
		// Cancelled on purpose: nothing to say, and nobody left to say it to.
		if ( read.code === 'ABORTED' || ! alive.current ) {
			return;
		}
		if ( retriesItself( read ) && waited.current < WAITS ) {
			waited.current += 1;
			setWait( read.retryAfter );
		}
		setFailure( read );
	};

	const go = async () => {
		setBusy( true );
		setFailure( null );
		setWait( 0 );
		try {
			const made = await send();
			if ( ! alive.current ) {
				return;
			}
			runs.current = [ ...runs.current, made.run ];
			setRun( made );
			setDraft( made.text.trim() );
			setShort( !! made.truncated );
		} catch ( error ) {
			if ( SUPERSEDED === error ) {
				// The newer request is the one still working.
				return;
			}
			fail( error );
		}
		if ( alive.current ) {
			setBusy( false );
		}
	};

	// "Continue": ask for what comes after the part that came back.
	const carryOn = async () => {
		setBusy( true );
		setFailure( null );
		try {
			const more = await send( { so_far: draft } );
			if ( ! alive.current ) {
				return;
			}
			runs.current = [ ...runs.current, more.run ];
			const next = more.text.trim();
			const joined = /\s$/.test( draft )
				? draft + next
				: `${ draft } ${ next }`;
			setDraft( joined );
			setRun( { ...more, text: joined } );
			setShort( !! more.truncated );
		} catch ( error ) {
			if ( SUPERSEDED === error ) {
				// The newer request is the one still working.
				return;
			}
			fail( error );
		}
		if ( alive.current ) {
			setBusy( false );
		}
	};

	useEffect( () => {
		alive.current = true;
		cancelled.current = false;
		go();
		return () => {
			alive.current = false;
			// Closed while it was still writing: stop asking.
			cancelled.current = true;
			call.current?.abort();
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Runs once; "Try again" runs it again.

	// "Retrying in N s", then it tries again by itself.
	useEffect( () => {
		if ( wait <= 0 ) {
			return;
		}
		const timer = window.setTimeout( () => {
			if ( wait === 1 ) {
				setWait( 0 );
				go();
			} else {
				setWait( wait - 1 );
			}
		}, 1000 );
		return () => window.clearTimeout( timer );
	}, [ wait ] ); // eslint-disable-line react-hooks/exhaustive-deps -- go() is stable enough for one tick.

	const finish = ( outcome: 'accepted' | 'edited' | 'discarded' ) => {
		if ( ! settled.current && runs.current.length ) {
			settled.current = true;
			runs.current.forEach( ( one ) => tell( one, outcome ) );
		}
	};
	const use = ( how: 'replace' | 'insert' ) => {
		finish( draft === run?.text.trim() ? 'accepted' : 'edited' );
		onUse?.( draft, how );
	};
	const unusable = busy || ! draft || !! failure || ! run;
	const swaps = job.transforms || !! job.replaceable;
	const close = () => {
		cancelled.current = true;
		call.current?.abort();
		// A summary is there to be read; reading it is keeping it.
		finish( 'summarise' === job.task ? 'accepted' : 'discarded' );
		onClose();
	};
	const retry = () => {
		// A draft asked for again replaces the one before, which was dropped.
		finish( 'discarded' );
		settled.current = false;
		runs.current = [];
		waited.current = 0;
		setRun( null );
		setDraft( '' );
		go();
	};
	// Words of one's own, written into the box over what the model wrote:
	// "Discard" is a choice, a stray Escape beside the dialog is not.
	const { dismiss, guard } = useUnsavedGuard(
		!! run && draft.trim() !== run.text.trim(),
		close,
		{
			title: __( 'Discard your changes to the draft?', 'cliezoo' ),
			description: __(
				'What you wrote over the draft goes with it, and nothing has been put in yet.',
				'cliezoo'
			),
		}
	);

	return (
		<Dialog
			isOpen
			size="lg"
			onOpenChange={ dismiss }
			title={ job.label }
			description={ __(
				'Written by AI. Check it before you use it; nothing is put in until you choose.',
				'cliezoo'
			) }
			footer={ () => (
				<div className="flex w-full flex-wrap items-center gap-2">
					{ /* Nothing to use until a draft has come back. */ }
					<Button variant="ghost" onPress={ close }>
						{ busy
							? __( 'Cancel', 'cliezoo' )
							: __( 'Discard', 'cliezoo' ) }
					</Button>
					<Button
						variant="ghost"
						icon={ <RefreshCw size={ 14 } aria-hidden="true" /> }
						isDisabled={ busy || wait > 0 }
						onPress={ retry }
					>
						{ __( 'Try again', 'cliezoo' ) }
					</Button>
					{ /* Which AI answers this person; an add-on may draw its own. */ }
					<Slot
						name="ai.draft.provider"
						badge={ false }
						props={ { busy, retry, payload: payload() } }
						fallback={
							<ProviderPick busy={ busy } retry={ retry } />
						}
					/>
					<Button
						variant="ghost"
						icon={ <Copy size={ 14 } aria-hidden="true" /> }
						isDisabled={ unusable }
						onPress={ async () => {
							if (
								await copyText(
									draft,
									__( 'Copied.', 'cliezoo' )
								)
							) {
								finish( 'accepted' );
							}
						} }
					>
						{ __( 'Copy', 'cliezoo' ) }
					</Button>
					<span className="ms-auto" />
					{ onUse && swaps && (
						<Button
							isDisabled={ unusable }
							onPress={ () => use( 'insert' ) }
						>
							{ __( 'Insert below', 'cliezoo' ) }
						</Button>
					) }
					{ onUse && (
						<Button
							variant="primary"
							isDisabled={ unusable || ( swaps && short ) }
							onPress={ () =>
								use( swaps ? 'replace' : 'insert' )
							}
						>
							{ swaps
								? __( 'Replace', 'cliezoo' )
								: __( 'Insert', 'cliezoo' ) }
						</Button>
					) }
				</div>
			) }
		>
			<p className="sr-only" role="status">
				{ draftStatus( { busy, failure, draft } ) }
			</p>
			{ busy && (
				<div className="space-y-2">
					<p className="text-sm text-muted">
						{ __( 'Writing…', 'cliezoo' ) }
					</p>
					{ live ? (
						// What has arrived so far. Not a live region: the
						// status line above says when the draft is ready,
						// rather than reading every word as it lands.
						<p className="whitespace-pre-wrap rounded-md border border-line p-3 text-sm">
							{ live }
						</p>
					) : (
						<Skeleton className="h-24" />
					) }
				</div>
			) }
			{ ! busy && failure && (
				<Failure
					failure={ failure }
					wait={ wait }
					admin={ admin }
					onLeave={ close }
				/>
			) }
			{ ! busy && ! failure && (
				<div className="space-y-3">
					{ short && (
						<Alert tone="warning">
							<p>
								{ __(
									'The answer ran out of room and may have stopped short, so it cannot replace the text yet.',
									'cliezoo'
								) }
							</p>
							<Button
								size="sm"
								className="mt-2"
								onPress={ carryOn }
							>
								{ __( 'Continue', 'cliezoo' ) }
							</Button>
						</Alert>
					) }
					{ job.transforms && (
						<Switch isSelected={ changes } onChange={ setChanges }>
							{ __( 'Show what changed', 'cliezoo' ) }
						</Switch>
					) }
					{ job.transforms && changes ? (
						<Changes before={ job.text } after={ draft } />
					) : null }
					<TextField
						label={ __( 'Draft', 'cliezoo' ) }
						multiline
						rows={ 8 }
						value={ draft }
						onChange={ setDraft }
						className={ cx( job.transforms && changes && 'mt-2' ) }
					/>
				</div>
			) }
			{ /* An overlay of its own, so where it sits in the tree is
				     only about which dialog it belongs to. */ }
			{ guard }
		</Dialog>
	);
}
