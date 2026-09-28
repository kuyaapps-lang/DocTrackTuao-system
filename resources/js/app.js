import '../css/app.css';

import './bootstrap';

import { createApp } from 'vue';

import App from './App.vue';
import router from './router';
import { initializeTheme } from './lib/theme';

initializeTheme();

createApp(App)
    .use(router)
    .mount('#app');
