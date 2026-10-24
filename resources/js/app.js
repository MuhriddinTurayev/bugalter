import Alpine from 'alpinejs';

import { registerAiChat } from './ai-chat';
import { registerDashboard } from './dashboard';
import { registerDocumentForm } from './document-form';

window.Alpine = Alpine;

registerDashboard(Alpine);
registerDocumentForm(Alpine);
registerAiChat(Alpine);

Alpine.start();
