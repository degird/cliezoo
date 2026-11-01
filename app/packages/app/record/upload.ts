/**
 * Putting a file on a record.
 *
 * The same three steps the Files area takes — open a session, send the
 * bytes, finish — without the part that can be stopped and picked up
 * again: somebody attaching a brief to a card or a picture to a message is
 * not dropping a two-gigabyte video, and a second copy of a resumable
 * uploader is a second thing to keep right. The attachments panel and the
 * composer both come through here.
 */
import { api, sendBytes } from '@cliezoo/data';

export interface Uploaded {
	uid: string;
	name: string;
	mime?: string | null;
	size?: number | null;
	kind?: string;
}

/**
 * Uploads one file onto a record.
 *
 * @param type       Entity name of the record ('tasks', 'conversations').
 * @param uid        The record.
 * @param file       What to send.
 * @param onProgress Told how far through it is, 0 to 1.
 * @param purpose    What it is for: "message" for a composer attachment,
 *                   which stays with the team until the message is sent.
 */
export async function uploadTo(
	type: string,
	uid: string,
	file: File,
	onProgress?: ( done: number ) => void,
	purpose = 'file'
): Promise< Uploaded > {
	const session = (
		await api.post< { uid: string; parts: number; chunk_size: number } >(
			'/files/upload-session',
			{
				object_type: type,
				object: uid,
				name: file.name,
				size: file.size,
				mime: file.type,
				purpose,
			}
		)
	).data;
	const chunk = Math.max( 1, session.chunk_size );
	const parts = Math.max( 1, session.parts );
	// Parts are numbered from 0, as the server counts them.
	for ( let part = 0; part < parts; part++ ) {
		await sendBytes(
			`/files/${ session.uid }/chunks/${ part }`,
			file.slice( part * chunk, ( part + 1 ) * chunk )
		);
		onProgress?.( ( part + 1 ) / parts );
	}
	return ( await api.post< Uploaded >( `/files/${ session.uid }/complete` ) )
		.data;
}
