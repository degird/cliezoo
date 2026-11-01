/**
 * A column of cards you can drag — with a keyboard, too.
 *
 * A kanban board is the one place a mouse is assumed, and the assumption
 * quietly excludes people. Every card here has a drag handle: tab to it,
 * press Enter to pick the card up, arrow to where it should go, Enter to
 * drop it, Escape to change your mind. The card itself still opens on
 * Enter, because that is what a card does — which is exactly why picking
 * one up needed somewhere else to press.
 */
import {
	Button as AriaButton,
	GridList,
	GridListItem,
	useDragAndDrop,
} from 'react-aria-components';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { cx } from './utils';

/** What the drag carries: the card, and the column it left. */
const CARD = 'application/x-cliezoo-card';

export interface DragItem {
	id: string;
	/** Read out when the card is picked up and dropped. */
	label: string;
	children: ReactNode;
}

export interface DragListProps {
	/** The column these cards are in. */
	listId: string;
	items: DragItem[];
	/** Accessible name of the column. */
	label: string;
	/** Shown when the column is empty, so it is still a drop target. */
	empty?: ReactNode;
	className?: string;
	/**
	 * A card landed here.
	 *
	 * @param ids   The cards that moved, in order.
	 * @param from  The column they came from ('' when it is this one).
	 * @param index Where they landed, 0-based.
	 */
	onDrop: ( ids: string[], from: string, index: number ) => void;
	/** A card was opened (click, Enter or Space when not dragging). */
	onOpen?: ( id: string ) => void;
}

/**
 * One column.
 *
 * @param root0           Props.
 * @param root0.listId    The column id.
 * @param root0.items     The cards.
 * @param root0.label     Accessible name.
 * @param root0.empty     Shown when there is nothing in it.
 * @param root0.className Extra classes.
 * @param root0.onDrop    Called when cards land here.
 * @param root0.onOpen    Called when a card is opened.
 */
export function DragList( {
	listId,
	items,
	label,
	empty,
	className,
	onDrop,
	onOpen,
}: DragListProps ) {
	const place = ( keys: Iterable< unknown >, index: number ) => {
		const ids = [ ...keys ].map( String );
		onDrop( ids, '', index );
	};

	const { dragAndDropHooks } = useDragAndDrop( {
		getItems: ( keys ) =>
			[ ...keys ].map( ( key ) => ( {
				[ CARD ]: JSON.stringify( {
					id: String( key ),
					from: listId,
				} ),
				'text/plain': String(
					items.find( ( item ) => item.id === String( key ) )
						?.label ?? ''
				),
			} ) ),
		acceptedDragTypes: [ CARD ],
		getDropOperation: () => 'move',
		onReorder: ( event ) => place( event.keys, indexFor( items, event ) ),
		onInsert: async ( event ) => {
			const dropped = await read( event.items );
			onDrop(
				dropped.map( ( one ) => one.id ),
				dropped[ 0 ]?.from ?? '',
				indexFor( items, event )
			);
		},
		onRootDrop: async ( event ) => {
			const dropped = await read( event.items );
			onDrop(
				dropped.map( ( one ) => one.id ),
				dropped[ 0 ]?.from ?? '',
				items.length
			);
		},
	} );

	return (
		<GridList
			aria-label={ label }
			items={ items }
			dragAndDropHooks={ dragAndDropHooks }
			renderEmptyState={ () => <>{ empty }</> }
			className={ cx( 'flex flex-col gap-2 outline-none', className ) }
			onAction={ onOpen ? ( key ) => onOpen( String( key ) ) : undefined }
		>
			{ ( item: DragItem ) => (
				<GridListItem
					id={ item.id }
					textValue={ item.label }
					className={ ( { isDragging, isFocusVisible } ) =>
						cx(
							'group relative rounded-lg outline-none transition',
							isDragging && 'opacity-50',
							isFocusVisible && 'ring-2 ring-accent'
						)
					}
				>
					{ /*
					 * The handle is what makes this keyboard-draggable: the
					 * card itself opens on Enter, because that is what a
					 * card does, so picking one up needs somewhere else to
					 * press. React Aria gives the handle the whole
					 * interaction — Enter to lift, arrows to choose where,
					 * Enter to drop, Escape to put it back.
					 */ }
					<AriaButton
						slot="drag"
						// Invisible until it is focused, because React Aria
						// gives this handle `pointer-events: none`: it is
						// the keyboard's way in, and a grip a mouse cannot
						// take hold of is a promise nobody can keep.
						className={ cx(
							'absolute end-1 top-1 z-10 h-px w-px overflow-hidden rounded p-0 text-subtle outline-none',
							'focus-visible:h-auto focus-visible:w-auto focus-visible:bg-raised focus-visible:p-1 focus-visible:text-fg focus-visible:ring-2 focus-visible:ring-accent'
						) }
					>
						<span aria-hidden="true">⠿</span>
						<span className="sr-only">
							{ sprintf(
								/* translators: %s: what the card says. */
								__( 'Move %s', 'cliezoo' ),
								item.label
							) }
						</span>
					</AriaButton>
					{ item.children }
				</GridListItem>
			) }
		</GridList>
	);
}

/**
 * Where a drop landed, given what it was dropped on.
 *
 * @param items                     The cards in this column.
 * @param event                     The drop.
 * @param event.target
 * @param event.target.key
 * @param event.target.dropPosition
 */
function indexFor(
	items: DragItem[],
	event: { target: { key?: unknown; dropPosition?: string } }
): number {
	const at = items.findIndex(
		( item ) => item.id === String( event.target?.key ?? '' )
	);
	if ( at < 0 ) {
		return items.length;
	}
	return 'after' === event.target?.dropPosition ? at + 1 : at;
}

/**
 * The cards inside a drop.
 *
 * @param dropped What was dropped.
 */
async function read(
	dropped: Array< {
		kind: string;
		types?: Set< string >;
		getText?: ( type: string ) => Promise< string >;
	} >
): Promise< Array< { id: string; from: string } > > {
	const out: Array< { id: string; from: string } > = [];
	for ( const one of dropped ) {
		if (
			'text' !== one.kind ||
			! one.types?.has( CARD ) ||
			! one.getText
		) {
			continue;
		}
		try {
			const payload = JSON.parse( await one.getText( CARD ) );
			if ( payload?.id ) {
				out.push( {
					id: String( payload.id ),
					from: String( payload.from ?? '' ),
				} );
			}
		} catch {
			// A drop we cannot read is a drop from somewhere else.
		}
	}
	return out;
}
