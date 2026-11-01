/**
 * A value that waits for somebody to stop typing.
 *
 * A search box wired straight into a query key asks the server once per
 * character, and every one of those asks is a fresh key: the list empties
 * behind a skeleton and "Nothing matches." flashes between keystrokes.
 */
import { useEffect, useState } from 'react';

/** How long a pause counts as having stopped typing. */
const SETTLE_MS = 250;

/**
 * The value, once it has held still.
 *
 * @param value What is being typed.
 * @param wait  How long to wait, in milliseconds.
 */
export function useDebounced< T >( value: T, wait: number = SETTLE_MS ): T {
	const [ settled, setSettled ] = useState( value );
	useEffect( () => {
		const timer = window.setTimeout( () => setSettled( value ), wait );
		return () => window.clearTimeout( timer );
	}, [ value, wait ] );
	return settled;
}
