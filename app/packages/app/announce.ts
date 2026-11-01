/**
 * Words for somebody who is not looking at the screen.
 *
 * Small, and on its own, so the doc editor can say the same sentence the
 * composer does without dragging the composer into the editor's chunk.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * What a screen reader is told when a list of suggestions is open.
 *
 * The box stays a message box: making it a combobox would change the role
 * every reader announces for something that is a text area almost all of
 * the time, and a multi-line combobox is poorly supported besides. So the
 * list is pointed at with `aria-controls`, `aria-haspopup` and
 * `aria-activedescendant`, which say which row the arrows are on, and this
 * says the rest out loud — that a list opened, how long it is, and where
 * in it the arrows have got to. Without it, opening the list is silent.
 *
 * @param open  Whether the list is showing.
 * @param names The rows, in order.
 * @param pick  Which row the arrows are on.
 */
export function suggestionsLabel(
	open: boolean,
	names: string[],
	pick: number
): string {
	if ( ! open || ! names.length ) {
		return '';
	}
	const at = Math.min( Math.max( pick, 0 ), names.length - 1 );
	return sprintf(
		/* translators: 1: the highlighted suggestion, 2: its place in the list, 3: how many there are. */
		__( '%1$s, %2$d of %3$d', 'cliezoo' ),
		names[ at ],
		at + 1,
		names.length
	);
}
