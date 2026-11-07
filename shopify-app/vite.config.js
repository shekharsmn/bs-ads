import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/shopify/',
  build: { outDir: '../backend/public/shopify', emptyOutDir: true, manifest: 'manifest.json', rollupOptions: { input: 'src/main.jsx' } },
  test: { environment: 'jsdom', setupFiles: './src/test/setup.js', clearMocks: true },
  server: { cors: { origin: ['http://127.0.0.1:8000', 'http://localhost:8000'] } },
})
