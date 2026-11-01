/**
 * Overlays, loaded when something opens one.
 *
 * A dialog, a menu and a popover are the heaviest things React Aria has —
 * focus traps, positioning, scroll locking — and none of them is on screen
 * when the app first paints, so they are their own chunk, preloaded as soon
 * as the browser is idle (Blueprint §18.1, ADR 0003).
 *
 * Everything here has the same API as the real thing: these wrappers render
 * it as soon as the chunk has landed, and nothing until then — which is
 * what an unopened overlay looks like anyway. A trigger renders its
 * children either way, so a button never disappears.
 */
import { overlayPart, passthrough } from './lazy';

export const Popover = overlayPart( 'Popover' );
export const Menu = overlayPart( 'Menu' );
export const MenuItem = overlayPart( 'MenuItem' );
export const MenuSection = overlayPart( 'MenuSection' );
export const MenuSeparator = overlayPart( 'MenuSeparator' );
export const Dialog = overlayPart( 'Dialog' );
export const ConfirmDialog = overlayPart( 'ConfirmDialog' );
export const Sheet = overlayPart( 'Sheet' );
export const DialogTrigger = overlayPart( 'DialogTrigger', passthrough );
export const MenuTrigger = overlayPart( 'MenuTrigger', passthrough );

export type { DialogProps, SheetProps } from './overlays';
