import { defineConfig } from 'vite';
import os from 'node:os';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

const networkInterfaces = os.networkInterfaces();
const wifiAddress = Object.entries(networkInterfaces)
    .find(([name]) => /wi-?fi|wlan/i.test(name))?.[1]
    ?.find(address => address.family === 'IPv4' && !address.internal)?.address;
const fallbackAddress = Object.values(networkInterfaces)
    .flat()
    .find(address => address.family === 'IPv4' && !address.internal)?.address;
const lanHost = process.env.VITE_LAN_HOST || wifiAddress || fallbackAddress || os.hostname().toLowerCase();
const localOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://[::1]',
    `http://${lanHost}`,
    `http://${os.hostname().toLowerCase()}`,
];

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/theme.js',
                'resources/js/app.js',
            ],
            refresh: true,
        }),

        vue(),

        tailwindcss(),
    ],

    server: {
        // Listen on every adapter, but publish the computer name for Laravel's
        // Vite hot file so other LAN devices do not depend on a changing IP.
        host: true,
        allowedHosts: [os.hostname(), os.hostname().toLowerCase()],
        cors: {
            origin: localOrigins,
        },
        hmr: {
            host: lanHost,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
