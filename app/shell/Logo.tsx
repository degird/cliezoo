/**
 * Workspace logo: the uploaded logo, or the Cliezoo mark (a white C with a
 * spark in its opening, on a rounded square in the accent colour). The
 * masters are in assets/logo at the root of the repository.
 */
import { getBoot } from '@cliezoo/data';
import { cx } from '@cliezoo/ui';

export function Mark( {
	size = 28,
	className,
}: {
	size?: number;
	className?: string;
} ) {
	return (
		<svg
			width={ size }
			height={ size }
			viewBox="0 0 32 32"
			aria-hidden="true"
			className={ cx( 'shrink-0', className ) }
		>
			<rect width="32" height="32" rx="7.4" fill="var(--cz-accent)" />
			<g transform="translate(16 16) scale(0.2304) translate(-49.25 -50)">
				<path
					d="M63.8 30.2A28 28 0 1 0 63.8 69.8"
					fill="none"
					stroke="#fff"
					strokeWidth="13"
					strokeLinecap="round"
				/>
				<path
					d="M76 37C78.6 45.06 80.94 47.4 89 50C80.94 52.6 78.6 54.94 76 63C73.4 54.94 71.06 52.6 63 50C71.06 47.4 73.4 45.06 76 37Z"
					fill="#fff"
				/>
			</g>
		</svg>
	);
}

export function Logo( { size = 28 }: { size?: number } ) {
	const { branding } = getBoot();
	// A workspace can give dark mode its own logo; the main one is used when
	// it has not.
	const dark =
		typeof document !== 'undefined' &&
		document.documentElement.getAttribute( 'data-theme' ) === 'dark';
	const src = ( dark && branding.logo_dark ) || branding.logo;
	if ( src ) {
		return (
			<img
				src={ src }
				alt={ branding.logoAlt }
				height={ size }
				className="max-h-7 w-auto max-w-32 object-contain"
			/>
		);
	}
	return <Mark size={ size } />;
}
