import { defineConfig } from "vite";
import fs from 'fs';

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
        outDir: "./public/dist/js/",
        emptyOutDir: true,
        rollupOptions: {
            input: inputFiles,
            output: {
                entryFileNames: "[name].min.js",
                chunkFileNames: "[name].[hash].min.js"
            }
        }
    },
    publicDir: false
});