/**
 * Keyboard shortcuts sheet (press ?): what the app listens for, including
 * the shortcuts modules registered with their commands.
 */
import { __ } from '@wordpress/i18n';
import { AriaDialog as Dialog, Kbd, Modal, ModalOverlay } from '@cliezoo/ui';
import { registry } from '../packages/app/registry';

const GROUPS = (): Array< [ string, Array< [ string, string ] > ] > => [
	[
		__( 'Anywhere', 'cliezoo' ),
		[
			[ 'Ctrl K', __( 'Search or run a command', 'cliezoo' ) ],
			[ '/', __( 'Search', 'cliezoo' ) ],
			[ 'C', __( 'Create something new', 'cliezoo' ) ],
			[ '?', __( 'Show this sheet', 'cliezoo' ) ],
			[ 'Esc', __( 'Close what is open', 'cliezoo' ) ],
		],
	],
	[
		__( 'In lists', 'cliezoo' ),
		[
			[ '↑ ↓', __( 'Move between rows', 'cliezoo' ) ],
			[ '↵', __( 'Open the row', 'cliezoo' ) ],
			[ 'Space', __( 'Select the row', 'cliezoo' ) ],
		],
	],
];

export default function ShortcutsSheet( { close }: { close: () => void } ) {
	const custom = registry.commands
		.filter( ( command ) => !! command.shortcut )
		.map(
			( command ) =>
				[ command.shortcut as string, command.label ] as [
					string,
					string,
				]
		);
	const groups = custom.length
		? [
				...GROUPS(),
				[ __( 'Commands', 'cliezoo' ), custom ] as [
					string,
					Array< [ string, string ] >,
				],
			]
		: GROUPS();

	return (
		<ModalOverlay
			isOpen
			onOpenChange={ close }
			isDismissable
			className="fixed inset-0 z-50 flex items-start justify-center bg-overlay p-3 pt-[12vh]"
		>
			<Modal className="w-full max-w-lg overflow-hidden rounded-xl border border-line bg-raised text-fg shadow-lg">
				<Dialog
					aria-label={ __( 'Keyboard shortcuts', 'cliezoo' ) }
					className="outline-none"
				>
					<h2 className="border-b border-line px-5 py-3 text-sm font-semibold">
						{ __( 'Keyboard shortcuts', 'cliezoo' ) }
					</h2>
					<div className="max-h-[min(60vh,32rem)] space-y-5 overflow-auto px-5 py-4">
						{ groups.map( ( [ title, rows ] ) => (
							<section key={ title }>
								<h3 className="mb-2 text-[0.7rem] font-semibold uppercase tracking-wider text-subtle">
									{ title }
								</h3>
								<dl className="space-y-1.5 text-sm">
									{ rows.map( ( [ keys, what ] ) => (
										<div
											key={ `${ keys }:${ what }` }
											className="flex items-center gap-3"
										>
											<dt className="flex shrink-0 gap-1">
												{ keys
													.split( ' ' )
													.map( ( key ) => (
														<Kbd key={ key }>
															{ key }
														</Kbd>
													) ) }
											</dt>
											<dd className="min-w-0 flex-1 truncate text-muted">
												{ what }
											</dd>
										</div>
									) ) }
								</dl>
							</section>
						) ) }
					</div>
				</Dialog>
			</Modal>
		</ModalOverlay>
	);
}
