import { defineConfig } from "vite";
import fs from 'fs';
import { resolve } from 'path'

const inputFiles = {};

// Dynamically create entry points for each .js in src/Assets/js/
fs.readdirSync('./src/Assets/js/').forEach(file => {
    if (file.endsWith('.js')) {
        // Clé sans extension : admin => ./src/Assets/js/admin.js
        const name = file.replace(/\.js$/, '');
        inputFiles[name] = `./src/Assets/js/${file}`;
    }
});

export default defineConfig({
    build: {
        outDir: resolve(__dirname, "public/dist/"),
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
    publicDir: false,


});