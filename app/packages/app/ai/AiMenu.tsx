/**
 * The AI button every editor and composer has: write from a line, rewrite,
 * shorten, expand, fix the grammar, change the tone, translate — and, on a
 * record, summarise it, draft a reply or write its description.
 *
 * Whatever it writes comes back as a draft beside what was there. Nothing
 * is put in until the person says so (approval level "draft", Free), and
 * what is put in can be taken back out with Undo. When the workspace asks
 * for it, what goes in says it was written by AI (§6.43.5).
 */
import { Suspense, lazy, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { Sparkles } from 'lucide-react';
import {
	AriaDialog,
	Button,
	Dialog,
	DialogTrigger,
	IconButton,
	Menu,
	MenuItem,
	MenuSection,
	MenuTrigger,
	Popover,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import { htmlToText, labelled, textToHtml } from './text';
import { useAi } from './useAi';
import type { AiJob } from './AiDraft';

const AiDraft = lazy(
	() => import( /* webpackChunkName: "ai" */ './AiDraft' )
);

export type AiExtra = 'summarise' | 'draft_reply' | 'task_description';

export interface AiMenuProps {
	/** The part of the workspace (module id), for "AI is off here". */
	place: string;
	/** What the helpers work on: the selection, or all of it. */
	source: () => { text: string; selected: boolean };
	/**
	 * Puts a draft in: in place of the source, or after it. It may return
	 * a function that puts back what was there, which is offered as Undo,
	 * or `false` when nothing went in — the draft is then kept on screen.
	 */
	apply: (
		text: string,
		how: 'replace' | 'insert'
	) => void | false | ( () => void );
	/** The record it is on, for the helpers that read it. */
	record?: { type: string; uid: string };
	/** The helpers that read the record. */
	extras?: AiExtra[];
	/** What there is to go on when the record is not saved yet. */
	about?: () => string;
	/**
	 * Only a selection can be changed (a doc: rewriting the whole of it as
	 * plain text would lose its headings, lists and pictures).
	 */
	selectionOnly?: boolean;
	className?: string;
}

/**
 * How a tone is called on the button.
 *
 * @param tone Tone id.
 */
export function toneLabel( tone: string ): string {
	const labels: Record< string, string > = {
		formal: __( 'Formal', 'cliezoo' ),
		friendly: __( 'Friendly', 'cliezoo' ),
		persuasive: __( 'Persuasive', 'cliezoo' ),
		confident: __( 'Confident', 'cliezoo' ),
		plain: __( 'Plain and simple', 'cliezoo' ),
	};
	return labels[ tone ] ?? tone;
}

/**
 * Why the helpers that change text are greyed, or nothing when they are
 * not. A greyed row with no reason beside it reads as a fault; in a doc,
 * where only a selection can be changed, it is also the common case.
 *
 * @param empty         Whether there is any text for them to work on.
 * @param selectionOnly Whether only a selection can be changed.
 */
export function whyGreyed( empty: boolean, selectionOnly: boolean ): string {
	if ( ! empty ) {
		return '';
	}
	return selectionOnly
		? __( 'select some text first', 'cliezoo' )
		: __( 'write something first', 'cliezoo' );
}

/**
 * A section heading carrying the reason its rows cannot be used.
 *
 * @param title  The heading.
 * @param reason Why the rows are greyed, or nothing.
 */
export function headingWith( title: string, reason: string ): string {
	return reason
		? sprintf(
				/* translators: 1: a menu section heading, 2: why its items cannot be used. */
				__( '%1$s — %2$s', 'cliezoo' ),
				title,
				reason
			)
		: title;
}

/** The helpers that change text that is already there. */
const CHANGERS = (): Array< [ string, string ] > => [
	[ 'rewrite', __( 'Rewrite', 'cliezoo' ) ],
	[ 'shorten', __( 'Shorten', 'cliezoo' ) ],
	[ 'expand', __( 'Expand', 'cliezoo' ) ],
	[ 'fix_grammar', __( 'Fix spelling and grammar', 'cliezoo' ) ],
	[ 'translate', __( 'Translate…', 'cliezoo' ) ],
];

/** Languages offered first; any other can be typed. */
const LANGUAGES = [
	'English',
	'Spanish',
	'French',
	'German',
	'Italian',
	'Portuguese',
	'Dutch',
	'Arabic',
	'Bengali',
	'Hindi',
	'Chinese',
	'Japanese',
];

function NotReady( {
	admin,
	className,
}: {
	admin: boolean;
	className?: string;
} ) {
	// One heading for everybody: the old one told a member of staff who
	// cannot change a setting to go and connect something, and "provider"
	// is a word from the settings screen that nobody outside it uses.
	const title = __( 'AI is not set up yet', 'cliezoo' );
	return (
		<DialogTrigger>
			<IconButton
				size="sm"
				className={ className }
				label={ __( 'AI writing help', 'cliezoo' ) }
				icon={ <Sparkles size={ 15 } aria-hidden="true" /> }
			/>
			<Popover placement="bottom start">
				<AriaDialog
					aria-label={ title }
					className="w-72 p-3 text-sm outline-none"
				>
					<p className="font-medium text-fg">{ title }</p>
					<p className="mt-1 text-muted">
						{ admin
							? __(
									'Once it is set up and switched on, AI can help you write and sum things up. Everything still works by hand.',
									'cliezoo'
								)
							: __(
									'Somebody who looks after this workspace can set it up. Everything still works by hand.',
									'cliezoo'
								) }
					</p>
					{ admin && (
						<Link
							to="/settings/$section"
							params={ { section: 'ai' } as never }
							hash="ai-providers"
							className="mt-2 inline-block text-accent-fg hover:underline"
						>
							{ __( 'Set up AI', 'cliezoo' ) }
						</Link>
					) }
				</AriaDialog>
			</Popover>
		</DialogTrigger>
	);
}

export function AiMenu( {
	place,
	source,
	apply,
	record,
	extras = [],
	about,
	selectionOnly = false,
	className,
}: AiMenuProps ) {
	const ai = useAi( place );
	const [ asking, setAsking ] = useState< {
		task: string;
		title: string;
		field: 'prompt' | 'language';
		required: boolean;
		snapshot: { text: string; selected: boolean };
	} | null >( null );
	const [ answer, setAnswer ] = useState( '' );
	const [ job, setJob ] = useState< AiJob | null >( null );
	// Read when the menu opens, not when the page draws: an editor that
	// was not ready then has text by now.
	const [ empty, setEmpty ] = useState( true );
	const take = () => {
		const now = source();
		return selectionOnly && ! now.selected
			? { text: '', selected: false }
			: now;
	};

	if ( ! ai.shown ) {
		return null;
	}
	if ( ! ai.ready ) {
		return <NotReady admin={ ai.admin } className={ className } />;
	}

	const has = ( id: string ) => !! ai.task( id );
	const changers = CHANGERS().filter( ( [ id ] ) => has( id ) );
	const reason = whyGreyed( empty, selectionOnly );
	// With nothing written yet there is nothing to change.
	const changing = [
		'rewrite',
		'shorten',
		'expand',
		'fix_grammar',
		'translate',
		...ai.tones.map( ( tone ) => `tone:${ tone }` ),
	];
	const start = (
		task: string,
		options: Record< string, string > = {},
		snapshot = take()
	) => {
		const reads = ( extras as string[] ).includes( task );
		// A helper that reads the record gets the record; what is half
		// written in the box is not part of it. Unsaved, it goes on what
		// there is.
		let text = snapshot.text;
		if ( reads ) {
			text = ! record && about ? about() : '';
		}
		setJob( {
			task,
			label: ai.task( task )?.label ?? task,
			place,
			options,
			text,
			selected: snapshot.selected,
			record: reads ? record : undefined,
			// Helpers that change text are compared with what was there.
			transforms: ! reads && task !== 'write',
			// A description already written can be replaced by a new one.
			replaceable: 'task_description' === task && !! source().text.trim(),
		} );
	};
	const ask = (
		task: string,
		title: string,
		field: 'prompt' | 'language',
		required: boolean
	) => {
		setAnswer( '' );
		setAsking( { task, title, field, required, snapshot: take() } );
	};

	return (
		<>
			<MenuTrigger
				onOpenChange={ ( open: boolean ) =>
					open && setEmpty( ! take().text.trim() )
				}
			>
				<IconButton
					size="sm"
					className={ className }
					label={ __( 'AI writing help', 'cliezoo' ) }
					icon={ <Sparkles size={ 15 } aria-hidden="true" /> }
				/>
				<Menu
					placement="bottom start"
					disabledKeys={ empty ? changing : [] }
					onAction={ ( key: unknown ) => {
						const id = String( key );
						if ( id === 'write' ) {
							ask(
								'write',
								__( 'What should it write?', 'cliezoo' ),
								'prompt',
								true
							);
						} else if ( id === 'translate' ) {
							ask(
								'translate',
								__( 'Translate into', 'cliezoo' ),
								'language',
								true
							);
						} else if ( id.startsWith( 'tone:' ) ) {
							start( 'change_tone', { tone: id.slice( 5 ) } );
						} else if (
							id === 'draft_reply' ||
							id === 'task_description'
						) {
							ask(
								id,
								id === 'draft_reply'
									? __(
											'Anything the reply should say?',
											'cliezoo'
										)
									: __(
											'Anything the description should cover?',
											'cliezoo'
										),
								'prompt',
								false
							);
						} else {
							start( id );
						}
					} }
				>
					{ has( 'write' ) && (
						<MenuSection id="ai-write">
							<MenuItem id="write">
								{ __( 'Write…', 'cliezoo' ) }
							</MenuItem>
						</MenuSection>
					) }
					{ /* An administrator can turn every one of these off,
					     and a heading with nothing under it is a menu that
					     looks broken. */ }
					{ !! changers.length && (
						<MenuSection
							id="ai-change"
							title={ headingWith(
								__( 'Change the text', 'cliezoo' ),
								reason
							) }
						>
							{ changers.map( ( [ id, label ] ) => (
								<MenuItem key={ id } id={ id }>
									{ label }
								</MenuItem>
							) ) }
						</MenuSection>
					) }
					{ has( 'change_tone' ) && !! ai.tones.length && (
						<MenuSection
							id="ai-tone"
							title={ headingWith(
								__( 'Change the tone', 'cliezoo' ),
								reason
							) }
						>
							{ ai.tones.map( ( tone ) => (
								<MenuItem key={ tone } id={ `tone:${ tone }` }>
									{ toneLabel( tone ) }
								</MenuItem>
							) ) }
						</MenuSection>
					) }
					{ ( !! record || !! about ) && extras.some( has ) && (
						<MenuSection
							id="ai-record"
							title={ __( 'From this record', 'cliezoo' ) }
						>
							{ extras.filter( has ).map( ( id ) => (
								<MenuItem key={ id } id={ id }>
									{ ai.task( id )?.label ?? id }
								</MenuItem>
							) ) }
						</MenuSection>
					) }
				</Menu>
			</MenuTrigger>

			{ asking && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( open ) => ! open && setAsking( null ) }
					title={ asking.title }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={
									asking.required && ! answer.trim()
								}
								onPress={ () => {
									const chosen = asking;
									setAsking( null );
									start(
										chosen.task,
										answer.trim()
											? {
													[ chosen.field ]:
														answer.trim(),
												}
											: {},
										chosen.snapshot
									);
								} }
							>
								{ __( 'Draft it', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					{ asking.field === 'language' ? (
						<div className="space-y-2">
							<TextField
								label={ __( 'Language', 'cliezoo' ) }
								value={ answer }
								onChange={ setAnswer }
								maxLength={ 40 }
							/>
							<div className="flex flex-wrap gap-1">
								{ LANGUAGES.map( ( language ) => (
									<button
										key={ language }
										type="button"
										onClick={ () => setAnswer( language ) }
										className={ cx(
											'rounded-full border border-line px-2 py-0.5 text-xs hover:bg-sunken',
											answer === language &&
												'border-accent text-accent-fg'
										) }
									>
										{ language }
									</button>
								) ) }
							</div>
						</div>
					) : (
						<TextField
							label={ asking.title }
							multiline
							rows={ 3 }
							value={ answer }
							onChange={ setAnswer }
							maxLength={ 2000 }
						/>
					) }
				</Dialog>
			) }

			{ job && (
				<Suspense fallback={ null }>
					<AiDraft
						job={ job }
						admin={ ai.admin }
						onClose={ () => setJob( null ) }
						onUse={ ( text, how ) => {
							const undo = apply(
								ai.label ? labelled( text ) : text,
								how
							);
							if ( false === undo ) {
								// Nothing went in, so the draft stays where
								// it is rather than being thrown away with
								// the dialog.
								toast.error(
									__(
										'The draft could not be put in. Nothing has changed.',
										'cliezoo'
									)
								);
								return;
							}
							setJob( null );
							// Whether or not there is an Undo to offer, what
							// happened to the text is said out loud.
							toast.success(
								'replace' === how
									? __(
											'Replaced with the AI draft.',
											'cliezoo'
										)
									: __( 'AI draft put in.', 'cliezoo' ),
								'function' === typeof undo
									? {
											action: {
												label: __( 'Undo', 'cliezoo' ),
												onAction: undo,
											},
										}
									: undefined
							);
						} }
					/>
				</Suspense>
			) }
		</>
	);
}

/**
 * The AI button for a plain text box: it works on all of the text, and a
 * field kept as HTML is read as words and written back as HTML.
 *
 * @param root0          Props.
 * @param root0.value    The text.
 * @param root0.onChange Changes it.
 * @param root0.html     Whether the field keeps HTML.
 */
export function AiForText( {
	value,
	onChange,
	html = false,
	...menu
}: Omit< AiMenuProps, 'source' | 'apply' > & {
	value: string;
	onChange: ( value: string ) => void;
	html?: boolean;
} ) {
	return (
		<AiMenu
			{ ...menu }
			source={ () => ( {
				text: html ? htmlToText( value ) : value,
				selected: false,
			} ) }
			apply={ ( written, how ) => {
				const before = value;
				const next = html ? textToHtml( written ) : written;
				let joined = next;
				if ( 'insert' === how && value.trim() ) {
					joined = html ? value + next : `${ value }\n\n${ next }`;
				}
				onChange( joined );
				// Undo puts back exactly what was there before.
				return () => onChange( before );
			} }
		/>
	);
}
