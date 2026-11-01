/**
 * The question a dialog asks before it throws away what somebody typed.
 *
 * `Dialog` closes on Escape and on a click beside it, which is right for a
 * dialog that only shows something and wrong for one holding a half-filled
 * form: one stray key takes a CSV column mapping, or a client's intake
 * form, and offers nothing back. Where the typing is worth keeping the
 * dismiss is held and the question is asked instead, so that only a
 * deliberate "Discard" closes it.
 *
 * A form that can keep its draft should keep it — `draft.ts` and
 * `RecordForm` do — and this is for the ones that cannot.
 */
import { useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { ConfirmDialog } from '@cliezoo/ui';

/**
 * Holds a dialog's dismiss while there is unsaved typing in it.
 *
 * @param dirty            Whether anything has been typed that closing would lose.
 * @param close            Closes the dialog for real.
 * @param copy             What the question says, where the default does not fit.
 * @param copy.title
 * @param copy.description
 * @return `dismiss` for the dialog's `onOpenChange`, and the `guard` to
 *         render beside it.
 */
export function useUnsavedGuard(
	dirty: boolean,
	close: () => void,
	copy: { title?: string; description?: string } = {}
): { dismiss: ( open: boolean ) => void; guard: ReactNode } {
	const [ asking, setAsking ] = useState( false );
	return {
		dismiss: ( open: boolean ) => {
			if ( open ) {
				return;
			}
			if ( dirty ) {
				setAsking( true );
				return;
			}
			close();
		},
		guard: (
			<ConfirmDialog
				isOpen={ asking }
				onOpenChange={ setAsking }
				title={
					copy.title ?? __( 'Discard what you typed?', 'cliezoo' )
				}
				description={
					copy.description ??
					__(
						'None of it has been saved. Closing this now throws it away, and there is no way back to it.',
						'cliezoo'
					)
				}
				confirmLabel={ __( 'Discard it', 'cliezoo' ) }
				onConfirm={ () => {
					setAsking( false );
					close();
				} }
			/>
		),
	};
}
