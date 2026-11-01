/**
 * Top bar: menu (mobile), search / command palette, + New, timer, inbox,
 * Ask AI (slot), account menu.
 */
import { Link, useNavigate } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import {
	Bell,
	Compass,
	Languages,
	LifeBuoy,
	LogOut,
	Menu as MenuIcon,
	Monitor,
	Moon,
	Plus,
	Search,
	Sun,
	User,
} from 'lucide-react';
import {
	api,
	getBoot,
	useQuery,
	useSavePreferences,
	usePreferences,
	useUser,
	type ThemePreference,
} from '@cliezoo/data';
import {
	Avatar,
	Button,
	IconButton,
	Kbd,
	Menu,
	MenuItem,
	MenuSection,
	MenuTrigger,
	cx,
	toast,
} from '@cliezoo/ui';
import { forgetDrafts } from '../packages/app/form/draft';
import { Slot, useSlotHasFills } from '../packages/app/slot';
import { openPalette } from './palette';
import { HELP_URL, startTour } from './tour-state';

const isMac =
	typeof navigator !== 'undefined' &&
	/Mac|iPhone|iPad/.test( navigator.platform );
export const MOD = isMac ? '⌘' : 'Ctrl';

function InboxButton() {
	const counts = useQuery( {
		queryKey: [ 'notifications', 'counts' ],
		queryFn: async () =>
			(
				await api.get< Record< string, number > >(
					'/notifications/counts'
				)
			).data,
		refetchInterval: () => ( document.hidden ? false : 30_000 ),
		staleTime: 15_000,
	} );
	const unread = counts.data?.all ?? 0;
	const label = unread
		? `${ __( 'Inbox', 'cliezoo' ) } (${ unread })`
		: __( 'Inbox', 'cliezoo' );
	return (
		<Link
			to="/inbox"
			aria-label={ label }
			title={ label }
			className="relative inline-flex size-9 items-center justify-center rounded-md text-fg hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
		>
			<Bell size={ 18 } aria-hidden="true" />
			{ unread > 0 && (
				<span
					aria-hidden="true"
					className="absolute end-1 top-1 min-w-4 rounded-full bg-danger px-1 text-center text-[0.625rem] font-bold leading-4 text-white dark:text-bg"
				>
					{ unread > 99 ? '99+' : unread }
				</span>
			) }
		</Link>
	);
}

/**
 * A language's own name ("Deutsch"), or its code when the browser cannot say.
 *
 * @param code Locale code ("de_DE").
 */
function languageName( code: string ): string {
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

function AccountMenu() {
	const user = useUser();
	const prefs = usePreferences();
	const save = useSavePreferences();
	const navigate = useNavigate();
	if ( ! user ) {
		return null;
	}
	const themes: Array< {
		id: ThemePreference;
		label: string;
		icon: JSX.Element;
	} > = [
		{
			id: 'light',
			label: __( 'Light', 'cliezoo' ),
			icon: <Sun size={ 16 } />,
		},
		{
			id: 'dark',
			label: __( 'Dark', 'cliezoo' ),
			icon: <Moon size={ 16 } />,
		},
		{
			id: 'system',
			label: __( 'System', 'cliezoo' ),
			icon: <Monitor size={ 16 } />,
		},
	];
	const logout = async () => {
		try {
			await api.post( '/auth/logout' );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
			return;
		}
		// Unsent typing is kept in this browser for a week. Signing out is
		// where it goes, so a shared computer hands nothing to the next
		// person. Only after the session really ended, never on a failure.
		forgetDrafts();
		window.location.assign( getBoot().routes.appUrl );
	};
	// Only the languages this site has; with one there is nothing to choose.
	const languages = getBoot().workspace.languages ?? [];
	const setLanguage = async ( code: string ) => {
		try {
			await api.patch( `/members/${ user.uid }`, {
				locale: '' === code ? null : code,
			} );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
			return;
		}
		// The words on screen come with the page, so a new language needs a
		// fresh load.
		window.location.reload();
	};
	return (
		<MenuTrigger>
			<Button
				variant="ghost"
				className="!h-9 !px-1"
				data-tour="account"
				aria-label={ __( 'Your account', 'cliezoo' ) }
			>
				<Avatar
					name={ user.name }
					src={ user.avatar }
					color={ user.color }
					size="sm"
				/>
			</Button>
			<Menu
				onAction={ ( key ) => {
					if ( key === 'account' ) {
						navigate( { to: '/account' } );
					} else if ( key === 'logout' ) {
						logout();
					} else if ( key === 'tour' ) {
						startTour();
					} else if ( key === 'help' ) {
						window.open( HELP_URL, '_blank', 'noopener' );
					} else if ( String( key ).startsWith( 'lang:' ) ) {
						setLanguage( String( key ).slice( 5 ) );
					} else if ( String( key ).startsWith( 'theme:' ) ) {
						save.mutate( {
							theme: String( key ).slice( 6 ) as ThemePreference,
						} );
					}
				} }
			>
				<MenuSection id="you" title={ user.name }>
					<MenuItem
						id="account"
						icon={ <User size={ 16 } /> }
						description={ user.roleLabel }
					>
						{ __( 'Account', 'cliezoo' ) }
					</MenuItem>
				</MenuSection>
				<MenuSection
					id="appearance"
					title={ __( 'Appearance', 'cliezoo' ) }
				>
					{ themes.map( ( theme ) => (
						<MenuItem
							key={ theme.id }
							id={ `theme:${ theme.id }` }
							icon={ theme.icon }
							description={
								prefs.theme === theme.id
									? __( 'Current', 'cliezoo' )
									: undefined
							}
						>
							{ theme.label }
						</MenuItem>
					) ) }
				</MenuSection>
				{ languages.length > 1 && (
					<MenuSection
						id="language"
						title={ __( 'Language', 'cliezoo' ) }
					>
						{ [ '', ...languages ].map( ( code ) => (
							<MenuItem
								key={ code || 'default' }
								id={ `lang:${ code }` }
								icon={ <Languages size={ 16 } /> }
								description={
									( user.locale ?? '' ) === code
										? __( 'Current', 'cliezoo' )
										: undefined
								}
							>
								{ code
									? languageName( code )
									: __( 'Workspace default', 'cliezoo' ) }
							</MenuItem>
						) ) }
					</MenuSection>
				) }
				<MenuSection id="help" title={ __( 'Help', 'cliezoo' ) }>
					<MenuItem id="tour" icon={ <Compass size={ 16 } /> }>
						{ __( 'Show me around', 'cliezoo' ) }
					</MenuItem>
					{ /* The help centre is on cliezoo.com: it is for the team,
					     not a link to put in front of a business's clients. */ }
					{ 'client' !== getBoot().user?.kind && (
						<MenuItem id="help" icon={ <LifeBuoy size={ 16 } /> }>
							{ __( 'Help centre', 'cliezoo' ) }
						</MenuItem>
					) }
				</MenuSection>
				<MenuSection id="session">
					<MenuItem id="logout" icon={ <LogOut size={ 16 } /> }>
						{ __( 'Sign out', 'cliezoo' ) }
					</MenuItem>
				</MenuSection>
			</Menu>
		</MenuTrigger>
	);
}

export function TopBar( { onMenu }: { onMenu: () => void } ) {
	const hasNew = useSlotHasFills( 'topbar.new' );
	return (
		<header className="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 overflow-hidden border-b border-line bg-bg/95 px-3 backdrop-blur sm:px-4">
			<IconButton
				className="lg:hidden"
				label={ __( 'Open navigation', 'cliezoo' ) }
				icon={ <MenuIcon size={ 18 } aria-hidden="true" /> }
				onPress={ onMenu }
			/>
			<button
				type="button"
				data-tour="search"
				onClick={ () => openPalette( 'search' ) }
				className={ cx(
					'flex h-9 min-w-0 flex-1 items-center gap-2 rounded-md border border-line bg-surface px-3 text-sm text-subtle sm:max-w-md',
					'hover:border-line-strong focus-visible:outline-2 focus-visible:outline-focus'
				) }
			>
				<Search size={ 16 } aria-hidden="true" />
				<span className="flex-1 truncate text-start">
					{ __( 'Search or jump to…', 'cliezoo' ) }
				</span>
				<span className="hidden gap-0.5 sm:flex" aria-hidden="true">
					<Kbd>{ MOD }</Kbd>
					<Kbd>K</Kbd>
				</span>
			</button>
			<div className="ms-auto flex min-w-0 items-center gap-1">
				{ hasNew ? (
					<Slot name="topbar.new" />
				) : (
					<IconButton
						data-tour="new"
						label={ __( 'Create new', 'cliezoo' ) }
						icon={ <Plus size={ 18 } aria-hidden="true" /> }
						variant="primary"
						onPress={ () => openPalette( 'new' ) }
					/>
				) }
				<Slot name="topbar.timer" badge={ false } />
				{ /* A badge with words is too wide for a phone's bar. */ }
				<span className="hidden sm:contents">
					<Slot name="topbar.ai" />
				</span>
				<InboxButton />
				<AccountMenu />
			</div>
		</header>
	);
}
