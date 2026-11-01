/**
 * The files on a record.
 *
 * Uploading against a record has worked since the upload session was
 * written — object_type and object are in its input — and nothing could
 * ask what came of it, so no module had an attachments panel. This is the
 * other half, and like the notes panel it is about records in general: a
 * task today, a deal or a document tomorrow, with no module knowing about
 * any other.
 */
import { useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	Download,
	ExternalLink,
	Image as ImageIcon,
	Paperclip,
	Trash2,
	Upload,
} from 'lucide-react';
import {
	api,
	formatBytes,
	safeHref,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import { Button, ErrorState, IconButton, Skeleton, toast } from '@cliezoo/ui';
import { uploadTo } from './upload';

interface Attachment {
	uid: string;
	name: string;
	kind?: string;
	url?: string | null;
	size?: number | null;
	extension?: string | null;
	mime?: string | null;
}

/** How many attachments one panel shows. Past this it is the Files area. */
const LIMIT = 50;

export function AttachmentsPanel( {
	type,
	uid,
	canWrite = true,
	cover,
	onCover,
}: {
	/** Entity name of the record: 'tasks', 'deals'. */
	type: string;
	uid: string;
	/** Whether this person may add and remove them. */
	canWrite?: boolean;
	/** The file uid currently used as the record's picture, if any. */
	cover?: string;
	/** Offered on pictures when the record has somewhere to show one. */
	onCover?: ( file: string ) => void;
} ) {
	const queries = useQueryClient();
	const picker = useRef< HTMLInputElement >( null );
	const [ busy, setBusy ] = useState( '' );

	const files = useQuery( {
		queryKey: [ 'attachments', type, uid ],
		queryFn: async () =>
			(
				await api.get< Attachment[] >( '/attachments', {
					object_type: type,
					object: uid,
					limit: LIMIT,
				} )
			).data,
	} );

	const refresh = () =>
		queries.invalidateQueries( { queryKey: [ 'attachments', type, uid ] } );

	/**
	 * Sends one file.
	 *
	 * @param file The chosen file.
	 */
	const send = async ( file: File ) => {
		setBusy( file.name );
		try {
			await uploadTo( type, uid, file );
			refresh();
			toast.success(
				sprintf(
					/* translators: %s: the file's name. */
					__( '%s is on it.', 'cliezoo' ),
					file.name
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( '' );
		}
	};

	const open = async ( file: Attachment ) => {
		if ( 'link' === file.kind && file.url ) {
			// An attachment that is a link opens where it says, so long as
			// that is somewhere a window may be opened.
			const address = safeHref( file.url );
			if ( ! address ) {
				toast.error( __( 'That link cannot be opened.', 'cliezoo' ) );
				return;
			}
			window.open( address, '_blank', 'noopener,noreferrer' );
			return;
		}
		try {
			const link = await api.get< { url: string } >(
				`/files/${ file.uid }/link`
			);
			window.location.assign( link.data.url );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<section aria-labelledby="record-files" className="space-y-2">
			<div className="flex items-center justify-between gap-2">
				<h3 id="record-files" className="text-sm font-semibold text-fg">
					{ __( 'Files', 'cliezoo' ) }
				</h3>
				{ canWrite && (
					<Button
						size="sm"
						variant="ghost"
						isPending={ '' !== busy }
						icon={ <Upload size={ 14 } aria-hidden="true" /> }
						onPress={ () => picker.current?.click() }
					>
						{ __( 'Attach a file', 'cliezoo' ) }
					</Button>
				) }
			</div>

			<input
				ref={ picker }
				type="file"
				className="hidden"
				onChange={ ( event ) => {
					const chosen = event.target.files?.[ 0 ];
					event.target.value = '';
					if ( chosen ) {
						send( chosen );
					}
				} }
			/>

			{ files.isPending && <Skeleton className="h-12" /> }
			{ files.isError && (
				<ErrorState
					error={ files.error }
					onRetry={ () => files.refetch() }
				/>
			) }

			{ files.isSuccess && ! files.data.length && (
				<p className="text-sm text-muted">
					{ __( 'Nothing attached yet.', 'cliezoo' ) }
				</p>
			) }

			<ul className="space-y-1">
				{ ( files.data ?? [] ).map( ( file ) => (
					<li
						key={ file.uid }
						className="flex items-center gap-2 rounded border border-line px-2 py-1.5 text-sm"
					>
						<Paperclip
							size={ 14 }
							aria-hidden="true"
							className="shrink-0 text-subtle"
						/>
						<span className="min-w-0 flex-1 truncate text-fg">
							{ file.name }
						</span>
						{ !! file.size && (
							<span className="shrink-0 text-xs text-subtle">
								{ formatBytes( file.size ) }
							</span>
						) }
						{ !! onCover &&
							canWrite &&
							file.mime?.startsWith( 'image/' ) && (
								<IconButton
									size="sm"
									variant="ghost"
									label={
										cover === file.uid
											? __(
													'Stop using it as the picture',
													'cliezoo'
												)
											: __(
													'Use it as the picture',
													'cliezoo'
												)
									}
									icon={
										<ImageIcon
											size={ 14 }
											aria-hidden="true"
											className={
												cover === file.uid
													? 'text-accent-fg'
													: undefined
											}
										/>
									}
									onPress={ () =>
										onCover(
											cover === file.uid ? '' : file.uid
										)
									}
								/>
							) }
						<IconButton
							size="sm"
							variant="ghost"
							label={
								'link' === file.kind
									? __( 'Open it', 'cliezoo' )
									: __( 'Download it', 'cliezoo' )
							}
							icon={
								'link' === file.kind ? (
									<ExternalLink
										size={ 14 }
										aria-hidden="true"
									/>
								) : (
									<Download size={ 14 } aria-hidden="true" />
								)
							}
							onPress={ () => open( file ) }
						/>
						{ canWrite && (
							<IconButton
								size="sm"
								variant="ghost"
								label={ __( 'Take it off', 'cliezoo' ) }
								icon={
									<Trash2 size={ 14 } aria-hidden="true" />
								}
								onPress={ async () => {
									try {
										await api.delete(
											`/files/${ file.uid }`
										);
										refresh();
										toast.success(
											__(
												'Moved to the trash.',
												'cliezoo'
											)
										);
									} catch ( error ) {
										toast.error(
											( error as ApiError ).message
										);
									}
								} }
							/>
						) }
					</li>
				) ) }
			</ul>
		</section>
	);
}
