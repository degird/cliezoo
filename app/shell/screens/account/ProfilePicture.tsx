/**
 * Account → your picture. It is stored as a private file attached to your
 * member record, like any other upload, and shown through signed links;
 * nothing goes to an outside picture service. Without one, the app draws
 * your initials.
 */
import { useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import {
	api,
	getBoot,
	sendBytes,
	useSession,
	type ApiError,
	type Boot,
	type SessionUser,
} from '@cliezoo/data';
import { Avatar, Button, toast } from '@cliezoo/ui';

/** Picture types a browser shows everywhere. */
const TYPES = [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ];
/** Anything bigger is a photo straight off a camera, not a profile picture. */
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * Why a file cannot be a profile picture, or undefined when it can.
 *
 * @param file      The chosen file.
 * @param file.type
 * @param file.size
 */
export function pictureProblem( file: {
	type: string;
	size: number;
} ): string | undefined {
	if ( ! TYPES.includes( file.type ) ) {
		return __( 'Choose a PNG, JPEG, WebP or GIF picture.', 'cliezoo' );
	}
	if ( file.size > MAX_BYTES ) {
		return __( 'Choose a picture under 5 MB.', 'cliezoo' );
	}
	return undefined;
}

async function refreshSession(): Promise< void > {
	const boot = ( await api.get< Boot >( '/session' ) ).data;
	useSession.getState().setBoot( { ...getBoot(), user: boot.user } );
}

export default function ProfilePicture( { user }: { user: SessionUser } ) {
	const input = useRef< HTMLInputElement >( null );
	const [ busy, setBusy ] = useState( false );

	const upload = async ( file: File ) => {
		const problem = pictureProblem( file );
		if ( problem ) {
			toast.error( problem );
			return;
		}
		setBusy( true );
		try {
			const session = (
				await api.post< {
					uid: string;
					chunk_size: number;
					parts: number;
				} >( '/files/upload-session', {
					name: file.name,
					size: file.size,
					mime: file.type,
					object_type: 'members',
					object: user.uid,
					purpose: 'avatar',
				} )
			).data;
			const size = Math.max( 1, session.chunk_size );
			for ( let part = 0; part < session.parts; part += 1 ) {
				await sendBytes(
					`/files/${ session.uid }/chunks/${ part }`,
					file.slice( part * size, ( part + 1 ) * size )
				);
			}
			await api.post( `/files/${ session.uid }/complete` );
			await api.patch( `/members/${ user.uid }`, {
				avatar_file: session.uid,
			} );
			await refreshSession();
			toast.success( __( 'Picture saved.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
			if ( input.current ) {
				input.current.value = '';
			}
		}
	};

	const remove = async () => {
		setBusy( true );
		try {
			await api.patch( `/members/${ user.uid }`, { avatar_file: null } );
			await refreshSession();
			toast.success( __( 'Picture removed.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="flex flex-wrap items-center gap-3">
			<Avatar
				name={ user.name }
				src={ user.avatar }
				color={ user.color }
				size="lg"
			/>
			<input
				ref={ input }
				type="file"
				accept={ TYPES.join( ',' ) }
				className="sr-only"
				tabIndex={ -1 }
				aria-hidden="true"
				onChange={ ( event ) => {
					const file = event.target.files?.[ 0 ];
					if ( file ) {
						upload( file );
					}
				} }
			/>
			<Button
				variant="secondary"
				size="sm"
				isPending={ busy }
				onPress={ () => input.current?.click() }
			>
				{ user.avatar
					? __( 'Change picture', 'cliezoo' )
					: __( 'Add a picture', 'cliezoo' ) }
			</Button>
			{ user.avatar && (
				<Button
					variant="ghost"
					size="sm"
					isDisabled={ busy }
					onPress={ remove }
				>
					{ __( 'Remove', 'cliezoo' ) }
				</Button>
			) }
		</div>
	);
}
