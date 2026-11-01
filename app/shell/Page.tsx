/**
 * Page scaffolding: header (title, meta, primary action, tabs) and content.
 */
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { cx } from '@cliezoo/ui';

export function PageHeader( {
	title,
	meta,
	actions,
	breadcrumb,
	children,
}: {
	title: ReactNode;
	meta?: ReactNode;
	actions?: ReactNode;
	breadcrumb?: ReactNode;
	children?: ReactNode;
} ) {
	return (
		<header className="border-b border-line bg-bg px-4 pt-4 sm:px-6 sm:pt-5">
			{ breadcrumb && (
				<nav
					aria-label={ __( 'Breadcrumb', 'cliezoo' ) }
					className="mb-1.5 text-xs text-muted"
				>
					{ breadcrumb }
				</nav>
			) }
			<div className="flex flex-wrap items-start gap-3 pb-4">
				{ /* A basis, so the title keeps room to be read: without one it
				     was squeezed to its first few letters by the buttons beside
				     it ("Cruml" for a board called Crumb Board), and the buttons
				     now wrap underneath instead. */ }
				<div className="min-w-0 flex-1 basis-64">
					<h1 className="truncate text-xl font-semibold tracking-tight text-fg">
						{ title }
					</h1>
					{ meta && (
						<div className="mt-1 text-sm text-muted">{ meta }</div>
					) }
				</div>
				{ actions && (
					<div className="flex min-w-0 max-w-full flex-wrap items-center gap-2">
						{ actions }
					</div>
				) }
			</div>
			{ children }
		</header>
	);
}

export function Page( {
	children,
	className,
	width = 'full',
}: {
	children: ReactNode;
	className?: string;
	width?: 'full' | 'narrow' | 'form';
} ) {
	return (
		<div
			className={ cx(
				'px-4 py-5 sm:px-6',
				width === 'narrow' && 'mx-auto max-w-4xl',
				width === 'form' && 'mx-auto max-w-2xl',
				className
			) }
		>
			{ children }
		</div>
	);
}
