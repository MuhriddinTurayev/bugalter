import Alpine from 'alpinejs';

import { registerDashboard } from './dashboard';
import { registerDocumentForm } from './document-form';

window.Alpine = Alpine;

registerDashboard(Alpine);
registerDocumentForm(Alpine);

Alpine.start();
