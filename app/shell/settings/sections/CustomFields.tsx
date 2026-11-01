/**
 * Settings → Data model: custom fields per record type (no limit on how
 * many), with options, defaults, help text and client visibility.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import {
	api,
	getBoot,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Alert,
	Button,
	Checkbox,
	Dialog,
	EmptyState,
	ErrorState,
	IconButton,
	Select,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import { FieldInput } from '../../../packages/app/form/FieldInput';

interface EntityInfo {
	name: string;
	plural_label: string;
	custom_fields: boolean;
}

interface FieldType {
	id: string;
	label: string;
	choices: boolean;
}

interface Choice {
	value: string;
	label: string;
	color?: string;
}

interface FieldOptions {
	choices?: Choice[];
	/** Number: how it is shown. */
	format?: 'plain' | 'thousands' | 'percentage';
	decimals?: number | null;
	/** Currency: the code amounts are counted in. */
	currency?: string;
	/** Rating: the most stars. */
	max?: number;
}

interface CustomField {
	uid: string;
	entity: string;
	field_key: string;
	label: string;
	type: string;
	options: FieldOptions | null;
	required: boolean;
	default_value: string | null;
	help: string | null;
	visible_to_clients: boolean;
	/** Whether the icon and colour are shown beside the value. */
	show_icon?: boolean;
	icon?: string | null;
	color?: string | null;
	position: number;
	/** Where a task field is used: everywhere, one board or one task. */
	scope_type?: 'global' | 'board' | 'task';
	/** The board or task it is kept for (uid). */
	scope_of?: string;
}

const empty = ( entity: string ): Partial< CustomField > => ( {
	entity,
	label: '',
	type: 'text',
	options: { choices: [] },
	required: false,
	visible_to_clients: false,
	help: '',
	default_value: '',
} );

/**
 * The options one type keeps: a list its choices, a number its format, a
 * currency field its currency, a rating its most stars. Options of another
 * type are left behind, so switching type while adding a field does not
 * save what the first type needed.
 *
 * @param type    The field type.
 * @param options What the editor holds.
 * @param choices Whether the type has choices.
 */
export function optionsFor(
	type: string,
	options: FieldOptions | null | undefined,
	choices: boolean
): FieldOptions | null {
	const o = options ?? {};
	if ( choices ) {
		return { choices: o.choices ?? [] };
	}
	switch ( type ) {
		case 'number':
			return {
				format: o.format ?? 'plain',
				decimals: 'number' === typeof o.decimals ? o.decimals : null,
			};
		case 'currency':
			return { currency: o.currency || getBoot().workspace.currency };
		case 'rating':
			return { max: o.max ?? 5 };
	}
	return null;
}

/** The currencies this browser can name, the workspace's first. */
function currencyCodes(): string[] {
	let codes: string[];
	try {
		codes = (
			Intl as unknown as { supportedValuesOf: ( k: string ) => string[] }
		 ).supportedValuesOf( 'currency' );
	} catch {
		codes = [ 'USD', 'EUR', 'GBP' ];
	}
	const own = getBoot().workspace.currency;
	return [ own, ...codes.filter( ( code ) => code !== own ) ];
}

export default function CustomFields() {
	const client = useQueryClient();
	const [ entity, setEntity ] = useState( '' );
	const [ editing, setEditing ] = useState< Partial< CustomField > | null >(
		null
	);
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ removing, setRemoving ] = useState< CustomField | null >( null );
	const [ busy, setBusy ] = useState( false );

	const entities = useQuery( {
		queryKey: [ 'meta', 'entities', { custom_fields: true } ],
		queryFn: async () =>
			(
				await api.get< EntityInfo[] >( '/meta/entities', {
					custom_fields: true,
				} )
			).data,
	} );
	const types = useQuery( {
		queryKey: [ 'custom-field-types' ],
		staleTime: 10 * 60_000,
		queryFn: async () =>
			( await api.get< FieldType[] >( '/custom-fields/types' ) ).data,
	} );
	const fields = useQuery( {
		queryKey: [ 'custom_fields', 'list', { entity } ],
		enabled: !! entity,
		queryFn: async () =>
			(
				await api.get< CustomField[] >( '/custom_fields', {
					filter: JSON.stringify( {
						field: 'entity',
						op: 'eq',
						value: entity,
					} ),
					sort: 'position',
					limit: 200,
				} )
			).data,
	} );

	useEffect( () => {
		if ( ! entity && entities.data?.length ) {
			setEntity( entities.data[ 0 ].name );
		}
	}, [ entities.data, entity ] );

	const refresh = () => {
		client.invalidateQueries( { queryKey: [ 'custom_fields' ] } );
		client.invalidateQueries( { queryKey: [ 'meta' ] } );
	};
	const type = types.data?.find( ( t ) => t.id === editing?.type );
	const choices = editing?.options?.choices ?? [];

	const save = async () => {
		setBusy( true );
		setErrors( {} );
		try {
			const body = {
				entity,
				label: editing?.label,
				type: editing?.type,
				options: optionsFor(
					editing?.type ?? 'text',
					editing?.options,
					!! type?.choices
				),
				show_icon: !! editing?.show_icon,
				icon: editing?.icon ?? '',
				color: editing?.color || null,
				required: editing?.required,
				visible_to_clients: editing?.visible_to_clients,
				help: editing?.help,
				default_value: editing?.default_value,
				field_key: editing?.field_key,
				...( 'tasks' === entity
					? {
							scope_type: editing?.scope_type ?? 'global',
							scope_of:
								'global' === ( editing?.scope_type ?? 'global' )
									? ''
									: ( editing?.scope_of ?? '' ),
						}
					: {} ),
			};
			if ( editing?.uid ) {
				await api.patch( `/custom_fields/${ editing.uid }`, body );
			} else {
				await api.post( '/custom_fields', body );
			}
			setEditing( null );
			refresh();
			toast.success( __( 'Field saved.', 'cliezoo' ) );
		} catch ( error ) {
			setErrors( ( error as ApiError ).fields );
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const move = async ( field: CustomField, delta: number ) => {
		const list = [ ...( fields.data ?? [] ) ];
		const index = list.findIndex( ( f ) => f.uid === field.uid );
		const target = list[ index + delta ];
		if ( ! target ) {
			return;
		}
		await Promise.all( [
			api.patch( `/custom_fields/${ field.uid }`, {
				position: target.position,
			} ),
			api.patch( `/custom_fields/${ target.uid }`, {
				position: field.position,
			} ),
		] );
		refresh();
	};

	const remove = async ( field: CustomField ) => {
		setBusy( true );
		try {
			await api.delete( `/custom_fields/${ field.uid }` );
			setRemoving( null );
			refresh();
			toast.success(
				__(
					'Field removed. Values stay in the database until the trash is emptied.',
					'cliezoo'
				)
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	if ( entities.isPending || types.isPending ) {
		return <Skeleton className="h-48" />;
	}
	if ( entities.isError || types.isError ) {
		return (
			<ErrorState
				error={ entities.error ?? types.error }
				onRetry={ () => {
					entities.refetch();
					types.refetch();
				} }
			/>
		);
	}
	if ( ! entities.data?.length ) {
		return (
			<EmptyState title={ __( 'No record types yet', 'cliezoo' ) }>
				{ __(
					'Custom fields appear here once modules such as Clients or Projects are active.',
					'cliezoo'
				) }
			</EmptyState>
		);
	}

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-end gap-3">
				<Select
					label={ __( 'Record type', 'cliezoo' ) }
					className="w-60"
					items={ ( entities.data ?? [] ).map( ( e ) => ( {
						id: e.name,
						label: e.plural_label,
					} ) ) }
					selectedKey={ entity }
					onSelectionChange={ ( key ) => setEntity( String( key ) ) }
				/>
				<Button
					className="ms-auto"
					variant="primary"
					icon={ <Plus size={ 14 } aria-hidden="true" /> }
					onPress={ () => setEditing( empty( entity ) ) }
				>
					{ __( 'Add field', 'cliezoo' ) }
				</Button>
			</div>

			{ fields.isPending && <Skeleton className="h-40" /> }
			{ fields.isError && (
				<ErrorState
					error={ fields.error }
					onRetry={ () => fields.refetch() }
				/>
			) }
			{ fields.data && ! fields.data.length && (
				<EmptyState title={ __( 'No custom fields yet', 'cliezoo' ) }>
					{ __(
						'Add the fields your team needs. They appear on records, in filters, columns and exports.',
						'cliezoo'
					) }
				</EmptyState>
			) }
			{ !! fields.data?.length && (
				<ul className="divide-y divide-line rounded-lg border border-line bg-raised">
					{ fields.data.map( ( field, index ) => (
						<li
							key={ field.uid }
							className="flex flex-wrap items-center gap-3 px-4 py-3"
						>
							<div className="min-w-0 flex-1">
								<p className="text-sm font-medium">
									{ field.label }
									{ field.required && (
										<span className="ms-1 text-danger">
											*
										</span>
									) }
								</p>
								<p className="text-xs text-muted">
									{ types.data?.find(
										( t ) => t.id === field.type
									)?.label ?? field.type }{ ' ' }
									· <code>custom.{ field.field_key }</code>
									{ field.visible_to_clients &&
										` · ${ __( 'Visible to clients', 'cliezoo' ) }` }
								</p>
							</div>
							<IconButton
								size="sm"
								label={ __( 'Move up', 'cliezoo' ) }
								tooltip={ false }
								icon={
									<ArrowUp size={ 14 } aria-hidden="true" />
								}
								isDisabled={ index === 0 }
								onPress={ () => move( field, -1 ) }
							/>
							<IconButton
								size="sm"
								label={ __( 'Move down', 'cliezoo' ) }
								tooltip={ false }
								icon={
									<ArrowDown size={ 14 } aria-hidden="true" />
								}
								isDisabled={
									index === ( fields.data?.length ?? 0 ) - 1
								}
								onPress={ () => move( field, 1 ) }
							/>
							<Button
								size="sm"
								onPress={ () => setEditing( field ) }
							>
								{ __( 'Edit', 'cliezoo' ) }
							</Button>
							<IconButton
								size="sm"
								label={ __( 'Remove field', 'cliezoo' ) }
								icon={
									<Trash2 size={ 14 } aria-hidden="true" />
								}
								onPress={ () => setRemoving( field ) }
							/>
						</li>
					) ) }
				</ul>
			) }

			<Dialog
				isOpen={ !! removing }
				onOpenChange={ () => setRemoving( null ) }
				role="alertdialog"
				title={ __( 'Remove this field?', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: field label. */
					__(
						'“%s” disappears from every record, filter and export. Saved values stay in the database until the trash is emptied.',
						'cliezoo'
					),
					String( removing?.label ?? '' )
				) }
				footer={ () => (
					<Button
						variant="danger"
						isPending={ busy }
						onPress={ () => {
							if ( removing ) {
								remove( removing );
							}
						} }
					>
						{ __( 'Remove field', 'cliezoo' ) }
					</Button>
				) }
			/>

			<Dialog
				isOpen={ !! editing }
				onOpenChange={ () => setEditing( null ) }
				size="lg"
				title={
					editing?.uid
						? __( 'Edit field', 'cliezoo' )
						: __( 'Add field', 'cliezoo' )
				}
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ ! editing?.label?.trim() }
						onPress={ save }
					>
						{ __( 'Save field', 'cliezoo' ) }
					</Button>
				) }
			>
				{ editing && (
					<div className="space-y-4">
						<div className="grid gap-4 sm:grid-cols-2">
							<TextField
								label={ __( 'Label', 'cliezoo' ) }
								value={ editing.label ?? '' }
								onChange={ ( v ) =>
									setEditing( { ...editing, label: v } )
								}
								isRequired
								errorMessage={ errors.label }
							/>
							<Select
								label={ __( 'Type', 'cliezoo' ) }
								items={ ( types.data ?? [] ).map( ( t ) => ( {
									id: t.id,
									label: t.label,
								} ) ) }
								selectedKey={ editing.type ?? 'text' }
								isDisabled={ !! editing.uid }
								onSelectionChange={ ( key ) =>
									setEditing( {
										...editing,
										type: String( key ),
									} )
								}
								description={
									editing.uid
										? __(
												'The type of an existing field cannot change.',
												'cliezoo'
											)
										: undefined
								}
							/>
						</div>
						{ type?.choices && (
							<fieldset>
								<legend className="text-sm font-medium">
									{ __( 'Choices', 'cliezoo' ) }
								</legend>
								<ul className="mt-2 space-y-2">
									{ choices.map( ( choice, index ) => (
										<li
											key={ index }
											className="grid items-end gap-2 sm:grid-cols-[1fr_8rem_auto]"
										>
											<TextField
												label={ __(
													'Label',
													'cliezoo'
												) }
												value={ choice.label }
												onChange={ ( v ) =>
													setEditing( {
														...editing,
														options: {
															choices:
																choices.map(
																	( c, i ) =>
																		i ===
																		index
																			? {
																					...c,
																					label: v,
																					value:
																						c.value ||
																						v
																							.toLowerCase()
																							.replace(
																								/[^a-z0-9]+/g,
																								'_'
																							),
																				}
																			: c
																),
														},
													} )
												}
											/>
											<TextField
												label={ __(
													'Colour',
													'cliezoo'
												) }
												type="color"
												value={
													choice.color ?? '#0458fb'
												}
												onChange={ ( v ) =>
													setEditing( {
														...editing,
														options: {
															choices:
																choices.map(
																	( c, i ) =>
																		i ===
																		index
																			? {
																					...c,
																					color: v,
																				}
																			: c
																),
														},
													} )
												}
											/>
											<IconButton
												label={ __(
													'Remove choice',
													'cliezoo'
												) }
												tooltip={ false }
												icon={
													<Trash2
														size={ 14 }
														aria-hidden="true"
													/>
												}
												onPress={ () =>
													setEditing( {
														...editing,
														options: {
															choices:
																choices.filter(
																	( _, i ) =>
																		i !==
																		index
																),
														},
													} )
												}
											/>
										</li>
									) ) }
								</ul>
								<Button
									className="mt-2"
									size="sm"
									icon={
										<Plus size={ 14 } aria-hidden="true" />
									}
									onPress={ () =>
										setEditing( {
											...editing,
											options: {
												choices: [
													...choices,
													{ value: '', label: '' },
												],
											},
										} )
									}
								>
									{ __( 'Add choice', 'cliezoo' ) }
								</Button>
							</fieldset>
						) }
						{ 'number' === editing.type && (
							<div className="grid gap-4 sm:grid-cols-2">
								<Select
									label={ __( 'Number format', 'cliezoo' ) }
									items={ [
										{
											id: 'plain',
											label: __(
												'Plain (1234.5)',
												'cliezoo'
											),
										},
										{
											id: 'thousands',
											label: __(
												'Thousands separator (1,234.5)',
												'cliezoo'
											),
										},
										{
											id: 'percentage',
											label: __(
												'Percentage (12.5%)',
												'cliezoo'
											),
										},
									] }
									selectedKey={
										editing.options?.format ?? 'plain'
									}
									onSelectionChange={ ( key ) =>
										setEditing( {
											...editing,
											options: {
												...editing.options,
												format: String(
													key
												) as FieldOptions[ 'format' ],
											},
										} )
									}
								/>
								<TextField
									label={ __( 'Decimal places', 'cliezoo' ) }
									type="number"
									inputMode="numeric"
									description={ __(
										'Leave empty to show the number as it was typed.',
										'cliezoo'
									) }
									value={
										'number' ===
										typeof editing.options?.decimals
											? String( editing.options.decimals )
											: ''
									}
									onChange={ ( v ) =>
										setEditing( {
											...editing,
											options: {
												...editing.options,
												decimals:
													'' === v
														? null
														: Math.max(
																0,
																Math.min(
																	6,
																	Number( v )
																)
															),
											},
										} )
									}
								/>
							</div>
						) }
						{ 'currency' === editing.type && (
							<Select
								label={ __( 'Currency', 'cliezoo' ) }
								className="sm:w-60"
								description={ __(
									'Amounts in this field are counted in this currency.',
									'cliezoo'
								) }
								items={ currencyCodes().map( ( code ) => ( {
									id: code,
									label: code,
								} ) ) }
								selectedKey={
									editing.options?.currency ||
									getBoot().workspace.currency
								}
								onSelectionChange={ ( key ) =>
									setEditing( {
										...editing,
										options: {
											...editing.options,
											currency: String( key ),
										},
									} )
								}
							/>
						) }
						{ 'rating' === editing.type && (
							<TextField
								label={ __( 'Most stars', 'cliezoo' ) }
								className="sm:w-60"
								type="number"
								inputMode="numeric"
								value={ String( editing.options?.max ?? 5 ) }
								onChange={ ( v ) =>
									setEditing( {
										...editing,
										options: {
											...editing.options,
											max: Math.max(
												1,
												Math.min( 10, Number( v ) || 5 )
											),
										},
									} )
								}
							/>
						) }
						<fieldset className="space-y-3">
							<legend className="text-sm font-medium">
								{ __( 'Icon and colour', 'cliezoo' ) }
							</legend>
							<Switch
								isSelected={ !! editing.show_icon }
								onChange={ ( on ) =>
									setEditing( { ...editing, show_icon: on } )
								}
							>
								{ __(
									'Show an icon and colour beside the value',
									'cliezoo'
								) }
							</Switch>
							{ editing.show_icon && (
								<div className="grid gap-4 sm:grid-cols-2">
									<TextField
										label={ __( 'Icon', 'cliezoo' ) }
										description={ __(
											'An icon name, for example "star" or "phone".',
											'cliezoo'
										) }
										value={ editing.icon ?? '' }
										onChange={ ( v ) =>
											setEditing( {
												...editing,
												icon: v
													.toLowerCase()
													.replace(
														/[^a-z0-9-]/g,
														''
													)
													.slice( 0, 60 ),
											} )
										}
									/>
									<TextField
										label={ __( 'Colour', 'cliezoo' ) }
										type="color"
										value={ editing.color || '#0458fb' }
										onChange={ ( v ) =>
											setEditing( {
												...editing,
												color: v,
											} )
										}
									/>
								</div>
							) }
						</fieldset>
						<div className="grid gap-4 sm:grid-cols-2">
							<TextField
								label={ __( 'Default value', 'cliezoo' ) }
								value={ editing.default_value ?? '' }
								onChange={ ( v ) =>
									setEditing( {
										...editing,
										default_value: v,
									} )
								}
							/>
							<TextField
								label={ __( 'Help text', 'cliezoo' ) }
								value={ editing.help ?? '' }
								onChange={ ( v ) =>
									setEditing( { ...editing, help: v } )
								}
							/>
						</div>
						{ ! editing.uid && (
							<TextField
								label={ __( 'Key', 'cliezoo' ) }
								description={ __(
									'Used in filters, exports and automations. Leave empty to build it from the label.',
									'cliezoo'
								) }
								value={ editing.field_key ?? '' }
								onChange={ ( v ) =>
									setEditing( {
										...editing,
										field_key: v
											.toLowerCase()
											.replace( /[^a-z0-9_]/g, '' ),
									} )
								}
								errorMessage={ errors.field_key }
							/>
						) }
						{ 'tasks' === entity && (
							<div className="grid gap-4 sm:grid-cols-2">
								<Select
									label={ __(
										'Where it is used',
										'cliezoo'
									) }
									items={ [
										{
											id: 'global',
											label: __(
												'On every task',
												'cliezoo'
											),
										},
										{
											id: 'board',
											label: __(
												'On one board',
												'cliezoo'
											),
										},
										{
											id: 'task',
											label: __(
												'On one task',
												'cliezoo'
											),
										},
									] }
									selectedKey={
										editing.scope_type ?? 'global'
									}
									onSelectionChange={ ( key ) =>
										setEditing( {
											...editing,
											scope_type: String(
												key
											) as CustomField[ 'scope_type' ],
											scope_of: '',
										} )
									}
									errorMessage={ errors.scope_type }
								/>
								{ 'global' !==
									( editing.scope_type ?? 'global' ) && (
									<FieldInput
										key={ editing.scope_type }
										field={ {
											key: 'scope_of',
											label:
												'board' === editing.scope_type
													? __( 'Board', 'cliezoo' )
													: __( 'Task', 'cliezoo' ),
											type: 'ref',
											ref:
												'board' === editing.scope_type
													? 'boards'
													: 'tasks',
											required: true,
										} }
										value={ editing.scope_of ?? null }
										onChange={ ( value ) =>
											setEditing( {
												...editing,
												scope_of:
													value &&
													'object' === typeof value
														? String(
																(
																	value as {
																		uid?: string;
																	}
																 ).uid ?? ''
															)
														: String( value ?? '' ),
											} )
										}
										error={ errors.scope_of }
									/>
								) }
							</div>
						) }
						<div className="space-y-3">
							<Checkbox
								isSelected={ !! editing.required }
								onChange={ ( on ) =>
									setEditing( { ...editing, required: on } )
								}
							>
								{ __( 'Required', 'cliezoo' ) }
							</Checkbox>
							<Switch
								isSelected={ !! editing.visible_to_clients }
								onChange={ ( on ) =>
									setEditing( {
										...editing,
										visible_to_clients: on,
									} )
								}
							>
								{ __(
									'Visible to clients in the portal',
									'cliezoo'
								) }
							</Switch>
						</div>
						{ !! editing.uid && (
							<Alert tone="info">
								{ sprintf(
									/* translators: %s: field key. */
									__(
										'Records already using custom.%s keep their values.',
										'cliezoo'
									),
									String( editing.field_key )
								) }
							</Alert>
						) }
					</div>
				) }
			</Dialog>
		</div>
	);
}
