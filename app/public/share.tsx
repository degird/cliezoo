/**
 * The page at the end of a shared link.
 *
 * Whoever opens this has no account and did not choose to be here — they
 * were sent a link. So the page asks for nothing it does not need, says
 * plainly what it is and who sent it, and puts the download where a hand
 * lands. It is deliberately not the app: no router, no query client, no
 * design system, because all of that would be shipped to a stranger to
 * render one list.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { formatBytes, getBoot } from '@cliezoo/data';

interface ShareFile {
	uid: string;
	name: string;
	size: number;
	mime: string;
	is_image: boolean;
	url: string | null;
}

interface PublicCard {
	uid: string;
	title: string;
	due_date?: string | null;
	priority?: string;
	done?: boolean;
	labels?: Array< { uid: string; label: string; color?: string } >;
	assignees?: string[];
}

interface PublicList {
	uid: string;
	name: string;
	color?: string | null;
	is_done?: boolean;
	cards: PublicCard[];
}

interface Share {
	kind?: 'file' | 'folder' | 'board';
	name?: string;
	/** The folder that was shared, when you are somewhere below it. */
	shared?: string;
	trail?: Array< { uid: string; name: string } >;
	folders?: Array< { uid: string; name: string } >;
	files?: ShareFile[];
	file?: ShareFile;
	/** Whether this folder holds more than one page of things. */
	more?: boolean;
	zip?: string | null;
	size?: number;
	can_download?: boolean;
	/** A shared board: its columns and the cards marked for the client. */
	about?: string;
	lists?: PublicList[];
	cards?: number;
	needs_passcode?: boolean;
	needs_email?: boolean;
}

const CARD = 'rounded-xl border border-line bg-raised p-6 shadow-md sm:p-8';
const BUTTON =
	'inline-flex items-center justify-center rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-contrast transition hover:opacity-90 disabled:opacity-60';
const QUIET =
	'inline-flex items-center justify-center rounded-lg border border-line px-3 py-1.5 text-sm text-fg transition hover:bg-sunken';
const INPUT =
	'w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-fg';

/**
 * Asks the server what is behind this token.
 *
 * @param body token, passcode, email, folder.
 */
async function open( body: Record< string, string > ): Promise< Share > {
	let response: Response;
	try {
		response = await fetch( `${ getBoot().rest.root }public/share`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} );
	} catch {
		// A dropped connection throws the browser's own "Failed to fetch",
		// which reads like the link is broken when nothing is.
		throw new Error(
			__(
				'This page could not reach the site. Check your connection, then try again.',
				'cliezoo'
			)
		);
	}
	const payload = await response.json().catch( () => null );
	if ( ! response.ok ) {
		throw new Error(
			payload?.message ?? __( 'This link is not available.', 'cliezoo' )
		);
	}
	return ( payload?.data ?? {} ) as Share;
}

/**
 * One file in the list, with its address when there is one.
 *
 * @param root0      Props.
 * @param root0.file The file.
 */
function FileRow( { file }: { file: ShareFile } ) {
	return (
		<li className="flex items-center gap-3 py-2.5">
			<span className="min-w-0 flex-1">
				<span className="block truncate text-sm text-fg">
					{ file.name }
				</span>
				<span className="text-xs text-muted">
					{ formatBytes( file.size ) }
				</span>
			</span>
			{ file.url && (
				<a className={ QUIET } href={ file.url } download>
					{ __( 'Download', 'cliezoo' ) }
				</a>
			) }
		</li>
	);
}

/**
 * A small form the page shows when the sender asked for something before
 * letting anyone in.
 *
 * @param root0          Props.
 * @param root0.title    Heading.
 * @param root0.help     What to say under it.
 * @param root0.label    Field label.
 * @param root0.type     Input type.
 * @param root0.busy     Whether the answer is being checked.
 * @param root0.error    What went wrong last time.
 * @param root0.onSubmit Called with the value.
 */
function Ask( {
	title,
	help,
	label,
	type,
	busy,
	error,
	onSubmit,
}: {
	title: string;
	help: string;
	label: string;
	type: string;
	busy: boolean;
	error: string;
	onSubmit: ( value: string ) => void;
} ) {
	const [ value, setValue ] = useState( '' );
	return (
		<form
			className="space-y-3"
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSubmit( value );
			} }
		>
			<h1 className="text-lg font-semibold text-fg">{ title }</h1>
			<p className="text-sm text-muted">{ help }</p>
			<div className="space-y-1.5">
				<label
					className="block text-sm font-medium text-fg"
					htmlFor="cliezoo-ask"
				>
					{ label }
				</label>
				<input
					id="cliezoo-ask"
					className={ INPUT }
					type={ type }
					value={ value }
					autoComplete={ 'password' === type ? 'off' : 'email' }
					onChange={ ( event ) => setValue( event.target.value ) }
				/>
			</div>
			{ /* The standalone bundle has no Toaster, so this paragraph is
			     the only word a wrong passcode gets. It has to announce
			     itself, or the form simply looks as though nothing
			     happened. */ }
			{ !! error && (
				<p role="alert" className="text-sm text-danger">
					{ error }
				</p>
			) }
			<button className={ BUTTON } type="submit" disabled={ busy }>
				{ __( 'Continue', 'cliezoo' ) }
			</button>
		</form>
	);
}

/**
 * The shared file or folder itself.
 *
 * @param root0        Props.
 * @param root0.share  What the server said.
 * @param root0.onOpen Look inside a folder.
 */
function Contents( {
	share,
	onOpen,
}: {
	share: Share;
	onOpen: ( uid: string ) => void;
} ) {
	const sender = getBoot().branding.logoAlt;

	// Opening a folder swaps the heading underneath a button that then
	// stops existing, and focus falls back to the body: a screen reader is
	// left reading the folder you have just left. Moving focus to the new
	// heading says where you have arrived. Not on the way in, though —
	// that is where focus already is.
	const heading = useRef< HTMLHeadingElement >( null );
	const landed = useRef( false );
	const here = ( share.trail ?? [] )
		.map( ( step ) => step.uid )
		.concat( share.name ?? '' )
		.join( '/' );
	useEffect( () => {
		if ( ! landed.current ) {
			landed.current = true;
			return;
		}
		heading.current?.focus();
	}, [ here ] );

	if ( 'file' === share.kind && share.file ) {
		return (
			<div className="space-y-4 text-center">
				<p className="text-xs uppercase tracking-wider text-subtle">
					{ sprintf(
						/* translators: %s: the workspace that shared it. */
						__( '%s shared a file with you', 'cliezoo' ),
						sender
					) }
				</p>
				<h1 className="break-words text-lg font-semibold text-fg">
					{ share.file.name }
				</h1>
				<p className="text-sm text-muted">
					{ formatBytes( share.file.size ) }
				</p>
				{ share.file.url ? (
					<a className={ BUTTON } href={ share.file.url } download>
						{ __( 'Download', 'cliezoo' ) }
					</a>
				) : (
					<p className="text-sm text-muted">
						{ __(
							'The sender turned downloading off for this link.',
							'cliezoo'
						) }
					</p>
				) }
			</div>
		);
	}

	if ( 'board' === share.kind ) {
		return <Board share={ share } sender={ sender } />;
	}

	const folders = share.folders ?? [];
	const files = share.files ?? [];
	const trail = share.trail ?? [];

	return (
		<div className="space-y-4">
			<div>
				<p className="text-xs uppercase tracking-wider text-subtle">
					{ sprintf(
						/* translators: %s: the workspace that shared it. */
						__( '%s shared a folder with you', 'cliezoo' ),
						sender
					) }
				</p>
				<h1
					ref={ heading }
					tabIndex={ -1 }
					className="mt-1 break-words text-lg font-semibold text-fg outline-none"
				>
					{ share.name }
				</h1>
			</div>

			{ !! trail.length && (
				<nav
					className="flex flex-wrap items-center gap-1 text-sm text-muted"
					aria-label={ __( 'Where you are', 'cliezoo' ) }
				>
					<button
						className="underline-offset-2 hover:underline"
						type="button"
						onClick={ () => onOpen( '' ) }
					>
						{ share.shared }
					</button>
					{ trail.slice( 1 ).map( ( step ) => (
						<span key={ step.uid }>
							{ ' / ' }
							<button
								className="underline-offset-2 hover:underline"
								type="button"
								onClick={ () => onOpen( step.uid ) }
							>
								{ step.name }
							</button>
						</span>
					) ) }
					<span>{ ` / ${ share.name ?? '' }` }</span>
				</nav>
			) }

			{ ! folders.length && ! files.length && (
				<p className="text-sm text-muted">
					{ __( 'There is nothing in this folder.', 'cliezoo' ) }
				</p>
			) }

			{ !! folders.length && (
				<ul className="divide-y divide-line">
					{ folders.map( ( folder ) => (
						<li key={ folder.uid }>
							<button
								className="flex w-full items-center gap-3 py-2.5 text-start text-sm text-fg hover:text-accent-fg"
								type="button"
								onClick={ () => onOpen( folder.uid ) }
							>
								<span className="truncate">
									{ folder.name }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }

			{ !! files.length && (
				<ul className="divide-y divide-line">
					{ files.map( ( file ) => (
						<FileRow key={ file.uid } file={ file } />
					) ) }
				</ul>
			) }

			{ ! share.can_download &&
				( !! files.length || !! folders.length ) && (
					<p className="text-sm text-muted">
						{ __(
							'The sender turned downloading off for this link, so these can be seen but not taken away.',
							'cliezoo'
						) }
					</p>
				) }

			{ /* Only the version with a way through says there is one: with
			     downloading turned off there is no "Download everything"
			     button below to press, and naming it was sending people
			     looking for something that is not on the page. */ }
			{ !! share.more && (
				<p className="text-sm text-muted">
					{ share.zip
						? __(
								'There is more in here than fits on one page. Downloading everything gets all of it.',
								'cliezoo'
							)
						: __(
								'There is more in here than fits on one page, so only part of it is listed. Ask the sender for the rest.',
								'cliezoo'
							) }
				</p>
			) }

			{ !! share.zip && (
				<a className={ BUTTON } href={ share.zip }>
					{ sprintf(
						/* translators: %s: total size, e.g. "12 MB". */
						__( 'Download everything (%s)', 'cliezoo' ),
						formatBytes( share.size ?? 0 )
					) }
				</a>
			) }
		</div>
	);
}

/**
 * A board, to read.
 *
 * It is columns and cards and nothing else: no dragging, no menus, no
 * "add a card". Somebody watching a board without an account is watching,
 * and a control that looks live and is not is worse than no control.
 *
 * @param root0        Props.
 * @param root0.share  What the server said.
 * @param root0.sender Who shared it.
 */
function Board( { share, sender }: { share: Share; sender: string } ) {
	const lists = share.lists ?? [];
	return (
		<div className="space-y-4">
			<div>
				<p className="text-xs uppercase tracking-wider text-subtle">
					{ sprintf(
						/* translators: %s: the workspace that shared it. */
						__( '%s shared a board with you', 'cliezoo' ),
						sender
					) }
				</p>
				<h1 className="mt-1 break-words text-lg font-semibold text-fg">
					{ share.name }
				</h1>
				{ !! share.about && (
					<p className="mt-1 text-sm text-muted">{ share.about }</p>
				) }
			</div>

			{ ! share.cards && (
				<p className="text-sm text-muted">
					{ __(
						'Nothing on this board has been marked for you yet.',
						'cliezoo'
					) }
				</p>
			) }

			{ /* The row of lists scrolls sideways, so it is somewhere the
			     keyboard can reach and arrow through. */ }
			<div
				className="flex gap-3 overflow-x-auto pb-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
				tabIndex={ 0 }
				role="group"
				aria-label={ __( 'Lists on this board', 'cliezoo' ) }
			>
				{ lists.map( ( list ) => (
					<section
						key={ list.uid }
						aria-label={ list.name }
						className="w-64 shrink-0 rounded-xl bg-sunken p-2"
					>
						<h2 className="flex items-center gap-2 px-1 pb-2 text-sm font-semibold text-fg">
							{ !! list.color && (
								<span
									aria-hidden="true"
									className="size-2.5 rounded-full"
									style={ { backgroundColor: list.color } }
								/>
							) }
							{ list.name }
							<span className="text-xs text-subtle">
								{ list.cards.length }
							</span>
						</h2>
						<ul className="space-y-2">
							{ list.cards.map( ( card ) => (
								<li
									key={ card.uid }
									className="rounded-lg border border-line bg-raised p-2.5"
								>
									<p
										className={
											card.done
												? 'text-sm text-muted line-through'
												: 'text-sm text-fg'
										}
									>
										{ card.title }
									</p>
									<p className="mt-1 text-xs text-muted">
										{ [
											card.due_date
												? sprintf(
														/* translators: %s: a date. */
														__(
															'Due %s',
															'cliezoo'
														),
														card.due_date
													)
												: '',
											( card.assignees ?? [] ).join(
												', '
											),
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</p>
								</li>
							) ) }
							{ ! list.cards.length && (
								<li className="px-1 py-4 text-center text-xs text-subtle">
									{ __( 'Nothing here', 'cliezoo' ) }
								</li>
							) }
						</ul>
					</section>
				) ) }
			</div>

			{ !! share.more && (
				<p className="text-sm text-muted">
					{ __(
						'There is more on this board than fits on one page.',
						'cliezoo'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * The page.
 *
 * @param root0       Props.
 * @param root0.token The token from the address.
 */
export default function SharePage( { token }: { token: string } ) {
	const [ share, setShare ] = useState< Share | null >( null );
	const [ error, setError ] = useState( '' );
	// A failure that arrived while something good was already on screen,
	// and the folder it was reaching for.
	const [ trouble, setTrouble ] = useState< {
		message: string;
		folder: string;
	} | null >( null );
	const [ asked, setAsked ] = useState( '' );
	const [ passcode, setPasscode ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ folder, setFolder ] = useState( '' );
	const [ busy, setBusy ] = useState( true );

	const load = useCallback(
		async ( next: {
			passcode?: string;
			email?: string;
			folder?: string;
		} ) => {
			const wanted = next.folder ?? folder;
			setBusy( true );
			setAsked( '' );
			try {
				const result = await open( {
					token,
					passcode: next.passcode ?? passcode,
					email: next.email ?? email,
					folder: wanted,
				} );
				setShare( result );
				// Where the visitor is is where the server took them, so
				// the folder is remembered only once it has opened.
				setFolder( wanted );
				setError( '' );
				setTrouble( null );
			} catch ( problem ) {
				const message = ( problem as Error ).message;
				// A wrong passcode is not a dead link: stay on the form.
				if ( share?.needs_passcode || share?.needs_email ) {
					setAsked( message );
				} else if ( share ) {
					// One folder that will not open is not the end of the
					// link. What the visitor was reading stays where it is.
					setTrouble( { message, folder: wanted } );
				} else {
					setError( message );
				}
			} finally {
				setBusy( false );
			}
		},
		[ token, passcode, email, folder, share ]
	);

	useEffect( () => {
		load( {} );
		// Once, on the way in.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( error ) {
		return (
			<div className={ `${ CARD } max-w-sm text-center` }>
				<h1 className="text-lg font-semibold text-fg">
					{ __( 'This link is not available', 'cliezoo' ) }
				</h1>
				<p className="mt-2 text-sm text-muted">{ error }</p>
				<p className="mt-2 text-sm text-muted">
					{ __(
						'It may have expired or been turned off. Ask the sender for a new link.',
						'cliezoo'
					) }
				</p>
				{ /* Most of what lands here is a connection that dropped,
				     so the way out is a second go and not a dead end. */ }
				<button
					className={ `${ QUIET } mt-4` }
					type="button"
					disabled={ busy }
					onClick={ () => load( {} ) }
				>
					{ __( 'Try again', 'cliezoo' ) }
				</button>
			</div>
		);
	}

	if ( ! share ) {
		return (
			<div className={ `${ CARD } max-w-sm text-center` }>
				<p role="status" className="text-sm text-muted">
					{ __( 'Opening…', 'cliezoo' ) }
				</p>
			</div>
		);
	}

	if ( share.needs_passcode ) {
		return (
			<div className={ `${ CARD } w-full max-w-sm` }>
				{ /* Each gate is its own form: without a key of its own,
				     React keeps the last one's state and the passcode
				     somebody typed reappears in the email field. */ }
				<Ask
					key="passcode"
					title={ __( 'This link has a passcode', 'cliezoo' ) }
					help={ __(
						'Whoever sent you the link has the passcode.',
						'cliezoo'
					) }
					label={ __( 'Passcode', 'cliezoo' ) }
					type="password"
					busy={ busy }
					error={ asked }
					onSubmit={ ( value ) => {
						setPasscode( value );
						load( { passcode: value } );
					} }
				/>
			</div>
		);
	}

	if ( share.needs_email ) {
		return (
			<div className={ `${ CARD } w-full max-w-sm` }>
				<Ask
					key="email"
					title={ __( 'Who is opening this?', 'cliezoo' ) }
					help={ __(
						'The sender asked to know who opens this link.',
						'cliezoo'
					) }
					label={ __( 'Your email', 'cliezoo' ) }
					type="email"
					busy={ busy }
					error={ asked }
					onSubmit={ ( value ) => {
						setEmail( value );
						load( { email: value } );
					} }
				/>
			</div>
		);
	}

	return (
		<div
			className={ `${ CARD } w-full ${
				'board' === share.kind ? 'max-w-5xl' : 'max-w-xl'
			}` }
		>
			{ !! trouble && (
				<div
					role="alert"
					className="mb-4 rounded-lg border border-line bg-sunken p-3"
				>
					<p className="text-sm text-fg">
						{ __(
							'That could not be opened. What you were reading is still here.',
							'cliezoo'
						) }
					</p>
					<p className="mt-1 text-sm text-muted">
						{ trouble.message }
					</p>
					<button
						className={ `${ QUIET } mt-2` }
						type="button"
						disabled={ busy }
						onClick={ () => load( { folder: trouble.folder } ) }
					>
						{ __( 'Try again', 'cliezoo' ) }
					</button>
				</div>
			) }
			<Contents
				share={ share }
				onOpen={ ( uid ) => load( { folder: uid } ) }
			/>
		</div>
	);
}
