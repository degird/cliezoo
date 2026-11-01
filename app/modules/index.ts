/**
 * Every feature module's registrations, in one place.
 *
 * A module registers its routes, navigation, tabs and commands here rather
 * than the shell knowing about it.
 */
import { registerClients } from './clients';
import { registerContacts } from './contacts';
import { registerDashboard } from './dashboard';
import { registerFiles } from './files';
import { registerLeads } from './leads';

export function registerModules(): void {
	registerLeads();
	registerClients();
	registerContacts();
	registerFiles();
	registerDashboard();
}
