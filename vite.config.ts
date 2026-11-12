import path from 'path'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scripts/main.js'],
            publicDirectory: 'public',
            buildDirectory: 'build',
        }),
        vue(),
        {
            name: 'crater-static-assets',
            enforce: 'post',
            config: () => ({
                // The official Laravel plugin disables Vite's public directory.
                publicDir: 'resources/static',
            }),
        },
    ],
    server: {
        watch: {
            ignored: ['**/.env/**'],
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources'),
            'vue-i18n': 'vue-i18n/dist/vue-i18n.cjs.js',
        }
    }
})
