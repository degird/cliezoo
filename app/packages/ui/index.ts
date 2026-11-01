/**
 * The cliezoo ui package: the Cliezoo design system (React Aria + Tailwind tokens).
 * Exposed as window.cliezoo.ui (handle cliezoo-ui). Also emits the app CSS.
 */
import '../../styles/app.css';

export * from './utils';
export * from './button';
export * from './overlay';
export type { DragItem, DragListProps } from './board';
export type {
	TextFieldProps,
	SearchFieldProps,
	SelectOption,
	SelectProps,
} from './form';
export * from './lazy';
export * from './feedback';
export {
	RouterProvider,
	Heading,
	VisuallyHidden,
	I18nProvider,
} from 'react-aria-components';
export type { Key, Selection, SortDescriptor } from 'react-aria-components';

/*
 * The unstyled React Aria primitives app code and add-ons build on come
 * from ./lazy, which loads them with the rest of the overlay chunk. The
 * library still ships exactly once; it simply does not ship in the first
 * load. App code never imports react-aria-components directly.
 */
