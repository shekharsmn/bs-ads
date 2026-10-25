import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    chunkSizeWarningLimit: 350,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('recharts')) return 'charts'
          if (id.includes('react-markdown')) return 'markdown'
          if (id.includes('@tanstack/react-query')) return 'query'
          if (id.includes('react-router')) return 'router'
          if (id.includes('node_modules/react')) return 'react'
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.js',
    include: ['src/**/*.test.{js,jsx}'],
    clearMocks: true,
  },
  server: {
    port: 5173,
    proxy: {
      // Keep the proxy on the same address Laravel's local server binds to.
      // On Windows, `localhost` can resolve to ::1 and leave requests pending
      // when `php artisan serve` is listening only on 127.0.0.1.
      '/sanctum': 'http://127.0.0.1:8000',
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
