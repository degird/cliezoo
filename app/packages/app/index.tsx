/**
 * The cliezoo app package: the app shell, router and extension API.
 * Exposed as window.cliezoo.app (handle cliezoo-app):
 *
 *     const { registerRoutes, registerNavItems, registerSlotFill } = window.cliezoo.app;
 */
import { lazy } from 'react';
import { registerCore } from '../../shell/register';
import { registerModules } from '../../modules';
import { start } from './mount';

export {
	registry,
	registerRoutes,
	registerNavItems,
	registerSlotFill,
	registerCommand,
	registerFieldType,
	registerWidget,
	registerAiAction,
	registerAiStreamer,
	getAiStreamer,
	aiActionsFor,
	applies,
	registerRecordLink,
	registerSettingsSection,
	registerViewSource,
	recordPath,
} from './registry';
export type {
	NavItem,
	NavGroup,
	RouteDef,
	SlotFill,
	Command,
	FieldTypeDef,
	WidgetDef,
	AiActionDef,
	AiStreamer,
	Audience,
	SettingsSection,
	SettingsGroup,
	ViewSource,
} from './registry';
export { Slot, useSlotHasFills, useRegistry } from './slot';
export { getRouter } from './mount';
export { PageHeader, Page } from '../../shell/Page';
export {
	Link as RouterLink,
	Outlet,
	useNavigate,
	useParams,
	useSearch,
	useLocation,
	useRouter,
	useMatchRoute,
} from '@tanstack/react-router';

registerCore();
registerModules();
start();

/**
 * The universal list, loaded on first use (its own chunk).
 */
export const EntityList = lazy(
	() => import( /* webpackChunkName: "list" */ './list/EntityList' )
);
export type { EntityListProps } from './list/EntityList';

/**
 * The list's filter builder, for an add-on that filters records the way a
 * list does (a custom report's filter). In the list's own chunk.
 */
export const FilterBuilder = lazy( () =>
	import( /* webpackChunkName: "list" */ './list/FilterBuilder' ).then(
		( m ) => ( { default: m.FilterBuilder } )
	)
);
export type {
	EntityMeta,
	FieldMeta,
	FilterGroup,
	ListConfig,
} from './list/meta';

/**
 * Activity timeline for a record, client or project (its own chunk).
 */
export const ActivityFeed = lazy(
	() => import( /* webpackChunkName: "activity" */ './activity/ActivityFeed' )
);
export type { ActivityFeedProps } from './activity/ActivityFeed';

/**
 * "Who can open this" for any record, loaded when it is first opened
 * (its own chunk).
 */
export const ShareDialog = lazy(
	() => import( /* webpackChunkName: "access" */ './access/ShareDialog' )
);

/**
 * The AI draft dialog: a draft written about a record, or from a text, for
 * the person to read, change, copy or put in (its own chunk, shared with
 * the AI menu).
 */
export const AiDraft = lazy(
	() => import( /* webpackChunkName: "ai" */ './ai/AiDraft' )
);
export type { AiJob } from './ai/AiDraft';

/*
 * What AI can do for this person in a part of the workspace, for an add-on
 * that offers AI helpers of its own. Small, and loaded with the app.
 */
export { useAi, placeOf, tell } from './ai/useAi';
export type { AiState, AiTask, AiRun } from './ai/useAi';
