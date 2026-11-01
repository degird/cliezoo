/**
 * Overlays: tooltip, popover, menu, dialog, side sheet, confirm.
 */
import { useId, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';
import {
	Dialog as AriaDialog,
	DialogTrigger,
	Header,
	Heading,
	Menu as AriaMenu,
	MenuItem as AriaMenuItem,
	MenuSection as AriaMenuSection,
	MenuTrigger,
	Modal,
	ModalOverlay,
	Popover as AriaPopover,
	Separator,
	Button as AriaButton,
	type MenuItemProps,
	type MenuProps,
	type PopoverProps,
} from 'react-aria-components';
import { cx, focusRing, withClass } from './utils';

export { DialogTrigger, MenuTrigger };

/*
 * The unstyled primitives the app builds its own overlays from travel with
 * this chunk, so React Aria's overlay machinery is loaded exactly once and
 * never at start-up.
 */
export {
	Header,
	Modal,
	ModalOverlay,
	Dialog as AriaDialog,
	Menu as AriaMenu,
	MenuItem as AriaMenuItem,
	MenuSection as AriaMenuSection,
	Button as AriaButton,
} from 'react-aria-components';

/**
 * Popover surface.
 * @param root0
 * @param root0.className
 */
export function Popover( { className, ...props }: PopoverProps ) {
	return (
		<AriaPopover
			offset={ 6 }
			{ ...props }
			className={ withClass(
				className as string,
				'min-w-44 max-w-[min(24rem,calc(100vw-2rem))] overflow-auto rounded-lg border border-line bg-raised text-fg shadow-lg outline-none'
			) }
		/>
	);
}

/**
 * Dropdown menu.
 * @param root0
 * @param root0.placement
 */
export function Menu< T extends object >( {
	placement,
	...props
}: MenuProps< T > & { placement?: PopoverProps[ 'placement' ] } ) {
	return (
		<Popover placement={ placement ?? 'bottom end' }>
			<AriaMenu
				{ ...props }
				className="max-h-[70vh] overflow-auto p-1 outline-none"
			/>
		</Popover>
	);
}

/**
 * Menu item with optional icon, description and shortcut.
 * @param root0
 * @param root0.icon
 * @param root0.shortcut
 * @param root0.description
 * @param root0.danger
 * @param root0.children
 * @param root0.className
 */
export function MenuItem( {
	icon,
	shortcut,
	description,
	danger,
	children,
	className,
	...props
}: MenuItemProps & {
	icon?: ReactNode;
	shortcut?: string;
	description?: string;
	danger?: boolean;
	children: ReactNode;
} ) {
	return (
		<AriaMenuItem
			{ ...props }
			textValue={
				props.textValue ??
				( typeof children === 'string' ? children : undefined )
			}
			className={ withClass(
				className as string,
				cx(
					'flex cursor-default items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm outline-none',
					'data-[focused]:bg-sunken data-[disabled]:opacity-50',
					danger ? 'text-danger' : 'text-fg'
				)
			) }
		>
			{ icon && (
				<span className="flex size-4 shrink-0 items-center justify-center text-muted">
					{ icon }
				</span>
			) }
			<span className="flex min-w-0 flex-1 flex-col">
				<span className="truncate">{ children }</span>
				{ description && (
					<span className="truncate text-xs text-muted">
						{ description }
					</span>
				) }
			</span>
			{ shortcut && (
				<kbd className="font-sans text-xs text-subtle">
					{ shortcut }
				</kbd>
			) }
		</AriaMenuItem>
	);
}

/**
 * Menu section with an optional heading.
 * @param root0
 * @param root0.title
 * @param root0.children
 * @param root0.id
 */
export function MenuSection< T extends object >( {
	title,
	children,
	id,
}: {
	title?: string;
	children: ReactNode | ( ( item: T ) => ReactNode );
	/**
	 * Identifies the section in the menu's collection. It is namespaced
	 * before use, because a section and an item sharing a key would
	 * displace each other.
	 */
	id?: string;
} ) {
	return (
		<AriaMenuSection
			id={ 'section:' + ( id ?? title ?? '' ) }
			className="[&:not(:first-child)]:mt-1 [&:not(:first-child)]:border-t [&:not(:first-child)]:border-line [&:not(:first-child)]:pt-1"
		>
			{ /* Header, not Heading: inside a collection, anything else
			     confuses the builder and the section loses its items. */ }
			{ title && (
				<Header className="px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-subtle">
					{ title }
				</Header>
			) }
			{ children as ReactNode }
		</AriaMenuSection>
	);
}

export function MenuSeparator() {
	return <Separator className="my-1 border-t border-line" />;
}

export interface DialogProps {
	title: ReactNode;
	description?: ReactNode;
	children?: ReactNode | ( ( close: () => void ) => ReactNode );
	footer?: ( close: () => void ) => ReactNode;
	size?: 'sm' | 'md' | 'lg' | 'xl';
	isOpen?: boolean;
	onOpenChange?: ( open: boolean ) => void;
	isDismissable?: boolean;
	role?: 'dialog' | 'alertdialog';
}

const DIALOG_SIZES = {
	sm: 'max-w-sm',
	md: 'max-w-lg',
	lg: 'max-w-2xl',
	xl: 'max-w-4xl',
};

/**
 * Modal dialog. Focus is trapped and returned to the trigger on close; Escape closes.
 * @param root0
 * @param root0.title
 * @param root0.description
 * @param root0.children
 * @param root0.footer
 * @param root0.size
 * @param root0.isOpen
 * @param root0.onOpenChange
 * @param root0.isDismissable
 * @param root0.role
 */
export function Dialog( {
	title,
	description,
	children,
	footer,
	size = 'md',
	isOpen,
	onOpenChange,
	isDismissable = true,
	role = 'dialog',
}: DialogProps ) {
	const describedBy = useId();
	return (
		<ModalOverlay
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			isDismissable={ isDismissable && role !== 'alertdialog' }
			/*
			 * Tall as the screen, not as the page.
			 *
			 * `inset-0` on a fixed element resolves against the layout
			 * viewport, which on a phone is taller than what anybody can
			 * see whenever the browser's own chrome is showing — by a
			 * couple of hundred pixels in the emulator, by the height of
			 * the URL bar on a real one. A sheet held against the bottom
			 * of that is held against the bottom of something off the
			 * screen, and the Save at its foot goes with it. `svh` is the
			 * viewport with the chrome showing, which is the part that
			 * is always there, so the sheet ends where the screen does.
			 *
			 * The safe-area inset is nothing on a desktop and the height
			 * of the gesture bar on a phone that has one.
			 */
			className="fixed inset-x-0 top-0 z-50 flex h-[100svh] items-end justify-center bg-overlay pb-[env(safe-area-inset-bottom)] sm:items-center sm:p-4"
		>
			{ /*
			 * The cap belongs on the box, not only on what is inside it.
			 * With the height on the dialog alone the modal could grow
			 * taller than the screen, and on a phone — where this is a
			 * sheet against the bottom — the scrolling body came to sit
			 * over the footer, so Save and Cancel could not be pressed
			 * at all.
			 */ }
			<Modal
				className={ cx(
					'max-h-[90dvh] w-full overflow-hidden rounded-t-xl bg-raised text-fg shadow-lg sm:rounded-xl',
					DIALOG_SIZES[ size ]
				) }
			>
				<AriaDialog
					role={ role }
					aria-describedby={ description ? describedBy : undefined }
					className="flex h-full max-h-[90dvh] flex-col outline-none"
				>
					{ ( { close } ) => (
						<>
							<header className="flex items-start gap-3 border-b border-line px-5 py-4">
								<div className="min-w-0 flex-1">
									<Heading
										slot="title"
										className="text-base font-semibold"
									>
										{ title }
									</Heading>
									{ /* Named by the dialog itself, so it is
									     said as well as shown: a line only
									     sighted readers get is a line half
									     the room is missing. */ }
									{ description && (
										<p
											id={ describedBy }
											className="mt-1 text-sm text-muted"
										>
											{ description }
										</p>
									) }
								</div>
								{ /* A dialog that cannot be dismissed does not
								     offer a close button that would do
								     nothing. This is `isDismissable` and
								     not the value handed to the overlay:
								     an alertdialog only turns off going
								     away by clicking outside it, so that a
								     choice is made rather than stumbled
								     past, and it keeps its X. */ }
								{ isDismissable && (
									<AriaButton
										aria-label={ __( 'Close', 'cliezoo' ) }
										onPress={ close }
										className={ cx(
											'rounded-md p-1 text-muted hover:bg-sunken hover:text-fg',
											focusRing
										) }
									>
										<X size={ 18 } aria-hidden="true" />
									</AriaButton>
								) }
							</header>
							<div className="min-h-0 flex-1 overflow-auto px-5 py-4">
								{ typeof children === 'function'
									? children( close )
									: children }
							</div>
							{ footer && (
								/* The buttons wrap rather than run past the
								   edge. A row that cannot wrap overflows off
								   the start side, which nothing scrolls: on a
								   phone the viewer's footer put Close 144px to
								   the left of the screen, where it could not be
								   reached at all. */
								<footer className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">
									{ footer( close ) }
								</footer>
							) }
						</>
					) }
				</AriaDialog>
			</Modal>
		</ModalOverlay>
	);
}

/**
 * "Are you sure?", for the things that cannot be undone.
 *
 * Most of the app offers Undo instead of asking — but some rows are gone
 * for good when they go, and for those a question beforehand is the only
 * honest option.
 *
 * @param root0
 * @param root0.isOpen
 * @param root0.onOpenChange
 * @param root0.title
 * @param root0.description
 * @param root0.confirmLabel
 * @param root0.onConfirm
 * @param root0.tone
 * @param root0.children
 */
export function ConfirmDialog( {
	isOpen,
	onOpenChange,
	title,
	description,
	confirmLabel,
	onConfirm,
	tone = 'danger',
	children,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: ReactNode;
	description?: ReactNode;
	confirmLabel?: string;
	onConfirm: () => void;
	tone?: 'danger' | 'primary';
	children?: ReactNode;
} ) {
	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			role="alertdialog"
			size="sm"
			title={ title }
			description={ description }
			footer={ ( close ) => (
				<>
					<AriaButton
						onPress={ close }
						className={ cx(
							'rounded-md px-3.5 py-1.5 text-sm font-medium text-fg hover:bg-sunken',
							focusRing
						) }
					>
						{ __( 'Cancel', 'cliezoo' ) }
					</AriaButton>
					<AriaButton
						onPress={ () => {
							onConfirm();
							close();
						} }
						className={ cx(
							'rounded-md px-3.5 py-1.5 text-sm font-medium shadow-sm',
							'danger' === tone
								? 'bg-danger text-white dark:text-bg'
								: 'bg-accent text-accent-contrast',
							focusRing
						) }
					>
						{ confirmLabel ?? __( 'Delete', 'cliezoo' ) }
					</AriaButton>
				</>
			) }
		>
			{ children }
		</Dialog>
	);
}

export interface SheetProps {
	title: ReactNode;
	children: ReactNode | ( ( close: () => void ) => ReactNode );
	isOpen?: boolean;
	onOpenChange?: ( open: boolean ) => void;
	side?: 'end' | 'start';
	width?: 'md' | 'lg' | 'xl';
	actions?: ReactNode;
}

const SHEET_WIDTH = {
	md: 'sm:max-w-md',
	lg: 'sm:max-w-xl',
	xl: 'sm:max-w-3xl',
};

/**
 * Side panel (detail drawer). Full screen on phones.
 * @param root0
 * @param root0.title
 * @param root0.children
 * @param root0.isOpen
 * @param root0.onOpenChange
 * @param root0.side
 * @param root0.width
 * @param root0.actions
 */
export function Sheet( {
	title,
	children,
	isOpen,
	onOpenChange,
	side = 'end',
	width = 'lg',
	actions,
}: SheetProps ) {
	return (
		<ModalOverlay
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			isDismissable
			// Tall as the screen, for the reason the dialog above gives.
			className="fixed inset-x-0 top-0 z-50 h-[100svh] bg-overlay"
		>
			<Modal
				className={ cx(
					'fixed top-0 flex h-[100svh] w-full flex-col bg-raised text-fg shadow-lg',
					side === 'end' ? 'end-0' : 'start-0',
					SHEET_WIDTH[ width ]
				) }
			>
				<AriaDialog className="flex h-full flex-col outline-none">
					{ ( { close } ) => (
						<>
							<header className="flex h-14 shrink-0 items-center gap-2 border-b border-line px-4">
								<Heading
									slot="title"
									className="min-w-0 flex-1 truncate text-base font-semibold"
								>
									{ title }
								</Heading>
								{ actions }
								<AriaButton
									aria-label={ __( 'Close', 'cliezoo' ) }
									onPress={ close }
									className={ cx(
										'rounded-md p-1.5 text-muted hover:bg-sunken hover:text-fg',
										focusRing
									) }
								>
									<X size={ 18 } aria-hidden="true" />
								</AriaButton>
							</header>
							<div className="min-h-0 flex-1 overflow-auto">
								{ typeof children === 'function'
									? children( close )
									: children }
							</div>
						</>
					) }
				</AriaDialog>
			</Modal>
		</ModalOverlay>
	);
}
