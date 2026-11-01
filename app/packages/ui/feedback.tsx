/**
 * Feedback and display primitives: toasts, spinner, skeleton, empty state,
 * alert, badge, avatar, keyboard hint.
 */
import { useEffect, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { create } from 'zustand';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { cx } from './utils';

/* Toasts ----------------------------------------------------------------- */

export type ToastTone = 'success' | 'error' | 'info' | 'warning';

export interface ToastItem {
	id: number;
	tone: ToastTone;
	message: string;
	action?: { label: string; onAction: () => void };
	timeout: number;
}

interface ToastState {
	toasts: ToastItem[];
	push: ( toast: Omit< ToastItem, 'id' > ) => number;
	dismiss: ( id: number ) => void;
}

let nextId = 1;

export const useToasts = create< ToastState >()( ( set ) => ( {
	toasts: [],
	push: ( toast ) => {
		const id = nextId++;
		set( ( state ) => ( {
			toasts: [ ...state.toasts.slice( -4 ), { ...toast, id } ],
		} ) );
		return id;
	},
	dismiss: ( id ) =>
		set( ( state ) => ( {
			toasts: state.toasts.filter( ( t ) => t.id !== id ),
		} ) ),
} ) );

type ToastOptions = { action?: ToastItem[ 'action' ]; timeout?: number };

const show =
	( tone: ToastTone ) =>
	( message: string, opts: ToastOptions = {} ) =>
		useToasts.getState().push( {
			tone,
			message,
			action: opts.action,
			timeout:
				opts.timeout ??
				( opts.action || tone === 'error' ? 8000 : 4000 ),
		} );

/**
 * Toasts for success/info; errors that belong to a form are shown inline instead.
 * Offer `action: { label: 'Undo', onAction }` instead of "Are you sure?" where possible.
 */
export const toast = {
	success: show( 'success' ),
	error: show( 'error' ),
	info: show( 'info' ),
	warning: show( 'warning' ),
	dismiss: ( id: number ) => useToasts.getState().dismiss( id ),
};

/** What a batch of changes did, for the toast that reports it. */
export interface Outcome {
	/** How many the server actually changed. */
	done: number;
	/** Why each one that did not go through did not, as the server said. */
	reasons: string[];
	/** What to say when every one of them worked. */
	said: string;
	/**
	 * What to say when only some did.
	 *
	 * @param done   How many worked.
	 * @param failed How many did not.
	 */
	partly: ( done: number, failed: number ) => string;
}

/**
 * Reports a batch of changes, in the tone it deserves.
 *
 * A tick beside "0 changed. 12 could not be." is a lie told cheerfully:
 * nothing at all got through, and that is a failure whatever the counts
 * say. Some through is a warning, and either way the first reason the
 * server gave is passed on, because a number with no "why" teaches
 * nobody anything.
 *
 * @param outcome What the server did.
 */
export function reportOutcome( outcome: Outcome ): void {
	const failed = outcome.reasons.length;
	if ( ! failed ) {
		toast.success( outcome.said );
		return;
	}
	const why = outcome.reasons.find( ( one ) => !! one ) ?? '';
	const line = [ outcome.partly( outcome.done, failed ), why ]
		.filter( ( part ) => !! part )
		.join( ' ' );
	if ( ! outcome.done ) {
		toast.error( line );
		return;
	}
	toast.warning( line );
}

const TONE_ICON: Record< ToastTone, ReactNode > = {
	success: (
		<CheckCircle2 size={ 18 } className="text-success" aria-hidden="true" />
	),
	error: <XCircle size={ 18 } className="text-danger" aria-hidden="true" />,
	info: <Info size={ 18 } className="text-info" aria-hidden="true" />,
	warning: (
		<AlertTriangle
			size={ 18 }
			className="text-warning"
			aria-hidden="true"
		/>
	),
};

function ToastView( { item }: { item: ToastItem } ) {
	const dismiss = useToasts( ( s ) => s.dismiss );
	useEffect( () => {
		const timer = window.setTimeout(
			() => dismiss( item.id ),
			item.timeout
		);
		return () => window.clearTimeout( timer );
	}, [ item.id, item.timeout, dismiss ] );
	return (
		<div
			role={ item.tone === 'error' ? 'alert' : 'status' }
			className="pointer-events-auto flex w-full items-start gap-3 rounded-lg border border-line bg-raised p-3 text-sm text-fg shadow-lg"
		>
			{ TONE_ICON[ item.tone ] }
			<p className="min-w-0 flex-1 pt-px">{ item.message }</p>
			{ item.action && (
				<button
					type="button"
					className="rounded px-1.5 py-0.5 font-medium text-accent-fg hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
					onClick={ () => {
						item.action?.onAction();
						dismiss( item.id );
					} }
				>
					{ item.action.label }
				</button>
			) }
			<button
				type="button"
				aria-label={ __( 'Dismiss', 'cliezoo' ) }
				className="rounded p-0.5 text-muted hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus"
				onClick={ () => dismiss( item.id ) }
			>
				<X size={ 16 } aria-hidden="true" />
			</button>
		</div>
	);
}

/**
 * The toast region (a live region; render once in the shell).
 */
export function Toaster() {
	const toasts = useToasts( ( s ) => s.toasts );
	return (
		<section
			aria-label={ __( 'Notifications', 'cliezoo' ) }
			aria-live="polite"
			className="pointer-events-none fixed inset-x-3 bottom-20 z-[60] flex flex-col items-center gap-2 sm:inset-x-auto sm:bottom-4 sm:end-4 sm:w-96 sm:items-end"
		>
			{ toasts.map( ( item ) => (
				<ToastView key={ item.id } item={ item } />
			) ) }
		</section>
	);
}

/* Status ----------------------------------------------------------------- */

export function Spinner( {
	size = 'md',
	label,
}: {
	size?: 'sm' | 'md' | 'lg';
	label?: string;
} ) {
	const px = { sm: 14, md: 18, lg: 28 }[ size ];
	return (
		<svg
			width={ px }
			height={ px }
			viewBox="0 0 24 24"
			className="animate-spin motion-reduce:animate-none"
			role={ label ? 'status' : undefined }
			aria-label={ label }
			aria-hidden={ label ? undefined : true }
		>
			<circle
				cx="12"
				cy="12"
				r="10"
				stroke="currentColor"
				strokeOpacity="0.25"
				strokeWidth="3"
				fill="none"
			/>
			<path
				d="M22 12a10 10 0 0 0-10-10"
				stroke="currentColor"
				strokeWidth="3"
				strokeLinecap="round"
				fill="none"
			/>
		</svg>
	);
}

export function Skeleton( { className }: { className?: string } ) {
	return (
		<div
			aria-hidden="true"
			className={ cx(
				'animate-pulse rounded-md bg-sunken motion-reduce:animate-none',
				className
			) }
		/>
	);
}

export function EmptyState( {
	icon,
	title,
	children,
	actions,
}: {
	icon?: ReactNode;
	title: ReactNode;
	children?: ReactNode;
	actions?: ReactNode;
} ) {
	return (
		<div className="mx-auto flex max-w-md flex-col items-center px-4 py-16 text-center">
			{ icon && (
				<div className="mb-4 flex size-12 items-center justify-center rounded-full bg-accent-subtle text-accent-fg">
					{ icon }
				</div>
			) }
			<h2 className="text-base font-semibold text-fg">{ title }</h2>
			{ children && (
				<div className="mt-1.5 text-sm text-muted">{ children }</div>
			) }
			{ actions && (
				<div className="mt-5 flex flex-wrap justify-center gap-2">
					{ actions }
				</div>
			) }
		</div>
	);
}

/**
 * What a screen shows when the thing it asked for did not arrive.
 *
 * Not an empty state: "nothing here yet" and "we could not find out" are
 * different sentences, and only one of them has a way out of it. Anything
 * that can fail to load says which it is and offers the way out.
 *
 * @param root0
 * @param root0.error   The failure, for its message.
 * @param root0.onRetry Asks again.
 * @param root0.title   Overrides the sentence, e.g. for a named list.
 */
export function ErrorState( {
	error,
	onRetry,
	title,
}: {
	error?: unknown;
	onRetry?: () => void;
	title?: ReactNode;
} ) {
	const message =
		error && typeof error === 'object' && 'message' in error
			? String( ( error as { message: unknown } ).message )
			: '';
	return (
		<div
			role="alert"
			className="flex flex-col items-start gap-3 rounded-lg border border-danger/30 bg-danger-subtle p-4 text-sm sm:flex-row sm:items-center"
		>
			<AlertTriangle
				size={ 18 }
				className="shrink-0 text-danger"
				aria-hidden="true"
			/>
			<div className="min-w-0 flex-1">
				<p className="font-medium text-fg">
					{ title ?? __( 'This could not be loaded.', 'cliezoo' ) }
				</p>
				{ '' !== message && (
					<p className="mt-0.5 text-muted">{ message }</p>
				) }
			</div>
			{ onRetry && (
				<button
					type="button"
					onClick={ onRetry }
					className="shrink-0 rounded-md border border-line bg-raised px-3 py-1.5 font-medium text-fg hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
				>
					{ __( 'Try again', 'cliezoo' ) }
				</button>
			) }
		</div>
	);
}

const ALERT_TONES: Record< ToastTone, string > = {
	success: 'border-success/30 bg-success-subtle',
	error: 'border-danger/30 bg-danger-subtle',
	info: 'border-info/30 bg-info-subtle',
	warning: 'border-warning/30 bg-warning-subtle',
};

export function Alert( {
	tone = 'info',
	title,
	children,
	actions,
	role,
}: {
	tone?: ToastTone;
	title?: ReactNode;
	children?: ReactNode;
	actions?: ReactNode;
	/**
	 * How it is announced. An error says so on its own; anything that
	 * appears as the answer to something the person just did has to ask
	 * for `status`, or the result is on screen and nowhere else.
	 */
	role?: 'alert' | 'status';
} ) {
	return (
		<div
			role={ role ?? ( tone === 'error' ? 'alert' : undefined ) }
			className={ cx(
				'flex gap-3 rounded-lg border p-3 text-sm text-fg',
				ALERT_TONES[ tone ]
			) }
		>
			{ TONE_ICON[ tone ] }
			<div className="min-w-0 flex-1">
				{ title && <p className="font-medium">{ title }</p> }
				{ children && (
					<div className={ cx( title && 'mt-0.5', 'text-muted' ) }>
						{ children }
					</div>
				) }
				{ actions && (
					<div className="mt-2 flex gap-2">{ actions }</div>
				) }
			</div>
		</div>
	);
}

/* Display ---------------------------------------------------------------- */

export type BadgeTone =
	'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';

const BADGE_TONES: Record< BadgeTone, string > = {
	neutral: 'bg-sunken text-muted',
	accent: 'bg-accent-subtle text-accent-fg',
	success: 'bg-success-subtle text-success',
	warning: 'bg-warning-subtle text-warning',
	danger: 'bg-danger-subtle text-danger',
	info: 'bg-info-subtle text-info',
};

export function Badge( {
	tone = 'neutral',
	children,
	className,
}: {
	tone?: BadgeTone;
	children: ReactNode;
	className?: string;
} ) {
	return (
		<span
			className={ cx(
				'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
				BADGE_TONES[ tone ],
				className
			) }
		>
			{ children }
		</span>
	);
}

/**
 * Initials avatar with an optional image.
 * @param root0
 * @param root0.name
 * @param root0.src
 * @param root0.size
 * @param root0.color
 */
export function Avatar( {
	name,
	src,
	size = 'md',
	color,
}: {
	name: string;
	src?: string;
	size?: 'xs' | 'sm' | 'md' | 'lg';
	color?: string;
} ) {
	const px = {
		xs: 'size-5 text-[0.6rem]',
		sm: 'size-6 text-[0.65rem]',
		md: 'size-8 text-xs',
		lg: 'size-12 text-base',
	}[ size ];
	const initials = name
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( part ) => part[ 0 ]?.toUpperCase() )
		.join( '' );
	return (
		<span
			className={ cx(
				'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent-subtle font-semibold text-accent-fg',
				px
			) }
			style={
				color && ! src
					? { backgroundColor: color, color: '#fff' }
					: undefined
			}
			aria-hidden="true"
		>
			{ src ? (
				<img
					src={ src }
					alt=""
					className="size-full object-cover"
					loading="lazy"
				/>
			) : (
				initials
			) }
		</span>
	);
}

export function Kbd( { children }: { children: ReactNode } ) {
	return (
		<kbd className="inline-flex h-5 min-w-5 items-center justify-center rounded border border-line-strong bg-raised px-1 font-sans text-[0.7rem] text-muted">
			{ children }
		</kbd>
	);
}
