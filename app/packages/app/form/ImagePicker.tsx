/**
 * A picture for the workspace's look — a logo, an invoice header — chosen
 * from the site's media library, added to it here, or given by address.
 *
 * The media library rather than the workspace's own files, because these
 * pictures are shown to clients and have to be addresses anybody can load.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ImagePlus } from 'lucide-react';
import { api, useQuery, type ApiError } from '@cliezoo/data';
import {
	Button,
	Dialog,
	ErrorState,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';

type Picture = { id: number; url: string; thumb: string; title: string };

/**
 * The field.
 *
 * @param root0              Props.
 * @param root0.label        What it is.
 * @param root0.description  Words under it.
 * @param root0.value        The picture's address.
 * @param root0.onChange     Told the new address.
 * @param root0.errorMessage What is wrong with it.
 */
export function ImagePicker( {
	label,
	description,
	value,
	onChange,
	errorMessage,
}: {
	label: string;
	description?: string;
	value: string;
	onChange: ( url: string ) => void;
	errorMessage?: string;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const pictures = useQuery< { images: Picture[] } >( {
		queryKey: [ 'images' ],
		enabled: open,
		queryFn: async () =>
			( await api.get< { images: Picture[] } >( '/images' ) ).data,
	} );

	const add = async ( file: File ) => {
		setBusy( true );
		try {
			const data = await new Promise< string >( ( resolve, reject ) => {
				const reader = new FileReader();
				reader.onload = () => resolve( String( reader.result ) );
				reader.onerror = () => reject( reader.error );
				reader.readAsDataURL( file );
			} );
			const made = await api.post< { url: string } >( '/images', {
				name: file.name,
				data,
			} );
			onChange( made.data.url );
			setOpen( false );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="flex flex-col gap-2">
			<div className="flex flex-wrap items-end gap-2">
				<div className="min-w-0 flex-1">
					<TextField
						label={ label }
						description={ description }
						value={ value }
						onChange={ onChange }
						errorMessage={ errorMessage }
					/>
				</div>
				<Button
					icon={ <ImagePlus size={ 15 } aria-hidden="true" /> }
					onPress={ () => setOpen( true ) }
				>
					{ __( 'Choose a picture', 'cliezoo' ) }
				</Button>
			</div>
			{ !! value && (
				<img
					src={ value }
					alt=""
					className="max-h-16 max-w-48 rounded border border-line bg-raised object-contain p-1"
				/>
			) }

			<Dialog
				isOpen={ open }
				onOpenChange={ setOpen }
				size="lg"
				title={ label }
				description={ __(
					'From the site’s media library, where a client can load it. PNG, JPEG, GIF or WebP, up to 2 MB.',
					'cliezoo'
				) }
			>
				<div className="flex flex-col gap-3">
					<div>
						<label
							htmlFor="cliezoo-image-picker-file"
							className="text-sm font-medium text-fg"
						>
							{ __( 'Add a picture', 'cliezoo' ) }
						</label>
						<input
							id="cliezoo-image-picker-file"
							type="file"
							accept="image/png,image/jpeg,image/gif,image/webp"
							disabled={ busy }
							className="mt-1 block w-full text-sm text-fg"
							onChange={ ( event ) => {
								const file = event.target.files?.[ 0 ];
								if ( file ) {
									add( file );
								}
							} }
						/>
					</div>

					{ pictures.isPending && <Skeleton className="h-32" /> }
					{ pictures.isError && (
						<ErrorState
							error={ pictures.error }
							onRetry={ () => pictures.refetch() }
						/>
					) }
					{ !! pictures.data && ! pictures.data.images.length && (
						<p className="text-sm text-muted">
							{ __(
								'The media library has no pictures yet. Add one above.',
								'cliezoo'
							) }
						</p>
					) }
					<ul
						aria-label={ __( 'Pictures already here', 'cliezoo' ) }
						className="grid grid-cols-3 gap-2 sm:grid-cols-5"
					>
						{ ( pictures.data?.images ?? [] ).map( ( one ) => (
							<li key={ one.id }>
								<button
									type="button"
									aria-pressed={ one.url === value }
									aria-label={
										one.title ||
										__( 'A picture', 'cliezoo' )
									}
									onClick={ () => {
										onChange( one.url );
										setOpen( false );
									} }
									className="flex aspect-square w-full items-center justify-center overflow-hidden rounded border border-line bg-raised p-1 hover:border-accent aria-pressed:border-accent"
								>
									<img
										src={ one.thumb }
										alt=""
										className="max-h-full max-w-full object-contain"
									/>
								</button>
							</li>
						) ) }
					</ul>
				</div>
			</Dialog>
		</div>
	);
}
