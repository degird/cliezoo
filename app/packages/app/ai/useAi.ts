/**
 * What AI can do for this person here: which helpers the workspace allows,
 * whether anything can answer, and where it is switched off.
 */
import { api, can, useQuery, useUser } from '@cliezoo/data';

export interface AiTask {
	id: string;
	label: string;
	description: string;
	module: string;
	writes: boolean;
	options: string[];
	requires: string[];
}

export interface AiState {
	enabled: boolean;
	ready: boolean;
	tasks: AiTask[];
	off: string[];
	off_in: string[];
	runnable: string[];
	providers: Array< { id: string; available: boolean } >;
	tones: string[];
	admin: boolean;
	/** Whether what an AI draft puts in says it was written by AI. */
	label_ai?: boolean;
}

export interface AiRun {
	run: string;
	text: string;
	ai: true;
	/** Whether it used its whole allowance and may have stopped short. */
	truncated?: boolean;
}

/**
 * The part of the workspace a record belongs to, for "AI is off here".
 *
 * @param type Entity name.
 */
export function placeOf( type: string ): string {
	const places: Record< string, string > = {
		tasks: 'boards',
		board_lists: 'boards',
		folders: 'files',
		comments: 'conversations',
	};
	return places[ type ] ?? type;
}

/**
 * The helpers, for a part of the workspace.
 *
 * @param place Module id.
 */
export function useAi( place: string ) {
	const user = useUser();
	// Staff with AI in their role; the server says the rest.
	const allowed = !! user && ! user.client && can.toggle( user, 'ai_use' );
	const state = useQuery( {
		queryKey: [ 'ai', 'tasks' ],
		enabled: allowed,
		staleTime: 5 * 60_000,
		queryFn: async () => ( await api.get< AiState >( '/ai/tasks' ) ).data,
	} );
	const data = state.data;
	const off = !! data && data.off_in.includes( place );
	// Switched off on purpose, the button goes; never set up, an
	// administrator is told how.
	// The server sends the list to anyone who may set AI up, which is the
	// same test as `admin` below. It used to send it to WordPress
	// administrators alone, so a role with ai_manage over a working
	// workspace read an empty list as "nothing is set up".
	const unset = !! data && ! data.providers.some( ( one ) => one.available );
	const visible = !! data && ( data.enabled || ( data.admin && unset ) );
	return {
		/** Whether to show the helpers at all. */
		shown: allowed && visible && ! off,
		/** Whether they can run, or only say how to set AI up. */
		ready: !! data?.ready,
		admin: !! data?.admin,
		/** Whether to label what a draft puts in as written by AI. */
		label: !! data?.label_ai,
		tones: data?.tones ?? [],
		task: ( id: string ) =>
			data?.runnable.includes( id )
				? ( data.tasks.find( ( one ) => one.id === id ) ?? null )
				: null,
	};
}

/**
 * Says what became of a draft. It is only a record for the log, so a
 * failure is not worth interrupting anybody over.
 *
 * @param run     The run.
 * @param outcome What the person did.
 */
export function tell(
	run: string,
	outcome: 'accepted' | 'edited' | 'discarded'
): void {
	if ( run ) {
		api.post( `/ai/runs/${ run }/outcome`, { outcome } ).catch(
			() => undefined
		);
	}
}
