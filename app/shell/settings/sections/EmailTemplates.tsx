/**
 * Settings → Email templates: every email the workspace sends.
 *
 * Edited beside a preview, because the only useful question about an email
 * is what it will look like when it lands. The preview is the server's own
 * rendering with sample data rather than the browser's guess, so what is
 * on screen is what will be sent.
 *
 * Nothing is lost by editing: "Put it back" returns the shipped wording at
 * any time, which is what makes a studio willing to try changing it.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Send, Undo2 } from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Alert,
	Button,
	ConfirmDialog,
	ErrorState,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import { useDebounced } from '../../../packages/app/useDebounced';
import { Slot } from '../../../packages/app/slot';
import { copyText } from '../../../packages/app/copy';

type Summary = {
	key: string;
	group: string;
	label: string;
	variables: string[];
};

type Template = {
	key: string;
	label?: string;
	group?: string;
	variables?: string[];
	subject?: string;
	subject_client?: string;
	greeting?: string;
	body?: string;
	closing?: string;
	button_label?: string;
	button_url?: string;
};

type Preview = { subject: string; html: string; test_sent?: boolean };

/** The parts a studio edits, in the order they are read. */
function partList() {
	return [
		{ key: 'subject' as const, label: __( 'Subject', 'cliezoo' ) },
		{
			key: 'subject_client' as const,
			label: __( 'Subject, to a client', 'cliezoo' ),
		},
		{ key: 'greeting' as const, label: __( 'Greeting', 'cliezoo' ) },
		{ key: 'body' as const, label: __( 'Body', 'cliezoo' ), lines: true },
		{ key: 'closing' as const, label: __( 'Closing', 'cliezoo' ) },
		{ key: 'button_label' as const, label: __( 'Button', 'cliezoo' ) },
		{
			key: 'button_url' as const,
			label: __( 'Where the button goes', 'cliezoo' ),
		},
	];
}

export default function EmailTemplates() {
	const queries = useQueryClient();
	const [ chosen, setChosen ] = useState( '' );
	const [ draft, setDraft ] = useState< Template | null >( null );
	const [ asClient, setAsClient ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	// Putting the wording back throws away whatever the studio wrote, and
	// there is no copy of it anywhere. That is worth one question.
	const [ putting, setPutting ] = useState( false );

	const all = useQuery< Summary[] >( {
		queryKey: [ 'email-templates' ],
		queryFn: async () =>
			( await api.get< Summary[] >( '/email-templates' ) ).data,
	} );

	const one = useQuery< Template >( {
		queryKey: [ 'email-templates', chosen ],
		queryFn: async () =>
			( await api.get< Template >( `/email-templates/${ chosen }` ) )
				.data,
		enabled: !! chosen,
	} );

	// The draft is this template's, and only this one's. Kept across a
	// change of template it was the last one's wording on the new one's
	// screen — and Save wrote it to the new one's key, so a template
	// nobody had opened came back holding another's words.
	useEffect( () => {
		setDraft( null );
	}, [ chosen ] );

	useEffect( () => {
		if ( one.data ) {
			setDraft( one.data );
		}
	}, [ one.data ] );

	// Keyed on the words rather than on the draft object: a new object
	// every render is a new key every render, which is a preview that asks
	// the server again forever and a page that never settles.
	const wording = JSON.stringify( {
		subject: draft?.subject ?? '',
		greeting: draft?.greeting ?? '',
		body: draft?.body ?? '',
		closing: draft?.closing ?? '',
	} );
	const typed = useDebounced( wording, 400 );

	const preview = useQuery< Preview >( {
		queryKey: [ 'email-templates', chosen, 'preview', asClient, typed ],
		queryFn: async () =>
			(
				await api.post< Preview >(
					`/email-templates/${ chosen }/preview`,
					{
						parts: JSON.parse( typed ),
						as_client: asClient,
					}
				)
			).data,
		enabled: !! chosen && !! draft,
	} );

	const groups = ( all.data ?? [] ).reduce< Record< string, Summary[] > >(
		( out, one_ ) => {
			( out[ one_.group ] ??= [] ).push( one_ );
			return out;
		},
		{}
	);

	const save = async () => {
		setBusy( true );
		try {
			await api.put( `/email-templates/${ chosen }`, {
				parts: draft ?? {},
			} );
			toast.success( __( 'Saved.', 'cliezoo' ) );
			queries.invalidateQueries( { queryKey: [ 'email-templates' ] } );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const restore = async () => {
		setBusy( true );
		try {
			await api.post( `/email-templates/${ chosen }/restore` );
			toast.success( __( 'Put back as it was written.', 'cliezoo' ) );
			queries.invalidateQueries( { queryKey: [ 'email-templates' ] } );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const test = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< Preview >(
				`/email-templates/${ chosen }/preview`,
				{
					parts: draft ?? {},
					as_client: asClient,
					send_test: true,
				}
			);
			toast.success(
				data.test_sent
					? __( 'Sent to your own address.', 'cliezoo' )
					: __(
							'It could not be sent. Check Settings → Email.',
							'cliezoo'
						)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	if ( all.isPending ) {
		return <Skeleton className="h-64" />;
	}

	if ( all.isError ) {
		return (
			<ErrorState error={ all.error } onRetry={ () => all.refetch() } />
		);
	}

	return (
		<div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
			<nav aria-label={ __( 'Emails', 'cliezoo' ) } className="space-y-4">
				{ Object.entries( groups ).map( ( [ group, items ] ) => (
					<div key={ group }>
						<h3 className="px-2 text-xs font-semibold uppercase tracking-wide text-muted">
							{ group }
						</h3>
						<ul className="mt-1">
							{ items.map( ( item ) => (
								<li key={ item.key }>
									<button
										type="button"
										aria-current={
											chosen === item.key
												? 'true'
												: undefined
										}
										className="flex min-h-9 w-full items-center rounded-md px-2 py-1.5 text-start text-sm text-fg hover:bg-sunken aria-[current]:bg-sunken aria-[current]:font-medium"
										onClick={ () => setChosen( item.key ) }
									>
										{ item.label }
									</button>
								</li>
							) ) }
						</ul>
					</div>
				) ) }
			</nav>

			{ ! chosen && (
				<p className="text-sm text-muted">
					{ __(
						'Choose an email to see how it reads and change what it says.',
						'cliezoo'
					) }
				</p>
			) }

			{ !! chosen && (
				<div className="grid gap-4 xl:grid-cols-2">
					<section
						aria-label={ __( 'What it says', 'cliezoo' ) }
						className="space-y-3"
					>
						{ one.isPending && <Skeleton className="h-64" /> }

						{ !! draft && (
							<>
								<Slot
									name="email.template-editor"
									variant="row"
									props={ { template: draft.key } }
								/>
								{ !! draft.variables?.length && (
									<Alert tone="info">
										<p>
											{ __(
												'These stand in for the real thing when it is sent:',
												'cliezoo'
											) }
										</p>
										<p className="mt-1 flex flex-wrap gap-1">
											{ draft.variables.map( ( name ) => (
												<button
													key={ name }
													type="button"
													onClick={ () =>
														copyText(
															`{{${ name }}}`,
															__(
																'Copied. Paste it where it belongs.',
																'cliezoo'
															)
														)
													}
													className="rounded bg-sunken px-1.5 py-0.5 font-mono text-xs text-fg hover:bg-accent-subtle"
												>
													{ `{{${ name }}}` }
												</button>
											) ) }
										</p>
									</Alert>
								) }

								{ partList().map( ( part ) => (
									<TextField
										key={ String( part.key ) }
										label={ part.label }
										value={
											( draft[ part.key ] as string ) ??
											''
										}
										description={
											'subject_client' === part.key
												? __(
														'Left empty, a client gets the same subject as the team.',
														'cliezoo'
													)
												: undefined
										}
										onChange={ ( value: string ) =>
											setDraft( {
												...draft,
												[ part.key ]: value,
											} )
										}
									/>
								) ) }

								<div className="flex flex-wrap gap-2">
									<Button
										variant="primary"
										isDisabled={ busy }
										onPress={ save }
									>
										{ __( 'Save', 'cliezoo' ) }
									</Button>
									<Button
										isDisabled={ busy }
										icon={
											<Send
												size={ 14 }
												aria-hidden="true"
											/>
										}
										onPress={ test }
									>
										{ __( 'Send me one', 'cliezoo' ) }
									</Button>
									<Button
										variant="ghost"
										isDisabled={ busy }
										icon={
											<Undo2
												size={ 14 }
												aria-hidden="true"
											/>
										}
										onPress={ () => setPutting( true ) }
									>
										{ __( 'Put it back', 'cliezoo' ) }
									</Button>
								</div>
							</>
						) }
					</section>

					<section
						aria-label={ __( 'How it reads', 'cliezoo' ) }
						className="space-y-2"
					>
						<div className="flex flex-wrap items-center gap-2">
							<h3 className="text-sm font-semibold">
								{ __( 'How it reads', 'cliezoo' ) }
							</h3>
							<Switch
								isSelected={ asClient }
								onChange={ setAsClient }
							>
								{ __( 'As a client sees it', 'cliezoo' ) }
							</Switch>
						</div>
						{ preview.isPending && <Skeleton className="h-64" /> }
						{ !! preview.data && (
							<div className="overflow-hidden rounded-lg border border-line">
								<p className="border-b border-line bg-sunken px-3 py-2 text-sm font-medium">
									{ preview.data.subject }
								</p>
								{ /* The server's own rendering, in a frame of
								     its own: an email's HTML is written for
								     mail clients and would fight with the
								     app's styles if it were let out. */ }
								<iframe
									title={ __(
										'The email as it will arrive',
										'cliezoo'
									) }
									className="h-[32rem] w-full bg-white"
									sandbox=""
									srcDoc={ preview.data.html }
								/>
							</div>
						) }
					</section>
				</div>
			) }

			<ConfirmDialog
				isOpen={ putting }
				onOpenChange={ ( open: boolean ) =>
					! open && setPutting( false )
				}
				title={ __( 'Put the shipped wording back?', 'cliezoo' ) }
				description={ __(
					'Whatever this email has been changed to will be gone. There is no copy of it anywhere else.',
					'cliezoo'
				) }
				confirmLabel={ __( 'Put it back', 'cliezoo' ) }
				onConfirm={ () => {
					setPutting( false );
					restore();
				} }
			/>
		</div>
	);
}
