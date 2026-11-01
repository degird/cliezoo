/**
 * Everything you can do to a folder: change how it looks and what it
 * allows, move it, copy it, keep it as a template, share it, put it away.
 * The same menu on a folder's tile and in the folder itself.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { copyText } from '../../packages/app/copy';
import {
	Archive,
	Copy,
	CopyPlus,
	FolderInput,
	LayoutTemplate,
	Link2,
	Pencil,
	Share2,
	Star,
	Trash2,
} from 'lucide-react';
import {
	api,
	can,
	getBoot,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Button,
	Checkbox,
	ConfirmDialog,
	Dialog,
	ErrorState,
	IconButton,
	Menu,
	MenuItem,
	MenuSection,
	MenuTrigger,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { ShareDialog } from '../../packages/app';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { appHref } from '../../packages/app/router';
import { FolderPicker } from './FolderPicker';

export interface MenuFolder {
	uid: string;
	name: string;
	is_default?: boolean;
}

export function FolderMenu( {
	folder,
	onChanged,
	onGone,
	label,
	isOpen,
	onOpenChange,
}: {
	folder: MenuFolder;
	onChanged: () => void;
	/** After it was archived, trashed or moved out of sight. */
	onGone?: () => void;
	label?: string;
	/** Opened by something else, such as a right-click on its tile. */
	isOpen?: boolean;
	onOpenChange?: ( open: boolean ) => void;
} ) {
	const queries = useQueryClient();
	const user = getBoot().user;
	const staff = ! user?.client;
	const writer = can.write( user );
	const templates =
		!! user && ( user.admin || can.toggle( user, 'templates_manage' ) );
	const [ open, setOpen ] = useState<
		'' | 'edit' | 'move' | 'template' | 'share'
	>( '' );
	// Putting a folder away takes everything in it, which is the largest
	// thing anybody can remove here off one menu item.
	const [ putting, setPutting ] = useState< '' | 'archive' | 'trash' >( '' );
	const [ name, setName ] = useState( '' );
	const [ keepFiles, setKeepFiles ] = useState( false );
	// Where the template is filed, which the picker shows beside its name.
	const [ category, setCategory ] = useState( '' );
	const [ keepPeople, setKeepPeople ] = useState( false );
	const [ target, setTarget ] = useState( '' );

	const refresh = async () => {
		await queries.invalidateQueries( { queryKey: [ 'files' ] } );
		onChanged();
	};
	// Says whether the work actually happened, so a dialog only closes on
	// the answer it asked for: closing it on a refusal takes what was
	// typed or chosen in it.
	const act = async (
		work: () => Promise< unknown >,
		said: string,
		gone = false
	) => {
		try {
			await work();
			await refresh();
			toast.success( said );
			if ( gone ) {
				onGone?.();
			}
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		}
	};

	const onAction = ( key: unknown ) => {
		const id = String( key );
		if ( 'edit' === id || 'share' === id ) {
			setOpen( id );
		} else if ( 'move' === id ) {
			setTarget( '' );
			setOpen( 'move' );
		} else if ( 'template' === id ) {
			setName( folder.name );
			setKeepFiles( false );
			setKeepPeople( false );
			setCategory( '' );
			setOpen( 'template' );
		} else if (
			'copy' === id ||
			'copy-files' === id ||
			'copy-all' === id
		) {
			act(
				() =>
					api.post( `/folders/${ folder.uid }/duplicate`, {
						files: 'copy' !== id,
						sharing: 'copy-all' === id,
					} ),
				__( 'Copied.', 'cliezoo' )
			);
		} else if ( 'favourite' === id ) {
			act(
				() =>
					api
						.post( '/favourites', {
							object_type: 'folders',
							object: folder.uid,
						} )
						.then( () =>
							queries.invalidateQueries( {
								queryKey: [ 'favourites' ],
							} )
						),
				__( 'Changed on the sidebar.', 'cliezoo' )
			);
		} else if ( 'link' === id ) {
			copyText( appHref( `/files?folder=${ folder.uid }` ) );
		} else if ( 'archive' === id || 'trash' === id ) {
			setPutting( id );
		}
	};

	const putAway = () => {
		if ( 'archive' === putting ) {
			act(
				() => api.post( `/folders/${ folder.uid }/archive` ),
				__(
					'Archived. It is in the Library, under Archive.',
					'cliezoo'
				),
				true
			);
		} else if ( 'trash' === putting ) {
			act(
				() => api.delete( `/folders/${ folder.uid }` ),
				__( 'Moved to the trash.', 'cliezoo' ),
				true
			);
		}
		setPutting( '' );
	};

	const disabled = [
		...( writer
			? []
			: [ 'edit', 'move', 'copy', 'copy-files', 'archive', 'trash' ] ),
		...( templates ? [] : [ 'template' ] ),
		...( folder.is_default ? [ 'trash', 'archive', 'move' ] : [] ),
	];

	return (
		<>
			<MenuTrigger
				{ ...( undefined === isOpen ? {} : { isOpen, onOpenChange } ) }
			>
				<IconButton
					size="sm"
					label={
						label ??
						sprintf(
							/* translators: %s: the name of the record the menu is for. */
							__( 'More actions for %s', 'cliezoo' ),
							folder.name
						)
					}
					icon={ <span aria-hidden="true">⋯</span> }
				/>
				<Menu onAction={ onAction } disabledKeys={ disabled }>
					<MenuSection id="folder-change">
						<MenuItem id="edit" icon={ <Pencil size={ 16 } /> }>
							{ __( 'Name, looks and rules…', 'cliezoo' ) }
						</MenuItem>
						<MenuItem
							id="move"
							icon={ <FolderInput size={ 16 } /> }
						>
							{ __( 'Move to…', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="copy" icon={ <Copy size={ 16 } /> }>
							{ __( 'Make a copy', 'cliezoo' ) }
						</MenuItem>
						<MenuItem
							id="copy-files"
							icon={ <CopyPlus size={ 16 } /> }
						>
							{ __( 'Make a copy, with the files', 'cliezoo' ) }
						</MenuItem>
						{ staff && writer && (
							<MenuItem
								id="copy-all"
								icon={ <Copy size={ 16 } /> }
							>
								{ __(
									'Make a copy, with the files and the people',
									'cliezoo'
								) }
							</MenuItem>
						) }
						{ staff && (
							<MenuItem
								id="template"
								icon={ <LayoutTemplate size={ 16 } /> }
							>
								{ __( 'Keep as a template…', 'cliezoo' ) }
							</MenuItem>
						) }
					</MenuSection>
					<MenuSection id="folder-find">
						<MenuItem id="favourite" icon={ <Star size={ 16 } /> }>
							{ __( 'Keep on the sidebar', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="link" icon={ <Link2 size={ 16 } /> }>
							{ __( 'Copy link', 'cliezoo' ) }
						</MenuItem>
						{ staff && (
							<MenuItem
								id="share"
								icon={ <Share2 size={ 16 } /> }
							>
								{ __( 'Who can see it', 'cliezoo' ) }
							</MenuItem>
						) }
					</MenuSection>
					<MenuSection id="folder-away">
						<MenuItem id="archive" icon={ <Archive size={ 16 } /> }>
							{ __( 'Archive', 'cliezoo' ) }
						</MenuItem>
						{ /* A default folder cannot be thrown away, so the
						     row keeps its own name and carries the reason
						     underneath. Putting the reason where the name
						     goes — in red, behind a bin — read as an
						     action nobody would want to take. */ }
						<MenuItem
							id="trash"
							icon={ <Trash2 size={ 16 } /> }
							danger={ ! folder.is_default }
							description={
								folder.is_default
									? __(
											'Comes with every client and project',
											'cliezoo'
										)
									: undefined
							}
						>
							{ __( 'Move to trash', 'cliezoo' ) }
						</MenuItem>
					</MenuSection>
				</Menu>
			</MenuTrigger>

			{ 'share' === open && (
				<Suspense fallback={ null }>
					<ShareDialog
						type="folders"
						uid={ folder.uid }
						isOpen
						onOpenChange={ ( on ) => ! on && setOpen( '' ) }
					/>
				</Suspense>
			) }

			{ 'edit' === open && (
				<FolderDetails
					uid={ folder.uid }
					onClose={ () => setOpen( '' ) }
					onSaved={ async () => {
						setOpen( '' );
						await refresh();
					} }
				/>
			) }

			{ 'move' === open && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( on ) => ! on && setOpen( '' ) }
					title={ sprintf(
						/* translators: %s: the name of the file or folder being moved. */
						__( 'Move “%s”', 'cliezoo' ),
						folder.name
					) }
					description={ __(
						'Everything inside it goes with it.',
						'cliezoo'
					) }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								onPress={ () =>
									act(
										() =>
											api.patch(
												`/folders/${ folder.uid }`,
												{
													parent: target || null,
												}
											),
										__( 'Moved.', 'cliezoo' )
									).then( ( worked ) => {
										if ( worked ) {
											setOpen( '' );
										}
									} )
								}
							>
								{ __( 'Move it here', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<FolderPicker
						value={ target }
						onChange={ setTarget }
						except={ folder.uid }
						enabled={ 'move' === open }
					/>
				</Dialog>
			) }

			{ 'template' === open && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( on ) => ! on && setOpen( '' ) }
					title={ __( 'Keep as a template', 'cliezoo' ) }
					description={ __(
						'Its folders, colours, icons and rules, so the next one starts the same shape — and, if you like, its files and its people.',
						'cliezoo'
					) }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={ ! name.trim() }
								onPress={ () =>
									act(
										() =>
											api.post(
												`/folders/${ folder.uid }/template`,
												{
													name: name.trim(),
													category: category.trim(),
													files: keepFiles,
													sharing: keepPeople,
												}
											),
										__( 'Kept as a template.', 'cliezoo' )
									).then( ( worked ) => {
										if ( worked ) {
											setOpen( '' );
										}
									} )
								}
							>
								{ __( 'Keep it', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<div className="space-y-3">
						<TextField
							label={ __( 'Call it', 'cliezoo' ) }
							value={ name }
							onChange={ setName }
							maxLength={ 190 }
						/>
						<TextField
							label={ __( 'Category', 'cliezoo' ) }
							description={ __(
								'Optional: “Clients”, “Internal” — shown beside its name when picking one.',
								'cliezoo'
							) }
							value={ category }
							onChange={ setCategory }
							maxLength={ 80 }
						/>
						<Checkbox
							isSelected={ keepFiles }
							onChange={ setKeepFiles }
						>
							{ __( 'Bring the files along', 'cliezoo' ) }
						</Checkbox>
						<Checkbox
							isSelected={ keepPeople }
							onChange={ setKeepPeople }
						>
							{ __(
								'Bring the people it is shared with',
								'cliezoo'
							) }
						</Checkbox>
					</div>
				</Dialog>
			) }

			<ConfirmDialog
				isOpen={ '' !== putting }
				onOpenChange={ ( on ) => ! on && setPutting( '' ) }
				title={
					'archive' === putting
						? sprintf(
								/* translators: %s: a folder name. */
								__( 'Archive %s?', 'cliezoo' ),
								folder.name
							)
						: sprintf(
								/* translators: %s: the name of the record being moved. */
								__( 'Move %s to the trash?', 'cliezoo' ),
								folder.name
							)
				}
				description={
					'archive' === putting
						? __(
								'Everything in it goes with it — the files, and the folders inside it — and it leaves the files list for everybody, not only you. You can bring it back from the Library, under Archive.',
								'cliezoo'
							)
						: __(
								'Everything in it goes with it — the files, and the folders inside it — and it leaves the files list for everybody, not only you. You can bring it back from the Library, under Trash, until the trash is emptied.',
								'cliezoo'
							)
				}
				confirmLabel={
					'archive' === putting
						? __( 'Archive it', 'cliezoo' )
						: __( 'Move it to the trash', 'cliezoo' )
				}
				onConfirm={ putAway }
			/>
		</>
	);
}

/**
 * A folder's name, looks and rules, in one form.
 *
 * @param root0         Props.
 * @param root0.uid     The folder.
 * @param root0.onClose Closes it.
 * @param root0.onSaved After it is saved.
 */
function FolderDetails( {
	uid,
	onClose,
	onSaved,
}: {
	uid: string;
	onClose: () => void;
	onSaved: () => void;
} ) {
	const folder = useQuery( {
		queryKey: [ 'files', 'folder', uid ],
		queryFn: async () =>
			(
				await api.get< Record< string, unknown > >(
					`/folders/${ uid }`
				)
			).data,
	} );
	return (
		<Dialog
			isOpen
			onOpenChange={ ( on ) => ! on && onClose() }
			title={ __( 'Folder', 'cliezoo' ) }
		>
			{ folder.isPending && <Skeleton className="h-64" /> }
			{ /* A dialog headed "Folder" with nothing in it, for ever, is
			     the failure drawn as emptiness. */ }
			{ folder.isError && (
				<ErrorState
					error={ folder.error }
					title={ __(
						'This folder could not be loaded.',
						'cliezoo'
					) }
					onRetry={ () => folder.refetch() }
				/>
			) }
			{ folder.data && (
				<RecordForm
					entity="folders"
					record={ folder.data }
					sections={ [
						{ fields: [ 'name', 'color', 'icon' ] },
						{ wide: true, fields: [ 'description' ] },
						{ fields: [ 'cover_file' ] },
						{
							title: __( 'Rules', 'cliezoo' ),
							description: __(
								'They hold for everything inside, however deep.',
								'cliezoo'
							),
							fields: [ 'comments_off', 'approvals_off' ],
						},
					] }
					onCancel={ onClose }
					onSaved={ onSaved }
				/>
			) }
		</Dialog>
	);
}
