import { defineConfig } from "vite";
import fs from 'fs';
import { resolve } from 'path'

const inputFiles = {};

// Dynamically create entry points for each .js in src/assets/js/
fs.readdirSync('./src/assets/js/').forEach(file => {
    if (file.endsWith('.js')) {
        // Clé sans extension : admin => ./src/assets/js/admin.js
        const name = file.replace(/\.js$/, '');
        inputFiles[name] = `./src/assets/js/${file}`;
    }
});

export default defineConfig({
    build: {
        outDir: resolve(__dirname, "public/dist/js/"),
        emptyOutDir: true,
        manifest: true, // <= ajoute ceci

        rollupOptions: {
            input: inputFiles,
            output: {
                entryFileNames: "[name].min.js",
                chunkFileNames: "[name].[hash].min.js"

            }
        }
    },
    css: {
        preprocessorOptions: {
            scss: {
                silenceDeprecations: [
                    'import',
                    'mixed-decls',
                    'color-functions',
                    'global-builtin',
                ],
            },
        },
    },
    publicDir: false
});