/**
 * What went wrong with an AI call, in the Blueprint's vocabulary (§13.8),
 * so each failure can offer its own way forward instead of one "something
 * went wrong" for all of them.
 */
import type { ApiError } from '@cliezoo/data';

export type AiCode =
	| 'NO_PROVIDER'
	| 'AUTH'
	| 'PAYMENT'
	| 'RATE_LIMIT'
	| 'SERVER'
	| 'MODEL_NOT_FOUND'
	| 'BAD_REQUEST'
	| 'SAFETY'
	| 'BAD_ANSWER'
	| 'BUDGET'
	| 'TRUNCATED'
	| 'ABORTED'
	| 'UNKNOWN';

export interface AiFailure {
	code: AiCode;
	/** What the server said, already safe to show. */
	message: string;
	/** Seconds to wait before it is worth trying again (RATE_LIMIT). */
	retryAfter: number;
}

/**
 * The longest wait worth counting down on screen and then trying again by
 * itself; anything longer is an hourly limit, and is only explained.
 */
export const AUTO_RETRY_LIMIT = 60;

/**
 * Reads a failed call.
 *
 * @param error   What the request threw.
 * @param aborted Whether the person cancelled it.
 */
export function aiFailure( error: unknown, aborted = false ): AiFailure {
	if ( aborted ) {
		return { code: 'ABORTED', message: '', retryAfter: 0 };
	}
	const failure = error as Partial< ApiError > | null;
	const raw = String( failure?.code ?? '' );
	let code: AiCode = 'UNKNOWN';
	if ( raw === 'cliezoo_rate_limited' ) {
		// The workspace's own limit, or a provider's pause it remembered.
		code = 'RATE_LIMIT';
	} else if ( raw.startsWith( 'cliezoo_ai_' ) ) {
		const said = raw.slice( 'cliezoo_ai_'.length ).toUpperCase();
		const known: AiCode[] = [
			'NO_PROVIDER',
			'AUTH',
			'PAYMENT',
			'RATE_LIMIT',
			'SERVER',
			'MODEL_NOT_FOUND',
			'BAD_REQUEST',
			'SAFETY',
			'BAD_ANSWER',
			'BUDGET',
		];
		code = known.includes( said as AiCode ) ? ( said as AiCode ) : code;
	}
	return {
		code,
		message: String( failure?.message ?? '' ),
		retryAfter: Math.max( 0, Number( failure?.retryAfter ?? 0 ) || 0 ),
	};
}

/**
 * Whether it is worth waiting and trying again without being asked.
 *
 * @param failure The failure.
 */
export function retriesItself( failure: AiFailure ): boolean {
	return (
		failure.code === 'RATE_LIMIT' &&
		failure.retryAfter > 0 &&
		failure.retryAfter <= AUTO_RETRY_LIMIT
	);
}

/**
 * Whether the fix is on Settings → AI → Providers, for an administrator.
 *
 * @param failure The failure.
 */
export function fixedInSettings( failure: AiFailure ): boolean {
	return [
		'NO_PROVIDER',
		'AUTH',
		'MODEL_NOT_FOUND',
		'PAYMENT',
		'BUDGET',
	].includes( failure.code );
}
