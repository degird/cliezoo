/**
 * Settings → Email: who the workspace's mail comes from, and — if the
 * studio has a mail server of its own — what to send it through.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Select, Switch, TextField, toast } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';

/**
 * Connects to the mail server and signs in, without sending anything to
 * anybody: it says whether the address, the port, the encryption and the
 * password are right, which is every way this is normally got wrong.
 */
function TryServer() {
	const [ busy, setBusy ] = useState( false );
	const [ said, setSaid ] = useState< {
		ok: boolean;
		message: string;
	} | null >( null );
	return (
		<div className="space-y-2">
			<Button
				isPending={ busy }
				onPress={ async () => {
					setBusy( true );
					setSaid( null );
					try {
						setSaid(
							(
								await api.post< {
									ok: boolean;
									message: string;
								} >( '/settings/email/smtp/check' )
							).data
						);
					} catch ( error ) {
						toast.error( ( error as ApiError ).message );
					} finally {
						setBusy( false );
					}
				} }
			>
				{ __( 'Try the server', 'cliezoo' ) }
			</Button>
			{ !! said && (
				<Alert tone={ said.ok ? 'success' : 'error' }>
					{ said.message }
				</Alert>
			) }
			<p className="text-xs text-muted">
				{ __(
					'Save the page first: the test uses what is stored, not what is on screen.',
					'cliezoo'
				) }
			</p>
		</div>
	);
}

/**
 * Reads the sending domain's SPF, DMARC and DKIM records and says what is
 * missing — the usual reason mail from a new studio lands in spam.
 */
function CheckDomain() {
	const [ busy, setBusy ] = useState( false );
	const [ selector, setSelector ] = useState( '' );
	const [ said, setSaid ] = useState< {
		domain: string;
		state: string;
		checks: Record<
			string,
			{ state: string; say: string; record?: string }
		>;
	} | null >( null );
	const tones: Record< string, 'success' | 'error' | 'warning' > = {
		ok: 'success',
		problem: 'error',
	};
	const tone = ( state: string ) => tones[ state ] ?? 'warning';
	const names: Record< string, string > = {
		spf: 'SPF',
		dmarc: 'DMARC',
		dkim: 'DKIM',
	};
	return (
		<div className="space-y-2">
			<div className="flex flex-wrap items-end gap-2">
				<TextField
					label={ __( 'DKIM selector (optional)', 'cliezoo' ) }
					description={ __(
						'Your mail provider names it with the DKIM record, for example google or s1.',
						'cliezoo'
					) }
					value={ selector }
					onChange={ setSelector }
				/>
				<Button
					isPending={ busy }
					onPress={ async () => {
						setBusy( true );
						setSaid( null );
						try {
							setSaid(
								(
									await api.post<
										NonNullable< typeof said >
									>( '/settings/email/domain/check', {
										selector,
									} )
								).data
							);
						} catch ( error ) {
							toast.error( ( error as ApiError ).message );
						} finally {
							setBusy( false );
						}
					} }
				>
					{ __( 'Check the sending domain', 'cliezoo' ) }
				</Button>
			</div>
			{ !! said &&
				Object.entries( said.checks ).map( ( [ key, one ] ) => (
					<Alert key={ key } tone={ tone( one.state ) }>
						<strong>{ names[ key ] ?? key }</strong> { one.say }
					</Alert>
				) ) }
			<p className="text-xs text-muted">
				{ __(
					'Checks the domain of the From address saved above. A DNS lookup only: nothing is sent.',
					'cliezoo'
				) }
			</p>
		</div>
	);
}

export default function Email() {
	const s = useSettings( 'email' );
	const text = ( key: string ) => ( s.values[ key ] as string ) ?? '';
	return (
		<SettingsForm state={ s }>
			<Fieldset
				title={ __( 'Sender', 'cliezoo' ) }
				description={ __(
					'Who your emails come from. Clients see this name in their inbox.',
					'cliezoo'
				) }
			>
				<TextField
					label={ __( 'Sender name', 'cliezoo' ) }
					value={ text( 'sender_name' ) }
					onChange={ ( x ) => s.set( 'sender_name', x ) }
					errorMessage={ s.errors.sender_name }
				/>
				<TextField
					label={ __( 'From address', 'cliezoo' ) }
					type="email"
					description={ __(
						'Empty uses the site’s own address. Use an address on this site’s domain, or mail may be marked as spam.',
						'cliezoo'
					) }
					value={ text( 'from_address' ) }
					onChange={ ( x ) => s.set( 'from_address', x.trim() ) }
					errorMessage={ s.errors.from_address }
				/>
				<TextField
					label={ __( 'Replies go to', 'cliezoo' ) }
					type="email"
					description={ __(
						'Optional. Where a reply lands when it should not go to the from address.',
						'cliezoo'
					) }
					value={ text( 'reply_to' ) }
					onChange={ ( x ) => s.set( 'reply_to', x.trim() ) }
					errorMessage={ s.errors.reply_to }
				/>
			</Fieldset>
			<Fieldset
				title={ __( 'Your own mail server', 'cliezoo' ) }
				description={ __(
					'Off, mail goes out however this WordPress site sends it. On, it goes through the server you name here — your own mailbox, or a sending service — which is usually what makes mail from your domain arrive.',
					'cliezoo'
				) }
			>
				<Switch
					isSelected={ !! s.values.smtp_enabled }
					onChange={ ( x ) => s.set( 'smtp_enabled', x ) }
				>
					{ __( 'Send through my own mail server', 'cliezoo' ) }
				</Switch>
				{ !! s.values.smtp_enabled && (
					<>
						<TextField
							label={ __( 'Server', 'cliezoo' ) }
							description={ __(
								'For example smtp.gmail.com, or email-smtp.eu-west-1.amazonaws.com.',
								'cliezoo'
							) }
							value={ text( 'smtp_host' ) }
							onChange={ ( x ) => s.set( 'smtp_host', x.trim() ) }
							errorMessage={ s.errors.smtp_host }
						/>
						<TextField
							label={ __( 'Port', 'cliezoo' ) }
							type="number"
							value={ String( s.values.smtp_port ?? '' ) }
							onChange={ ( x ) =>
								s.set(
									'smtp_port',
									'' === x ? '' : Number( x )
								)
							}
							errorMessage={ s.errors.smtp_port }
						/>
						<Select
							label={ __( 'Encryption', 'cliezoo' ) }
							items={ [
								{
									id: 'tls',
									label: __(
										'STARTTLS (usually port 587)',
										'cliezoo'
									),
								},
								{
									id: 'ssl',
									label: __(
										'SSL/TLS (usually port 465)',
										'cliezoo'
									),
								},
								{
									id: 'none',
									label: __( 'None', 'cliezoo' ),
								},
							] }
							selectedKey={ text( 'smtp_secure' ) || 'none' }
							onSelectionChange={ ( key ) =>
								s.set(
									'smtp_secure',
									'none' === key ? '' : String( key )
								)
							}
						/>
						<TextField
							label={ __( 'Username', 'cliezoo' ) }
							description={ __(
								'Leave both this and the password empty for a server that needs no sign-in.',
								'cliezoo'
							) }
							value={ text( 'smtp_user' ) }
							onChange={ ( x ) => s.set( 'smtp_user', x.trim() ) }
							errorMessage={ s.errors.smtp_user }
						/>
						<TextField
							label={ __( 'Password', 'cliezoo' ) }
							type="password"
							description={ __(
								'Kept encrypted, and never sent back to this screen. Leave it as it is to keep the one that is stored.',
								'cliezoo'
							) }
							value={ text( 'smtp_pass' ) }
							onChange={ ( x ) => s.set( 'smtp_pass', x ) }
							errorMessage={ s.errors.smtp_pass }
						/>
						<TryServer />
					</>
				) }
			</Fieldset>
			{ ! s.values.smtp_enabled && (
				<Alert tone="info">
					{ __(
						'Emails are sent through this WordPress site. If they do not arrive, your own mail server above — or an SMTP plugin — usually fixes it; Setup health can send a test.',
						'cliezoo'
					) }
				</Alert>
			) }
			<Fieldset title={ __( 'Sending domain', 'cliezoo' ) }>
				<CheckDomain />
			</Fieldset>
		</SettingsForm>
	);
}
