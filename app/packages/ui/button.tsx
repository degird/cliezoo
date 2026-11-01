/**
 * Buttons and links.
 */
import { forwardRef, type ReactNode } from 'react';
import {
	Button as AriaButton,
	Link as AriaLink,
	type ButtonProps as AriaButtonProps,
	type LinkProps as AriaLinkProps,
} from 'react-aria-components';
import { Spinner } from './feedback';
import { cx, focusRing, withClass } from './utils';

export type ButtonVariant =
	'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

const VARIANTS: Record< ButtonVariant, string > = {
	primary:
		'bg-accent text-accent-contrast hover:bg-accent-hover data-[pressed]:bg-accent-hover shadow-sm',
	secondary:
		'bg-raised text-fg border border-line-strong hover:bg-sunken data-[pressed]:bg-sunken shadow-sm',
	ghost: 'text-fg hover:bg-sunken data-[pressed]:bg-sunken',
	danger: 'bg-danger text-white hover:opacity-90 data-[pressed]:opacity-90 dark:text-bg shadow-sm',
	link: 'text-accent-fg underline-offset-2 hover:underline px-0',
};

const SIZES: Record< ButtonSize, string > = {
	sm: 'h-7 px-2.5 text-xs gap-1.5',
	md: 'h-9 px-3.5 text-sm gap-2',
	lg: 'h-11 px-5 text-base gap-2',
};

export const buttonClass = (
	variant: ButtonVariant = 'secondary',
	size: ButtonSize = 'md'
) =>
	cx(
		'inline-flex select-none items-center justify-center whitespace-nowrap rounded-md font-medium transition-colors duration-150',
		'data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50',
		focusRing,
		VARIANTS[ variant ],
		variant !== 'link' && SIZES[ size ]
	);

export interface ButtonProps extends AriaButtonProps {
	variant?: ButtonVariant;
	size?: ButtonSize;
	icon?: ReactNode;
	iconEnd?: ReactNode;
	children?: ReactNode;
}

/**
 * A button. `isPending` shows a spinner and keeps the width stable.
 */
export const Button = forwardRef< HTMLButtonElement, ButtonProps >(
	function ButtonRender(
		{
			variant = 'secondary',
			size = 'md',
			icon,
			iconEnd,
			className,
			children,
			...props
		},
		ref
	) {
		return (
			<AriaButton
				ref={ ref }
				{ ...props }
				className={ withClass(
					className as string,
					buttonClass( variant, size )
				) }
			>
				{ ( { isPending } ) => (
					<>
						{ isPending ? <Spinner size="sm" /> : icon }
						{ children }
						{ iconEnd }
					</>
				) }
			</AriaButton>
		);
	}
);

export interface IconButtonProps extends Omit< AriaButtonProps, 'children' > {
	label: string;
	icon: ReactNode;
	variant?: ButtonVariant;
	size?: ButtonSize;
	tooltip?: boolean;
	shortcut?: string;
}

const ICON_SIZES: Record< ButtonSize, string > = {
	sm: 'size-7',
	md: 'size-9',
	lg: 'size-11',
};

/**
 * An icon-only button with an accessible name and a light CSS tooltip
 * (shown on hover and keyboard focus; the name is always in aria-label).
 */
export const IconButton = forwardRef< HTMLButtonElement, IconButtonProps >(
	function IconButtonRender(
		{
			label,
			icon,
			variant = 'ghost',
			size = 'md',
			tooltip = true,
			shortcut,
			className,
			...props
		},
		ref
	) {
		return (
			<AriaButton
				ref={ ref }
				aria-label={ label }
				aria-keyshortcuts={ shortcut }
				data-tooltip={
					tooltip
						? label + ( shortcut ? ` (${ shortcut })` : '' )
						: undefined
				}
				{ ...props }
				className={ withClass(
					className as string,
					cx(
						buttonClass( variant, size ),
						'!px-0',
						ICON_SIZES[ size ],
						tooltip && 'cz-tip'
					)
				) }
			>
				{ icon }
			</AriaButton>
		);
	}
);

export interface LinkProps extends AriaLinkProps {
	variant?: 'inline' | 'plain' | ButtonVariant;
	size?: ButtonSize;
}

/**
 * A link (client-side navigation through the router provider).
 * @param root0
 * @param root0.variant
 * @param root0.size
 * @param root0.className
 */
export function Link( {
	variant = 'inline',
	size = 'md',
	className,
	...props
}: LinkProps ) {
	let base: string;
	if ( variant === 'inline' ) {
		base = cx(
			'text-accent-fg underline-offset-2 hover:underline rounded-sm',
			focusRing
		);
	} else if ( variant === 'plain' ) {
		base = cx( 'rounded-sm', focusRing );
	} else {
		base = buttonClass( variant, size );
	}
	return (
		<AriaLink
			{ ...props }
			className={ withClass( className as string, base ) }
		/>
	);
}
