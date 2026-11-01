/**
 * Rich tooltip (part of the lazy chunk). Icon buttons use the light CSS
 * tooltip instead (see .cz-tip in theme.css).
 */
import type { ReactNode } from 'react';
import {
	OverlayArrow,
	Tooltip as AriaTooltip,
	type TooltipProps,
} from 'react-aria-components';

/**
 * Tooltip (hover/focus; never the only way to get information).
 * @param root0
 * @param root0.children
 */
export function Tooltip( {
	children,
	...props
}: Omit< TooltipProps, 'children' > & { children: ReactNode } ) {
	return (
		<AriaTooltip
			offset={ 6 }
			{ ...props }
			className="rounded-md bg-fg px-2 py-1 text-xs text-bg shadow-md"
		>
			<OverlayArrow>
				<svg
					width={ 8 }
					height={ 8 }
					viewBox="0 0 8 8"
					className="fill-fg group-data-[placement=bottom]:rotate-180"
					aria-hidden="true"
				>
					<path d="M0 0 L4 4 L8 0" />
				</svg>
			</OverlayArrow>
			{ children }
		</AriaTooltip>
	);
}
