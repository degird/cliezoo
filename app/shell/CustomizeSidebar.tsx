/**
 * Personal sidebar layout: show/hide and reorder items. Items are dragged
 * into place, and every drag has a keyboard way to do the same thing: the
 * drag handle (Enter, arrows, Enter) and the move up/down buttons beside
 * each item. Home and Inbox can be moved, never hidden (cliezoo.md §6.1).
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useSavePreferences, usePreferences, useSession } from '@cliezoo/data';
import {
	Button,
	Checkbox,
	Dialog,
	DragList,
	IconButton,
	toast,
} from '@cliezoo/ui';
import {
	ALWAYS_SHOWN,
	availableItems,
	groupLabel,
	moveWithinGroup,
	ordered,
	GROUP_ORDER,
} from './nav';

export default function CustomizeSidebar( {
	isOpen,
	onOpenChange,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const boot = useSession( ( s ) => s.boot );
	const prefs = usePreferences();
	const save = useSavePreferences();
	const [ order, setOrder ] = useState< string[] >( [] );
	const [ hidden, setHidden ] = useState< string[] >( [] );

	useEffect( () => {
		if ( isOpen ) {
			setOrder(
				ordered( availableItems( boot ), prefs.nav_order ).map(
					( item ) => item.id
				)
			);
			setHidden(
				prefs.nav_hidden.filter(
					( id ) => ! ALWAYS_SHOWN.includes( id )
				)
			);
		}
	}, [ isOpen ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const items = availableItems( boot );
	const byId = new Map( items.map( ( item ) => [ item.id, item ] ) );
	const groups = new Map( items.map( ( item ) => [ item.id, item.group ] ) );

	const moveTo = ( id: string, index: number ) =>
		setOrder( ( current ) =>
			moveWithinGroup( current, groups, id, index )
		);

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			title={ __( 'Customize sidebar', 'cliezoo' ) }
			description={ __(
				'Choose what you see and in which order: drag an item, or use its arrows. This only changes your own sidebar.',
				'cliezoo'
			) }
			footer={ ( close ) => (
				<>
					<Button
						variant="ghost"
						onPress={ () => {
							setOrder(
								availableItems( boot ).map(
									( item ) => item.id
								)
							);
							setHidden( [] );
						} }
					>
						{ __( 'Reset', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isPending={ save.isPending }
						onPress={ () =>
							save.mutate(
								{ nav_order: order, nav_hidden: hidden },
								{
									onSuccess: () => {
										toast.success(
											__( 'Sidebar saved.', 'cliezoo' )
										);
										close();
									},
									onError: ( error ) =>
										toast.error( error.message ),
								}
							)
						}
					>
						{ __( 'Save', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			{ GROUP_ORDER.map( ( group ) => {
				const ids = order.filter(
					( id ) => byId.get( id )?.group === group
				);
				if ( ! ids.length ) {
					return null;
				}
				const legend =
					groupLabel( group ) || __( 'Personal', 'cliezoo' );
				return (
					<fieldset key={ group } className="mb-4">
						<legend className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-subtle">
							{ legend }
						</legend>
						<DragList
							listId={ `nav-${ group }` }
							label={ legend }
							className="!gap-0 divide-y divide-line rounded-md border border-line"
							onDrop={ ( dropped, from, index ) => {
								// Items stay in their own group: a drop
								// from another one is not a move here.
								const id = dropped[ 0 ];
								if ( from || ! id ) {
									return;
								}
								const at = ids.indexOf( id );
								moveTo( id, index > at ? index - 1 : index );
							} }
							items={ ids.flatMap( ( id, index ) => {
								const item = byId.get( id );
								if ( ! item ) {
									return [];
								}
								const always = ALWAYS_SHOWN.includes( id );
								return [
									{
										id,
										label: item.label,
										children: (
											<div className="flex items-center gap-2 px-3 py-1.5">
												<span
													aria-hidden="true"
													className="cursor-grab select-none text-subtle"
												>
													⠿
												</span>
												<Checkbox
													className="flex-1"
													isSelected={
														always ||
														! hidden.includes( id )
													}
													isDisabled={ always }
													onChange={ ( show ) =>
														setHidden(
															show
																? hidden.filter(
																		( h ) =>
																			h !==
																			id
																	)
																: [
																		...hidden,
																		id,
																	]
														)
													}
												>
													{ item.label }
													{ always && (
														<span className="ms-1 text-xs text-subtle">
															{ __(
																'(always shown)',
																'cliezoo'
															) }
														</span>
													) }
												</Checkbox>
												<IconButton
													size="sm"
													tooltip={ false }
													label={ sprintf(
														/* translators: %s: menu item. */
														__(
															'Move %s up',
															'cliezoo'
														),
														item.label
													) }
													icon={
														<ArrowUp
															size={ 14 }
															aria-hidden="true"
														/>
													}
													isDisabled={ index === 0 }
													onPress={ () =>
														moveTo( id, index - 1 )
													}
												/>
												<IconButton
													size="sm"
													tooltip={ false }
													label={ sprintf(
														/* translators: %s: menu item. */
														__(
															'Move %s down',
															'cliezoo'
														),
														item.label
													) }
													icon={
														<ArrowDown
															size={ 14 }
															aria-hidden="true"
														/>
													}
													isDisabled={
														index === ids.length - 1
													}
													onPress={ () =>
														moveTo( id, index + 1 )
													}
												/>
											</div>
										),
									},
								];
							} ) }
						/>
					</fieldset>
				);
			} ) }
		</Dialog>
	);
}
