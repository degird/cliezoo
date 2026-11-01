/**
 * The files attached to one record, on that record's own screen.
 *
 * Uploading against a record has worked since the upload session was
 * written, and asking what came of it has worked since `/attachments` was
 * added — but no screen did either, so an invoice with a signed order form
 * behind it had nowhere to keep it and the file went in an email instead.
 *
 * A panel rather than a page: what is attached to a bill is a short list
 * somebody glances at, and the file library is where files are managed.
 */
import { useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Paperclip, Download, Trash2, Upload } from 'lucide-react';
import {
	api,
	formatBytes,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Button,
	ConfirmDialog,
	ErrorState,
	IconButton,
	Skeleton,
	toast,
} from '@cliezoo/ui';
import { download, type FileRow } from './FileList';
import { useUpload } from './useUpload';

/**
 * What one upload in progress says about itself.
 *
 * @param one         The upload.
 * @param one.error
 * @param one.done
 * @param one.percent
 */
function said( one: {
	error?: string;
	done?: boolean;
	percent: number | null;
} ): string {
	if ( one.error ) {
		return one.error;
	}
	if ( one.done ) {
		return __( 'Done', 'cliezoo' );
	}
	return sprintf(
		/* translators: %d: per cent uploaded. */
		__( '%d%%', 'cliezoo' ),
		one.percent ?? 0
	);
}

/**
 * The panel.
 *
 * @param root0          Props.
 * @param root0.type     The entity the files are attached to, e.g. "invoices".
 * @param root0.uid      That record's uid.
 * @param root0.label    What the panel is called.
 * @param root0.hint     A line under the heading, where one helps.
 * @param root0.readOnly Whether attaching and removing are offered.
 */
export function RecordAttachments( {
	type,
	uid,
	label,
	hint,
	readOnly,
}: {
	type: string;
	uid: string;
	label?: string;
	hint?: string;
	readOnly?: boolean;
} ) {
	const queries = useQueryClient();
	const picker = useRef< HTMLInputElement >( null );
	const [ removing, setRemoving ] = useState< FileRow | null >( null );

	const attached = useQuery< { data: FileRow[] } >( {
		queryKey: [ 'attachments', type, uid ],
		enabled: !! uid,
		queryFn: async () =>
			await api.get< FileRow[] >( '/attachments', {
				object_type: type,
				object: uid,
				limit: 50,
			} ),
	} );

	const again = () => {
		attached.refetch();
		queries.invalidateQueries( { queryKey: [ 'files' ] } );
	};

	// The server takes the record as the target, so a file uploaded here
	// arrives attached rather than being filed and then linked.
	const { uploads, add } = useUpload(
		{ object_type: type, object: uid },
		again
	);

	const rows = attached.data?.data ?? [];

	const remove = async ( file: FileRow ) => {
		setRemoving( null );
		try {
			await api.delete( `/files/${ file.uid }` );
			toast.success( __( 'Taken off.', 'cliezoo' ) );
			again();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<section
			aria-label={ label ?? __( 'Attachments', 'cliezoo' ) }
			className="rounded-lg border border-line bg-surface p-3"
		>
			<div className="mb-2 flex items-center justify-between gap-2">
				<h3 className="flex items-center gap-2 text-sm font-semibold text-fg">
					<Paperclip size={ 14 } aria-hidden="true" />
					{ label ?? __( 'Attachments', 'cliezoo' ) }
				</h3>
				{ ! readOnly && (
					<>
						<Button
							size="sm"
							variant="ghost"
							icon={ <Upload size={ 14 } aria-hidden="true" /> }
							onPress={ () => picker.current?.click() }
						>
							{ __( 'Attach a file', 'cliezoo' ) }
						</Button>
						{ /* The input itself is never shown: it is the file
						     dialog, and the button above is what people see. */ }
						<input
							ref={ picker }
							type="file"
							multiple
							className="hidden"
							onChange={ ( event ) => {
								const chosen = Array.from(
									event.target.files ?? []
								);
								if ( chosen.length ) {
									add( chosen );
								}
								event.target.value = '';
							} }
						/>
					</>
				) }
			</div>

			{ !! hint && <p className="mb-2 text-xs text-muted">{ hint }</p> }

			{ attached.isPending && <Skeleton className="h-10" /> }
			{ attached.isError && (
				<ErrorState
					error={ attached.error }
					onRetry={ () => attached.refetch() }
				/>
			) }

			{ ! attached.isPending && ! attached.isError && ! rows.length && (
				<p className="text-sm text-muted">
					{ __( 'Nothing attached yet.', 'cliezoo' ) }
				</p>
			) }

			{ !! rows.length && (
				<ul className="flex flex-col gap-1">
					{ rows.map( ( file ) => (
						<li
							key={ file.uid }
							className="flex items-center gap-2 rounded border border-line px-2 py-1.5"
						>
							<span className="flex-1 truncate text-sm">
								{ file.name }
							</span>
							{ 'number' === typeof file.size && (
								<span className="text-xs tabular-nums text-muted">
									{ formatBytes( file.size ) }
								</span>
							) }
							<IconButton
								size="sm"
								variant="ghost"
								label={ sprintf(
									/* translators: %s: a file's name. */
									__( 'Download %s', 'cliezoo' ),
									file.name
								) }
								icon={
									<Download size={ 14 } aria-hidden="true" />
								}
								onPress={ () => download( file ) }
							/>
							{ ! readOnly && (
								<IconButton
									size="sm"
									variant="ghost"
									label={ sprintf(
										/* translators: %s: a file's name. */
										__( 'Take %s off', 'cliezoo' ),
										file.name
									) }
									icon={
										<Trash2
											size={ 14 }
											aria-hidden="true"
										/>
									}
									onPress={ () => setRemoving( file ) }
								/>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ !! uploads.length && (
				<ul className="mt-2 flex flex-col gap-1" aria-live="polite">
					{ uploads.map( ( one ) => (
						<li
							key={ one.id }
							className="flex items-center gap-2 text-xs text-muted"
						>
							<span className="flex-1 truncate">
								{ one.name }
							</span>
							<span className="tabular-nums">
								{ said( one ) }
							</span>
						</li>
					) ) }
				</ul>
			) }

			<ConfirmDialog
				isOpen={ !! removing }
				onOpenChange={ ( open: boolean ) =>
					! open && setRemoving( null )
				}
				title={ __( 'Take this file off?', 'cliezoo' ) }
				confirmLabel={ __( 'Take it off', 'cliezoo' ) }
				onConfirm={ () => removing && remove( removing ) }
			>
				{ sprintf(
					/* translators: %s: a file's name. */
					__(
						'%s goes to the trash, so this can be undone.',
						'cliezoo'
					),
					removing?.name ?? ''
				) }
			</ConfirmDialog>
		</section>
	);
}
