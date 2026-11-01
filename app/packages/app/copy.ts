/**
 * Copying something to the clipboard, and saying what actually happened.
 *
 * `navigator.clipboard` does not exist at all on a site served over plain
 * http — an ordinary staging box, and a good many production ones — so
 * `navigator.clipboard?.writeText( … )` is a no-op that looks like a
 * success. Somebody then pastes whatever was on their clipboard before
 * into a message to their client. For a share link, which is shown once
 * and never stored anywhere it can be read back, that loses the link for
 * good.
 */
import { __ } from '@wordpress/i18n';
import { toast } from '@cliezoo/ui';

/**
 * Puts text on the clipboard.
 *
 * @param text What to copy.
 * @param said What to say when it worked.
 * @return      Whether it was copied.
 */
export async function copyText(
	text: string,
	said: string = __( 'Link copied.', 'cliezoo' )
): Promise< boolean > {
	try {
		await navigator.clipboard.writeText( text );
		toast.success( said );
		return true;
	} catch {
		toast.error(
			__(
				'This browser would not copy it. Select it and copy it yourself — a site served over plain http cannot use the clipboard.',
				'cliezoo'
			)
		);
		return false;
	}
}
