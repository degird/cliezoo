/**
 * Account → Profile: name, handle, job title, phone, colour and personal
 * time zone / date and time formats (empty = workspace default).
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { UserRound } from 'lucide-react';
import {
	api,
	getBoot,
	useQuery,
	useSession,
	type ApiError,
} from '@cliezoo/data';
import {
	Button,
	ErrorState,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';

type Member = Record< string, unknown > & { uid: string };

const KEYS = [
	'name',
	'handle',
	'title',
	'phone',
	'color',
	'timezone',
	'date_format',
	'time_format',
	'locale',
	'week_start',
];

/**
 * A language's own name ("Deutsch"), or its code when the browser cannot say.
 *
 * @param code Locale code ("de_DE").
 */
export function languageName( code: string ): string {
	try {
		const tag = code.replace( '_', '-' );
		return (
			new Intl.DisplayNames( [ tag ], { type: 'language' } ).of( tag ) ??
			code
		);
	} catch {
		return code;
	}
}

/**
 * The days a week can start on, named in the reader's language.
 */
function weekdays(): Array< { id: string; label: string } > {
	// 2023-01-01 was a Sunday.
	return [ 0, 1, 2, 3, 4, 5, 6 ].map( ( day ) => ( {
		id: String( day ),
		label: new Intl.DateTimeFormat( undefined, {
			weekday: 'long',
			timeZone: 'UTC',
		} ).format( new Date( Date.UTC( 2023, 0, 1 + day ) ) ),
	} ) );
}

function zones(): string[] {
	try {
		return (
			Intl as unknown as { supportedValuesOf: ( k: string ) => string[] }
		 ).supportedValuesOf( 'timeZone' );
	} catch {
		return [ 'UTC' ];
	}
}

export default function Profile( { uid }: { uid: string } ) {
	const member = useQuery( {
		queryKey: [ 'members', 'one', uid ],
		queryFn: async () =>
			( await api.get< Member >( `/members/${ uid }` ) ).data,
	} );
	const [ draft, setDraft ] = useState< Record< string, string > >( {} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( member.data ) {
			setDraft(
				Object.fromEntries(
					KEYS.map( ( key ) => [
						key,
						null === member.data[ key ] ||
						undefined === member.data[ key ]
							? ''
							: String( member.data[ key ] ),
					] )
				)
			);
		}
	}, [ member.data ] );
	// A Skeleton is aria-hidden: a failure here pulses for ever and tells
	// nobody anything.
	if ( member.isError ) {
		return (
			<ErrorState
				error={ member.error }
				title={ __( 'Your profile could not be loaded.', 'cliezoo' ) }
				onRetry={ () => member.refetch() }
			/>
		);
	}
	if ( ! member.data ) {
		return <Skeleton className="h-48" />;
	}
	const set = ( key: string, value: string ) =>
		setDraft( { ...draft, [ key ]: value } );
	const save = async () => {
		setBusy( true );
		setErrors( {} );
		try {
			const payload = Object.fromEntries(
				Object.entries( draft ).map( ( [ key, value ] ) => [
					key,
					value === '' ? null : value,
				] )
			);
			await api.patch( `/members/${ uid }`, {
				...payload,
				week_start:
					'' === ( draft.week_start ?? '' )
						? null
						: Number( draft.week_start ),
				name: draft.name,
			} );
			// A new language needs the page's words in it, which only a
			// fresh load brings.
			if (
				( draft.locale ?? '' ) !== String( member.data?.locale ?? '' )
			) {
				window.location.reload();
				return;
			}
			const boot = getBoot();
			if ( boot.user ) {
				useSession.getState().setBoot( {
					...boot,
					user: {
						...boot.user,
						name: draft.name,
						timezone: draft.timezone,
						color: draft.color,
					},
				} );
			}
			member.refetch();
			toast.success( __( 'Profile saved.', 'cliezoo' ) );
		} catch ( error ) {
			setErrors( ( error as ApiError ).fields );
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};
	const workspace = __( 'Workspace default', 'cliezoo' );
	return (
		<section
			aria-labelledby="account-profile"
			className="rounded-lg border border-line bg-raised p-5"
		>
			<h2
				id="account-profile"
				className="flex items-center gap-2 text-base font-semibold"
			>
				<UserRound
					size={ 18 }
					className="text-accent-fg"
					aria-hidden="true"
				/>
				{ __( 'Profile', 'cliezoo' ) }
			</h2>
			<div className="mt-4 grid gap-4 sm:grid-cols-2">
				<TextField
					label={ __( 'Name', 'cliezoo' ) }
					value={ draft.name }
					onChange={ ( v ) => set( 'name', v ) }
					isRequired
					errorMessage={ errors.name }
					autoComplete="name"
				/>
				<TextField
					label={ __( 'Handle', 'cliezoo' ) }
					description={ __(
						'People mention you with @handle.',
						'cliezoo'
					) }
					value={ draft.handle }
					onChange={ ( v ) =>
						set(
							'handle',
							v.replace( /[^a-z0-9._-]/gi, '' ).toLowerCase()
						)
					}
					errorMessage={ errors.handle }
				/>
				<TextField
					label={ __( 'Job title', 'cliezoo' ) }
					value={ draft.title }
					onChange={ ( v ) => set( 'title', v ) }
					errorMessage={ errors.title }
				/>
				<TextField
					label={ __( 'Phone', 'cliezoo' ) }
					type="tel"
					value={ draft.phone }
					onChange={ ( v ) => set( 'phone', v ) }
					errorMessage={ errors.phone }
					autoComplete="tel"
				/>
				<TextField
					label={ __( 'Colour', 'cliezoo' ) }
					type="color"
					value={ draft.color || '#0458fb' }
					onChange={ ( v ) => set( 'color', v ) }
				/>
				<TextField
					label={ __( 'Email', 'cliezoo' ) }
					value={ String( member.data.email ?? '' ) }
					isReadOnly
					description={ __(
						'Ask an admin to change your email address.',
						'cliezoo'
					) }
				/>
				<Select
					label={ __( 'Time zone', 'cliezoo' ) }
					items={ [
						{
							id: '',
							label: `${ workspace } (${ getBoot().workspace.timezone })`,
						},
						...zones().map( ( z ) => ( {
							id: z,
							label: z.replace( /_/g, ' ' ),
						} ) ),
					] }
					selectedKey={ draft.timezone }
					onSelectionChange={ ( key ) =>
						set( 'timezone', String( key ) )
					}
				/>
				<Select
					label={ __( 'Date format', 'cliezoo' ) }
					items={ [
						{ id: '', label: workspace },
						...[
							'MMM D, YYYY',
							'D MMM YYYY',
							'MM/DD/YYYY',
							'DD/MM/YYYY',
							'YYYY-MM-DD',
						].map( ( f ) => ( { id: f, label: f } ) ),
					] }
					selectedKey={ draft.date_format }
					onSelectionChange={ ( key ) =>
						set( 'date_format', String( key ) )
					}
				/>
				<Select
					label={ __( 'Language', 'cliezoo' ) }
					items={ [
						{ id: '', label: workspace },
						...( getBoot().workspace.languages ?? [ 'en_US' ] ).map(
							( code ) => ( {
								id: code,
								label: languageName( code ),
							} )
						),
					] }
					selectedKey={ draft.locale ?? '' }
					onSelectionChange={ ( key ) =>
						set( 'locale', String( key ) )
					}
				/>
				<Select
					label={ __( 'First day of the week', 'cliezoo' ) }
					items={ [ { id: '', label: workspace }, ...weekdays() ] }
					selectedKey={ draft.week_start ?? '' }
					onSelectionChange={ ( key ) =>
						set( 'week_start', String( key ) )
					}
				/>
				<Select
					label={ __( 'Time format', 'cliezoo' ) }
					items={ [
						{ id: '', label: workspace },
						{
							id: '12',
							label: __( '12-hour (2:30 PM)', 'cliezoo' ),
						},
						{ id: '24', label: __( '24-hour (14:30)', 'cliezoo' ) },
					] }
					selectedKey={ draft.time_format }
					onSelectionChange={ ( key ) =>
						set( 'time_format', String( key ) )
					}
				/>
			</div>
			<div className="mt-4 flex justify-end">
				<Button
					variant="primary"
					isPending={ busy }
					onPress={ save }
					isDisabled={ ! draft.name?.trim() }
				>
					{ __( 'Save profile', 'cliezoo' ) }
				</Button>
			</div>
		</section>
	);
}
