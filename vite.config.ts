import path from 'path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [vue()],
    publicDir: 'resources/static',
    build: {
        manifest: true,
        outDir: 'public/build',
        rollupOptions: {
            input: 'resources/scripts/main.js',
        },
    },
    server: {
        watch: {
            ignored: ['**/.env/**'],
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources'),
            'vue-i18n': 'vue-i18n/dist/vue-i18n.cjs.js',
        },
    },
})
