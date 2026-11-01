/**
 * Small helpers shared by the components.
 */
import { composeRenderProps } from 'react-aria-components';

export type ClassValue = string | false | null | undefined | 0;

/**
 * Joins class names, skipping falsy values.
 *
 * @param values Class names.
 */
export function cx( ...values: ClassValue[] ): string {
	return values.filter( Boolean ).join( ' ' );
}

/**
 * Merges a component's base classes with a React Aria className prop
 * (string or render function).
 *
 * @param className Consumer className.
 * @param base      Base classes (may depend on render state).
 */
export function withClass< T >(
	className: string | ( ( state: T ) => string ) | undefined,
	base: string | ( ( state: T ) => string )
) {
	return composeRenderProps( className, ( value, state: T ) =>
		cx( typeof base === 'function' ? base( state ) : base, value )
	);
}

/**
 * Focus ring classes (visible only for keyboard focus).
 */
export const focusRing =
	'outline-none data-[focus-visible]:outline-2 data-[focus-visible]:outline-offset-2 data-[focus-visible]:outline-focus';

/**
 * Text input look (shared by fields and custom inputs).
 */
export const inputClass = cx(
	'w-full rounded-md border border-line-strong bg-bg px-3 py-2 text-sm text-fg shadow-sm placeholder:text-subtle',
	'outline-none data-[focused]:border-focus data-[focused]:ring-2 data-[focused]:ring-focus/25',
	'data-[invalid]:border-danger data-[disabled]:opacity-60'
);
