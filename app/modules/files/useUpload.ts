/**
 * Uploading, one chunk at a time, with something honest on screen while it
 * happens.
 *
 * A browser tab that looks frozen for four minutes is how people learn to
 * email attachments instead, so every file has its own row, its own
 * percentage, and its own error that does not take the others down with it.
 *
 * An upload that fails at 90% is the one that matters. The server keeps the
 * chunks it already has and will say which they are, so "Try again" picks
 * up where the line dropped instead of starting the file over.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, sendBytes, type ApiError } from '@cliezoo/data';
import { toast } from '@cliezoo/ui';

export interface Upload {
	id: string;
	name: string;
	size: number;
	/** 0–100, or null while the session is being set up. */
	percent: number | null;
	error?: string;
	done?: boolean;
	/** Stopped by the person, not by a failure. */
	stopped?: boolean;
	/** Whether the file is still in hand, so another go is possible. */
	canRetry?: boolean;
}

/**
 * How the batch is getting on, in one sentence.
 *
 * A bar that fills and a percentage that climbs are both silent, so this is
 * what the live region on the screen says. It is deliberately about the
 * batch and not about the percentages: it changes once per file finished
 * rather than several times a second, which is the difference between being
 * told what is happening and being talked over.
 *
 * @param uploads The rows on screen.
 */
export function uploadsSummary( uploads: Upload[] ): string {
	if ( ! uploads.length ) {
		return '';
	}
	const done = uploads.filter( ( one ) => one.done ).length;
	if ( done === uploads.length ) {
		return sprintf(
			/* translators: %d: how many files were uploaded. */
			_n( '%d file uploaded.', '%d files uploaded.', done, 'cliezoo' ),
			done
		);
	}
	return sprintf(
		/* translators: 1: how many are finished, 2: how many there are. */
		__( '%1$d of %2$d files uploaded.', 'cliezoo' ),
		done,
		uploads.length
	);
}

/**
 * Where the files are going: a folder, a client's or project's top, or a
 * record they are attached to (an invoice, a task, a deal).
 */
export interface UploadTarget {
	folder?: string;
	client?: string;
	project?: string;
	/** The entity a file is attached to, e.g. "invoices". */
	object_type?: string;
	/** That record's uid. */
	object?: string;
}

interface Session {
	uid: string;
	name: string;
	/** proxy: the parts come here; direct: straight to the storage. */
	mode: 'proxy' | 'direct';
	chunk_size: number;
	parts: number;
	/** For a direct upload, where each part goes (presigned, in order). */
	direct?: { parts: string[] } | null;
}

interface Job {
	file: File;
	/** The upload the server started, kept so a retry can resume it. */
	uid?: string;
	chunk?: number;
	/** A direct upload's addresses, one per part. */
	urls?: string[];
	abort: AbortController;
	stopped: boolean;
	/** A folder of its own (a folder uploaded whole), instead of the target. */
	folder?: string;
}

/**
 * Uploads files and reports what each one is doing.
 *
 * @param target Where they go.
 * @param onDone Called after each file finishes, to refresh what is on screen.
 */
export function useUpload(
	target: UploadTarget,
	onDone: ( uid?: string ) => void
) {
	const [ uploads, setUploads ] = useState< Upload[] >( [] );
	const counter = useRef( 0 );
	const jobs = useRef( new Map< string, Job >() );

	const patch = useCallback( ( id: string, values: Partial< Upload > ) => {
		setUploads( ( current ) =>
			current.map( ( one ) =>
				one.id === id ? { ...one, ...values } : one
			)
		);
	}, [] );

	const send = useCallback(
		async ( id: string ) => {
			const job = jobs.current.get( id );
			if ( ! job || job.stopped ) {
				return;
			}
			const file = job.file;
			try {
				patch( id, { error: undefined, percent: null } );

				// Somewhere to put it: a session the server already has, or
				// a new one. Asking for the old one back is what makes this
				// a resume rather than a second upload.
				let size = job.chunk ?? 0;
				let parts = 0;
				let have: number[] = [];
				if ( job.uid ) {
					const status = (
						await api.get< { parts: number; received: number[] } >(
							`/files/${ job.uid }/upload-session`
						)
					).data;
					parts = status.parts;
					have = status.received ?? [];
				} else {
					const session = (
						await api.post< Session >( '/files/upload-session', {
							...( job.folder ? { folder: job.folder } : target ),
							name: file.name,
							size: file.size,
							direct: true,
						} )
					).data;
					job.uid = session.uid;
					job.urls =
						'direct' === session.mode
							? ( session.direct?.parts ?? [] )
							: undefined;
					job.chunk = Math.max( 1, session.chunk_size );
					size = job.chunk;
					parts = session.parts;
				}

				let sent = have.length;
				patch( id, { percent: Math.round( ( sent / parts ) * 100 ) } );

				for ( let part = 0; part < parts; part += 1 ) {
					if ( job.stopped ) {
						return;
					}
					if ( have.includes( part ) ) {
						continue;
					}
					const slice = file.slice(
						part * size,
						( part + 1 ) * size
					);
					// One chunk at a time on purpose: several at once is
					// faster on a good line and unusable on a bad one, and
					// the server assembles them in order anyway.
					if ( job.urls ) {
						// Straight to the storage, at an address signed for
						// exactly this part; the server checks the whole file
						// when it is complete.
						const sentPart = await fetch( job.urls[ part ], {
							method: 'PUT',
							body: slice,
							credentials: 'omit',
							signal: job.abort.signal,
						} );
						if ( ! sentPart.ok ) {
							throw new Error(
								__(
									'The storage did not take part of the file.',
									'cliezoo'
								)
							);
						}
					} else {
						await sendBytes(
							`/files/${ job.uid }/chunks/${ part }`,
							slice,
							job.abort.signal
						);
					}
					sent += 1;
					patch( id, {
						percent: Math.round( ( sent / parts ) * 100 ),
					} );
				}

				await api.post( `/files/${ job.uid }/complete` );
				patch( id, { percent: 100, done: true, canRetry: false } );
				// The uid before the job goes: a screen that wants to do
				// something with the file it just uploaded — file it as a
				// signed contract, say — needs to know which file it was.
				const landed = job.uid;
				jobs.current.delete( id );
				onDone( landed );
			} catch ( error ) {
				if ( job.stopped ) {
					return;
				}
				patch( id, {
					canRetry: true,
					error:
						( error as ApiError ).message ||
						__( 'It could not be uploaded.', 'cliezoo' ),
				} );
			}
		},
		[ target, onDone, patch ]
	);

	const add = useCallback(
		( files: FileList | File[], folder?: string ) => {
			const queued = Array.from( files ).slice( 0, 50 );
			const rows = queued.map( ( file ) => {
				counter.current += 1;
				const id = `u${ counter.current }`;
				jobs.current.set( id, {
					file,
					abort: new AbortController(),
					stopped: false,
					folder,
				} );
				return {
					id,
					name: file.name,
					size: file.size,
					percent: null,
				} as Upload;
			} );
			setUploads( ( current ) => [ ...current, ...rows ] );
			// In turn, not all at once: fifty requests are faster on a good
			// line and unusable on a bad one, which is the same reason the
			// chunks of one file go up one at a time.
			queued.reduce(
				( wait, ignored, index ) =>
					wait.then( () => send( rows[ index ].id ) ),
				Promise.resolve()
			);
		},
		[ send ]
	);

	/**
	 * Stops one upload where it is. The chunks already sent stay on the
	 * server for a day and are then swept up, so stopping costs nothing and
	 * changing your mind costs nothing either.
	 */
	const stop = useCallback(
		( id: string ) => {
			const job = jobs.current.get( id );
			if ( ! job ) {
				return;
			}
			job.stopped = true;
			job.abort.abort();
			patch( id, {
				stopped: true,
				canRetry: true,
				error: undefined,
			} );
		},
		[ patch ]
	);

	const retry = useCallback(
		( id: string ) => {
			const job = jobs.current.get( id );
			if ( ! job ) {
				return;
			}
			job.stopped = false;
			job.abort = new AbortController();
			patch( id, { stopped: false, error: undefined } );
			send( id );
		},
		[ patch, send ]
	);

	const dismiss = useCallback( ( id: string ) => {
		const job = jobs.current.get( id );
		if ( job ) {
			job.stopped = true;
			job.abort.abort();
			jobs.current.delete( id );
		}
		setUploads( ( current ) => current.filter( ( one ) => one.id !== id ) );
	}, [] );

	const clearFinished = useCallback( () => {
		setUploads( ( current ) => current.filter( ( one ) => ! one.done ) );
	}, [] );

	// The queue lives in this hook and nowhere else, so it goes when the
	// screen goes. Closing the tab asks first; walking away inside the app
	// stops the jobs and says how many, rather than leaving a chunk loop
	// running against a panel nobody can see any more.
	const running = useRef( 0 );
	useEffect( () => {
		running.current = uploads.filter(
			( one ) => ! one.done && ! one.stopped && ! one.error
		).length;
	}, [ uploads ] );

	useEffect( () => {
		const warn = ( event: BeforeUnloadEvent ) => {
			if ( running.current ) {
				event.preventDefault();
			}
		};
		window.addEventListener( 'beforeunload', warn );
		const queue = jobs.current;
		return () => {
			window.removeEventListener( 'beforeunload', warn );
			const stopped = running.current;
			queue.forEach( ( job ) => {
				job.stopped = true;
				job.abort.abort();
			} );
			queue.clear();
			if ( stopped ) {
				toast.error(
					sprintf(
						/* translators: %d: how many uploads were stopped. */
						_n(
							'%d upload stopped when you left the files.',
							'%d uploads stopped when you left the files.',
							stopped,
							'cliezoo'
						),
						stopped
					)
				);
			}
		};
	}, [] );

	return { uploads, add, stop, retry, dismiss, clearFinished };
}
