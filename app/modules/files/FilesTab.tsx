/**
 * The files for one client or one project, on its own page.
 */
import { useNavigate, useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';
import { FileBrowser } from './FileBrowser';

export function FilesTab( {
	type,
	record,
}: {
	/** The entity this tab is on: clients or projects. */
	type: string;
	record: Record< string, unknown >;
} ) {
	const uid = String( record.uid );
	// The folder open is part of the address (/projects/{uid}/files/{folder}),
	// so a link to it opens it, and Back goes up a folder.
	const params = useParams( { strict: false } ) as {
		tab?: string;
		folder?: string;
	};
	const folder = 'files' === params.tab ? String( params.folder ?? '' ) : '';
	const navigate = useNavigate();
	const setFolder = ( next: string ) =>
		navigate( {
			to: ( next
				? `/${ type }/${ uid }/files/${ next }`
				: `/${ type }/${ uid }/files` ) as never,
			resetScroll: false,
		} );
	const scope = 'projects' === type ? { project: uid } : { client: uid };

	// The line above the files explains the client to whoever is reading
	// it — so when the reader is the client, it says something else.
	const intro = () => {
		if ( 'staff' !== getBoot().user?.kind ) {
			return 'projects' === type
				? __(
						'The files your team has shared with you on this project.',
						'cliezoo'
					)
				: __(
						'The files your team has shared with you that are not tied to one project.',
						'cliezoo'
					);
		}
		return 'projects' === type
			? __(
					'Everything this project has produced. A folder is shown to the client only once somebody shares it.',
					'cliezoo'
				)
			: __(
					'Everything for this client that is not tied to one project. A folder is shown to them only once somebody shares it.',
					'cliezoo'
				);
	};

	return (
		<FileBrowser
			scope={ scope }
			folder={ folder }
			onOpenFolder={ setFolder }
			intro={ intro() }
		/>
	);
}

export default FilesTab;
