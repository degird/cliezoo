/**
 * The list's column picker: which columns show, and in what order.
 *
 * Shown columns are dragged into order, and every drag has a keyboard way
 * to do the same: the drag handle (Enter, arrows, Enter), and the "move
 * left" and "move right" buttons on each one. The order is part of the
 * list's layout, so it is remembered and saved with a view like the rest.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, ArrowRight, EyeOff } from 'lucide-react';
import { Checkbox, DragList, IconButton } from '@cliezoo/ui';
import type { FieldMeta } from './meta';
import { moveColumn } from './sort';

export interface ColumnPickerProps {
	/** Every field the list could show. */
	fields: FieldMeta[];
	/** The shown ones, in order. */
	columns: string[];
	/** The record's name: always shown, always first. */
	titleField: string;
	onChange: ( columns: string[] ) => void;
}

export function ColumnPicker( {
	fields,
	columns,
	titleField,
	onChange,
}: ColumnPickerProps ) {
	const byKey = new Map( fields.map( ( f ) => [ f.key, f ] ) );
	const shown = columns.filter( ( key ) => byKey.has( key ) );
	const hidden = fields.filter( ( f ) => ! shown.includes( f.key ) );
	const move = ( key: string, index: number ) =>
		onChange( moveColumn( shown, key, index, titleField ) );

	return (
		<div className="space-y-3">
			<div>
				<h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-subtle">
					{ __( 'Shown, in order', 'cliezoo' ) }
				</h3>
				<DragList
					listId="columns"
					label={ __( 'Shown columns', 'cliezoo' ) }
					className="!gap-0.5"
					onDrop={ ( keys, from, index ) => {
						const key = keys[ 0 ];
						if ( from || ! key ) {
							return;
						}
						const at = shown.indexOf( key );
						move( key, index > at ? index - 1 : index );
					} }
					items={ shown.map( ( key, index ) => {
						const field = byKey.get( key ) as FieldMeta;
						const pinned = key === titleField;
						return {
							id: key,
							label: field.label,
							children: (
								<div className="flex items-center gap-1 rounded px-1.5 py-1 text-sm hover:bg-sunken">
									<span
										aria-hidden="true"
										className="cursor-grab select-none px-0.5 text-subtle"
									>
										⠿
									</span>
									<span className="min-w-0 flex-1 truncate">
										{ field.label }
									</span>
									{ ! pinned && (
										<>
											<IconButton
												size="sm"
												tooltip={ false }
												label={ sprintf(
													/* translators: %s: column name. */
													__(
														'Move %s left',
														'cliezoo'
													),
													field.label
												) }
												icon={
													<ArrowLeft
														size={ 13 }
														aria-hidden="true"
														className="rtl:rotate-180"
													/>
												}
												isDisabled={ index <= 1 }
												onPress={ () =>
													move( key, index - 1 )
												}
											/>
											<IconButton
												size="sm"
												tooltip={ false }
												label={ sprintf(
													/* translators: %s: column name. */
													__(
														'Move %s right',
														'cliezoo'
													),
													field.label
												) }
												icon={
													<ArrowRight
														size={ 13 }
														aria-hidden="true"
														className="rtl:rotate-180"
													/>
												}
												isDisabled={
													index === shown.length - 1
												}
												onPress={ () =>
													move( key, index + 1 )
												}
											/>
											<IconButton
												size="sm"
												tooltip={ false }
												label={ sprintf(
													/* translators: %s: column name. */
													__( 'Hide %s', 'cliezoo' ),
													field.label
												) }
												icon={
													<EyeOff
														size={ 13 }
														aria-hidden="true"
													/>
												}
												onPress={ () =>
													onChange(
														shown.filter(
															( c ) => c !== key
														)
													)
												}
											/>
										</>
									) }
								</div>
							),
						};
					} ) }
				/>
			</div>
			{ hidden.length > 0 && (
				<div>
					<h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-subtle">
						{ __( 'Add a column', 'cliezoo' ) }
					</h3>
					<ul className="space-y-1.5">
						{ hidden.map( ( f ) => (
							<li key={ f.key }>
								<Checkbox
									isSelected={ false }
									onChange={ ( on ) =>
										on && onChange( [ ...shown, f.key ] )
									}
								>
									{ f.label }
								</Checkbox>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
