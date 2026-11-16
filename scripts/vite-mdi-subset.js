// Plugin de Vite: regenera el subconjunto de íconos MDI (scripts/mdi-subset.mjs) al arrancar `vite`
// o `vite build` y, en desarrollo, cuando cambia un archivo con íconos. Así un `mdi-*` nuevo no sale
// como cuadro vacío ni rompe IconSubsetTest.
//   // vite.config.js
//   import mdiSubset from './scripts/vite-mdi-subset.js';
//   plugins: [mdiSubset(), …]
import { execFileSync } from 'node:child_process';

const WATCHED = /\.(vue|js|mjs|php|css)$/;

export default function mdiSubset() {
    let timer = null;
    const run = () => {
        try {
            execFileSync(process.execPath, ['scripts/mdi-subset.mjs'], { stdio: 'ignore' });
        } catch {
            // Si falla (sin @mdi/font), se conserva el subconjunto anterior.
        }
    };
    return {
        name: 'crm-mdi-subset',
        buildStart: run,
        handleHotUpdate({ file }) {
            if (!WATCHED.test(file) || /resources[\/]css[\/]mdi\.css$/.test(file)) return;
            clearTimeout(timer);
            timer = setTimeout(run, 800);
        },
    };
}
