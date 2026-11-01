/**
 * Error boundary for routes: a friendly message, retry, no stack traces.
 */
import { useEffect } from 'react';
import { useRouter, type ErrorComponentProps } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { Button, ErrorState, Link } from '@cliezoo/ui';

export function RouteError( { error, reset }: ErrorComponentProps ) {
	const router = useRouter();
	useEffect( () => {
		// Keep details in the console for support; never on screen.
		// eslint-disable-next-line no-console
		console.error( error );
	}, [ error ] );
	const chunkFailed =
		/Loading chunk|dynamically imported module|Failed to fetch/i.test(
			String( ( error as Error )?.message ?? '' )
		);
	return (
		<div className="mx-auto max-w-md space-y-3 px-4 py-12">
			<ErrorState
				title={
					chunkFailed
						? __( 'A new version is available', 'cliezoo' )
						: __( 'Something went wrong', 'cliezoo' )
				}
				// The sentence people read, not the exception: the exception
				// is in the console, where support can ask for it.
				error={ {
					message: chunkFailed
						? __(
								'The app was updated. Reload to continue.',
								'cliezoo'
							)
						: __(
								'Please try again. If it keeps happening, contact your workspace admin.',
								'cliezoo'
							),
				} }
				// A stale chunk is not retried, it is reloaded, and the
				// button beside it says so.
				onRetry={
					chunkFailed
						? undefined
						: () => {
								reset();
								router.invalidate();
							}
				}
			/>
			<div className="flex flex-wrap gap-2">
				{ chunkFailed && (
					<Button
						variant="primary"
						onPress={ () => window.location.reload() }
					>
						{ __( 'Reload', 'cliezoo' ) }
					</Button>
				) }
				{ /* Trying again re-renders the screen that just crashed, so
				     a screen that crashes every time needs a way off it that
				     is not the browser's Back button. */ }
				<Link href="/" variant="secondary">
					{ __( 'Go to Home', 'cliezoo' ) }
				</Link>
			</div>
		</div>
	);
}
