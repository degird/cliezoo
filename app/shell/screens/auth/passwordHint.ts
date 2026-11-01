/**
 * The password rule shown under new-password fields.
 */
import { _n, sprintf } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';

export function passwordHint(): string {
	const min = getBoot().security.passwordMin;
	return sprintf(
		/* translators: %d: minimum number of characters. */
		_n(
			'At least %d character. A longer passphrase is stronger.',
			'At least %d characters. A longer passphrase is stronger.',
			min,
			'cliezoo'
		),
		min
	);
}
